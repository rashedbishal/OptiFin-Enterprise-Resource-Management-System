<?php

namespace App\Actions\Tenant;

use App\Actions\Platform\CreateCallPermissionRequestTemplate;
use App\Enums\LeadCallPermissionStatus;
use App\Models\Lead;
use App\Models\LeadCallPermission;
use App\Models\LeadSource;
use App\Support\RequestLeadCallPermissionResult;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WhatsApp Outbound Calling — sends a `call_permission_request` template to
 * the consumer, but ONLY when explicitly triggered by the agent (never
 * automatically because a "Call" click found permission missing — the UI
 * must offer a distinct "Request permission" action) and only when Meta's
 * OWN live response says it is currently allowed.
 *
 * Never sends blind: re-runs CheckLeadCallPermission first and reads
 * `actions[].send_call_permission_request.can_perform_action` — Meta's own
 * `actions[].limits[]` is the sole gate, never a locally-remembered
 * "already requested recently" guess, and never a hardcoded rate limit.
 *
 * Uses the standard `POST /{phone_number_id}/messages` endpoint (not a
 * dedicated calling endpoint — none exists for this, per the verified
 * contract), on `calling.graph_version` (unchanged, same version every
 * existing inbound Calling API action already uses) rather than the new
 * outbound-only `calling.outbound_graph_version`, since this is not the
 * `/calls` connect action.
 *
 * The approved template's body is STATIC — no {{1}}/{{2}} variables, no
 * personalization. This was originally built with two variables (lead
 * name, lead inquiry), but a real, live Meta content review REJECTED that
 * version (confirmed 2026-10-14) while the identical-except-static wording
 * was APPROVED on the very first submission — Meta's review for this
 * specific, specialized `call_permission_request` component type appears
 * to reject a personalized body, even though the Cloud API's generic
 * template-parameter mechanism would otherwise allow it. Since there is no
 * variable to fill with the lead's own inquiry, a lead with no message
 * text is no longer refused here either (a deliberate product decision,
 * not just a technical consequence — see CreateCallPermissionRequestTemplate::
 * saveCallPermissionTemplate() for the matching body-validation flip from
 * "must contain {{1}}/{{2}}" to "must contain neither").
 */
class RequestLeadCallPermission
{
    public function __construct(private readonly CheckLeadCallPermission $check) {}

    public function handle(Lead $lead, LeadSource $source): RequestLeadCallPermissionResult
    {
        // Captured before either network call (the live check, then the
        // template send) — same "observation carries the time its request
        // STARTED" discipline CheckLeadCallPermission's own docblock
        // documents, so a call_permission_reply webhook that lands during
        // either round-trip is correctly recognized as newer than this
        // request's own eventual write, never the reverse.
        $observedAt = Carbon::now();

        $check = $this->check->handle($lead, $source);

        if (! $check->successful) {
            return RequestLeadCallPermissionResult::refused('permission_check_failed', $check);
        }

        $action = $this->findAction($check->data['actions'] ?? [], 'send_call_permission_request');

        if ($action === null || $action['can_perform_action'] !== true) {
            return RequestLeadCallPermissionResult::refused('not_allowed', $check);
        }

        $sent = $this->sendTemplate($source, (string) $lead->phone);

        if (! $sent) {
            return RequestLeadCallPermissionResult::refused('meta_rejected', $check);
        }

        $this->markRequested($lead, $source, $observedAt);

        return RequestLeadCallPermissionResult::sent();
    }

    /**
     * @param  array<int, array{action_name: ?string, can_perform_action: bool, limits: array<int, mixed>}>  $actions
     * @return array{action_name: ?string, can_perform_action: bool, limits: array<int, mixed>}|null
     */
    private function findAction(array $actions, string $name): ?array
    {
        foreach ($actions as $action) {
            if (($action['action_name'] ?? null) === $name) {
                return $action;
            }
        }

        return null;
    }

    /**
     * Sends the approved call_permission_request template. The
     * `call_permission_request` component itself stays exactly
     * `{"type": "call_permission_request"}` — Meta's public documentation
     * does not confirm any further field inside it, so none is added. No
     * `body` component at all — the approved template's body is static
     * (see this class's own docblock for why), and sending parameters for
     * a template that defines none would itself be a mismatch Meta
     * rejects, the same class of error a too-few/too-many count would
     * cause for any other template.
     */
    private function sendTemplate(LeadSource $source, string $to): bool
    {
        $config = is_array($source->config) ? $source->config : [];
        $phoneNumberId = $config['phone_number_id'] ?? null;
        $accessToken = $config['access_token'] ?? null;

        if (! is_string($phoneNumberId) || $phoneNumberId === '' || ! is_string($accessToken) || $accessToken === '') {
            return false;
        }

        $template = config('calling.call_permission_request_template');
        // Per-source, not the global config default — see
        // CreateCallPermissionRequestTemplate::TEMPLATE_NAME_CONFIG_KEY's
        // own docblock: this source may have had its template
        // delete-and-recreated under a bumped name since the global
        // default was last changed, and sending must always target
        // whichever name is CURRENTLY approved for this source, not a
        // stale one.
        $templateName = CreateCallPermissionRequestTemplate::resolveTemplateName($source);

        try {
            $response = Http::withToken($accessToken)
                ->timeout((int) config('calling.api_timeout_seconds'))
                ->connectTimeout((int) config('calling.api_connect_timeout_seconds'))
                ->asJson()
                ->post(sprintf('https://graph.facebook.com/%s/%s/messages', config('calling.graph_version'), rawurlencode($phoneNumberId)), [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $to,
                    'type' => 'template',
                    'template' => [
                        'name' => $templateName,
                        'language' => ['code' => $template['language']],
                        'components' => [
                            ['type' => 'call_permission_request'],
                        ],
                    ],
                ]);
        } catch (Throwable $exception) {
            Log::channel('whatsapp')->warning('WA CALL PERMISSION REQUEST FAILED', [
                'tenant_id' => $source->tenant_id,
                'lead_source_id' => $source->id,
                'exception' => $exception::class,
            ]);

            return false;
        }

        if (! $response->successful()) {
            Log::channel('whatsapp')->warning('WA CALL PERMISSION REQUEST REJECTED', [
                'tenant_id' => $source->tenant_id,
                'lead_source_id' => $source->id,
                'http_status' => $response->status(),
                'meta_code' => $response->json('error.code'),
            ]);

            return false;
        }

        return true;
    }

    /**
     * "Requested" is local-only bookkeeping (see LeadCallPermissionStatus's
     * own docblock) — never itself a Meta observation, so overwriting it is
     * only safe when nothing more authoritative has landed since $observedAt
     * (a call_permission_reply webhook, or a concurrent CheckLeadCallPermission
     * call, either of which is a REAL observation and must never be
     * regressed back to "Requested"). Mirrors CheckLeadCallPermission::
     * writeObservation()/RecordCallPermissionReply::handle()'s exact
     * read -> create-with-recovery -> guarded-update shape — the same
     * established, already-hardened pattern for this table, rather than
     * Laravel's plain updateOrCreate() (which recovers from neither a
     * concurrent-create race nor a stale write).
     */
    private function markRequested(Lead $lead, LeadSource $source, Carbon $observedAt): void
    {
        $existing = LeadCallPermission::query()->where('tenant_id', $lead->tenant_id)->where('lead_id', $lead->id)->first();

        if ($existing === null) {
            try {
                LeadCallPermission::query()->create([
                    'tenant_id' => $lead->tenant_id,
                    'lead_id' => $lead->id,
                    'lead_source_id' => $source->id,
                    'status' => LeadCallPermissionStatus::Requested,
                    'last_requested_at' => $observedAt,
                ]);

                return;
            } catch (UniqueConstraintViolationException) {
                // Lost the race: a concurrent CheckLeadCallPermission check or
                // call_permission_reply webhook created the row first. Re-read
                // it and fall through to the same guarded-update path below.
                $existing = LeadCallPermission::query()->where('tenant_id', $lead->tenant_id)->where('lead_id', $lead->id)->first();

                if ($existing === null) {
                    return;
                }
            }
        }

        LeadCallPermission::query()
            ->whereKey($existing->id)
            ->where(fn ($query) => $query
                ->whereNull('last_synced_at')
                ->orWhere('last_synced_at', '<=', $observedAt))
            ->update([
                'lead_source_id' => $source->id,
                'status' => LeadCallPermissionStatus::Requested,
                'last_requested_at' => $observedAt,
                'updated_at' => Carbon::now(),
            ]);
    }
}
