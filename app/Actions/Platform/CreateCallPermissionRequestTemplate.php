<?php

namespace App\Actions\Platform;

use App\Enums\LeadSourceType;
use App\Models\LeadSource;
use App\Models\PlatformUser;
use App\Models\Tenant;
use App\Support\MessengerTemplateActionResult;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Creates the one, specific WhatsApp message template App\Actions\Tenant\
 * RequestLeadCallPermission depends on existing and being Approved on a
 * tenant's own WhatsApp Business Account. Deliberately narrow, NOT general
 * WhatsApp template management: this app otherwise never creates WhatsApp
 * templates via Meta's API at all (see WhatsAppMessageTemplate's own
 * docblock — most stored WhatsApp tokens lack the
 * whatsapp_business_management scope this needs), just the one template
 * Calling cannot function without.
 *
 * language is NOT admin-provided — it comes from config('calling.
 * call_permission_request_template')['language'] and must stay exactly
 * that, since RequestLeadCallPermission::sendTemplate() hardcodes it when
 * actually sending the approved template later. The NAME, however, is
 * per-source, not a single global config value — see
 * TEMPLATE_NAME_CONFIG_KEY's own docblock for why, and
 * DeleteCallPermissionRequestTemplate for the other half of this (it's
 * the one that actually bumps this key after a successful delete).
 *
 * bodyText/footerText ARE admin-provided (see Platform\Clients\
 * LeadSources::saveCallPermissionTemplate()'s own validation) — but MUST
 * be STATIC, containing neither {{1}} nor {{2}} nor any other {{n}}
 * placeholder. This was originally built the opposite way (body required
 * exactly {{1}}/{{2}} for the lead's name/inquiry), but a real, live Meta
 * content review REJECTED that version (confirmed 2026-10-14) while the
 * byte-for-byte-identical-except-static wording was APPROVED on its very
 * first submission — Meta's review for this specific, specialized
 * `call_permission_request` component type appears to reject a
 * personalized body outright, even though the Cloud API's generic
 * template-parameter mechanism would otherwise allow one. See
 * RequestLeadCallPermission::sendTemplate()'s own docblock for the other
 * half of this: it no longer sends a `body` component at all.
 *
 * Confirmed live in production (2026-10-14): a missing/unapproved
 * template is exactly what makes RequestLeadCallPermission fail with
 * Meta error 132001, and since this template is per-WABA, every tenant
 * needs their own copy actually created — nothing does this
 * automatically, so Platform Admin gets a dedicated form for it on the
 * Lead Sources page.
 *
 * Unlike Messenger Utility templates (instant approve/reject at the SAME
 * fidelity — see CreateMessengerMessageTemplate), WhatsApp template
 * review is normally asynchronous (PENDING, then approved/rejected hours
 * later) — but Meta can ALSO reject instantly, in the very same create
 * response (confirmed live: the personalized-body attempt above came
 * back `status: REJECTED` synchronously). This action checks for that
 * explicitly and treats it as a failure, the same as Messenger's own
 * REJECTED handling — reporting a REJECTED status as "success" (the
 * original bug here, caught the same day) is actively misleading, not
 * just inaccurate. A later, asynchronous rejection (hours after a PENDING
 * response) is NOT caught here — nothing in this app tracks WhatsApp
 * template approval status after the fact, matching
 * WhatsAppMessageTemplate's existing "self-registered, never
 * Meta-tracked" philosophy. Only the NAME currently in use is tracked
 * (see TEMPLATE_NAME_CONFIG_KEY), and only because sending/deleting later
 * both need to agree on it.
 */
class CreateCallPermissionRequestTemplate
{
    /**
     * Where this source's own current template name lives — inside
     * LeadSource.config, same established pattern as WebsiteForm's
     * auto-generated api_key (LeadSources::save()): system-managed state,
     * never a configFields() entry an admin types into directly.
     *
     * Per-source, not a single global config value, because Meta's
     * ~30-day post-delete name lock (confirmed live, 2026-10-14 — see
     * DeleteCallPermissionRequestTemplate's own docblock) is scoped to
     * one WABA. If the name lived in one shared config value instead,
     * bumping it after ANY tenant's delete would silently break every
     * OTHER tenant whose own template is still correctly registered
     * under the old name on THEIR OWN WhatsApp Business Account —
     * RequestLeadCallPermission::sendTemplate() would start looking for
     * a template that was never created there.
     *
     * Falls back to config('calling.call_permission_request_template')
     * ['name'] when absent (a source that has never had Create or Delete
     * called on it yet) — see resolveTemplateName() below, shared with
     * DeleteCallPermissionRequestTemplate and RequestLeadCallPermission
     * so all three always agree on the same effective name.
     */
    public const string TEMPLATE_NAME_CONFIG_KEY = 'call_permission_template_name';

    public function handle(PlatformUser $actor, Tenant $tenant, string $leadSourceId, string $bodyText, string $footerText): MessengerTemplateActionResult
    {
        Gate::forUser($actor)->authorize('update', $tenant);

        $source = $tenant->leadSources()->findOrFail($leadSourceId);

        if ($source->type !== LeadSourceType::Whatsapp) {
            return new MessengerTemplateActionResult('danger', __('This template can only be created for WhatsApp sources.'));
        }

        $config = is_array($source->config) ? $source->config : [];
        $wabaId = $config['whatsapp_business_account_id'] ?? null;
        $accessToken = $config['access_token'] ?? null;

        if (! is_string($wabaId) || $wabaId === '' || ! is_string($accessToken) || $accessToken === '') {
            return new MessengerTemplateActionResult('danger', __('Add the WhatsApp Business Account ID and Access Token to this source before creating this template.'));
        }

        $template = config('calling.call_permission_request_template');
        $name = self::resolveTemplateName($source);

        $response = Http::timeout(15)->post("https://graph.facebook.com/{$this->graphVersion()}/{$wabaId}/message_templates", [
            'access_token' => $accessToken,
            'name' => $name,
            'language' => $template['language'],
            'category' => 'UTILITY',
            'components' => [
                ['type' => 'BODY', 'text' => $bodyText],
                ['type' => 'FOOTER', 'text' => $footerText],
                ['type' => 'call_permission_request'],
            ],
        ]);

        if (! $response->successful()) {
            $this->log($actor, $source, 'meta_rejected', ['error' => $response->json('error')]);

            return new MessengerTemplateActionResult('danger', $response->json('error.error_user_msg') ?? $response->json('error.message') ?? __('Meta rejected this template.'));
        }

        // A 2xx response only means Meta accepted the SUBMISSION — it can
        // still report an immediate, synchronous `status: REJECTED` in
        // this same response (confirmed live, 2026-10-14; see this
        // class's own docblock). Treat that as a failure, not success —
        // same reasoning and shape as CreateMessengerMessageTemplate's own
        // REJECTED handling. Also bump this source's name here, same as a
        // real Delete does: the rejected object still occupies the name
        // Meta's side, so the next attempt needs a fresh one regardless of
        // whether the admin also clicks Delete first.
        if ($response->json('status') === 'REJECTED') {
            $metaId = (string) $response->json('id');
            $reason = $this->fetchRejectedReason($wabaId, $accessToken, $metaId);

            $this->log($actor, $source, 'meta_content_rejected', ['name' => $name, 'meta_id' => $metaId, 'rejected_reason' => $reason]);

            $this->deleteRejectedTemplate($wabaId, $accessToken, $name);

            $nextName = self::nextTemplateName($name);
            $source->update(['config' => array_merge($config, [self::TEMPLATE_NAME_CONFIG_KEY => $nextName])]);

            return new MessengerTemplateActionResult('danger', __(
                'Meta rejected this template\'s content (:reason) — it was never approved. The next template you create for this source will automatically use a new name (":next").',
                ['reason' => $reason ?? __('no reason given'), 'next' => $nextName],
            ));
        }

        // Persist the exact name just submitted — a no-op write if it was
        // already this source's stored name (the common case), but
        // required the first time a source creates one at all (nothing
        // stored yet, name came from the global config fallback).
        $source->update(['config' => array_merge($config, [self::TEMPLATE_NAME_CONFIG_KEY => $name])]);

        $this->log($actor, $source, 'created', ['meta_id' => $response->json('id'), 'status' => $response->json('status'), 'name' => $name]);

        return new MessengerTemplateActionResult('success', __('Call permission template ":name" submitted — Meta reports it as :status. Approval usually completes within a few hours.', [
            'name' => $name,
            'status' => $response->json('status') ?? 'unknown',
        ]));
    }

    /**
     * Meta's create-template response doesn't include `rejected_reason`
     * itself, only `status` — this follow-up GET on the template node is
     * how the WABA's own template manager UI shows the detailed reason
     * too (same endpoint shape as CreateMessengerMessageTemplate's
     * identically-named method for Messenger templates). Best-effort:
     * null (rendered as "no reason given") if this read itself fails,
     * which must never block reporting the rejection to the admin — Meta
     * is also known to purge a rejected object entirely sometime after
     * creation (confirmed live: this returned "does not exist" on a
     * retry a few hours after the original rejection), so this is a
     * best-effort courtesy, not a guaranteed lookup.
     */
    private function fetchRejectedReason(string $wabaId, string $accessToken, string $templateId): ?string
    {
        $response = Http::timeout(15)->get("https://graph.facebook.com/{$this->graphVersion()}/{$templateId}", [
            'access_token' => $accessToken,
            'fields' => 'rejected_reason',
        ]);

        return $response->successful() ? $response->json('rejected_reason') : null;
    }

    /**
     * Best-effort cleanup of the rejected template object Meta still
     * created despite rejecting it — same query-parameter DELETE contract
     * as DeleteCallPermissionRequestTemplate, and the same "never let a
     * cleanup failure change the outcome reported to the admin" reasoning
     * as CreateMessengerMessageTemplate's identically-shaped method. A
     * leftover rejected object on Meta's side is cosmetic, not a
     * correctness issue here — this app never references it (no catalog
     * row was written), and the name has already been bumped regardless
     * of whether this cleanup succeeds.
     */
    private function deleteRejectedTemplate(string $wabaId, string $accessToken, string $name): void
    {
        try {
            Http::timeout(15)
                ->withQueryParameters(['access_token' => $accessToken, 'name' => $name])
                ->delete("https://graph.facebook.com/{$this->graphVersion()}/{$wabaId}/message_templates");
        } catch (Throwable $e) {
            Log::warning('WHATSAPP CALL PERMISSION TEMPLATE CREATE: failed to clean up a rejected template on Meta', [
                'name' => $name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The effective template name for this specific source — its own
     * stored name if it has one, otherwise the global config default.
     * Shared verbatim by Create/Delete/RequestLeadCallPermission so all
     * three always agree on which name is currently "the" template for
     * this source.
     */
    public static function resolveTemplateName(LeadSource $source): string
    {
        $config = is_array($source->config) ? $source->config : [];
        $stored = $config[self::TEMPLATE_NAME_CONFIG_KEY] ?? null;

        return is_string($stored) && $stored !== ''
            ? $stored
            : (string) config('calling.call_permission_request_template')['name'];
    }

    /**
     * "foo" -> "foo_v2"; "foo_v2" -> "foo_v3"; "foo_v9" -> "foo_v10" —
     * shared by this class's own REJECTED cleanup above and
     * DeleteCallPermissionRequestTemplate's normal delete path, so both
     * "paths out of a stuck name" advance it the exact same way.
     */
    public static function nextTemplateName(string $current): string
    {
        if (preg_match('/^(.*)_v(\d+)$/', $current, $matches) === 1) {
            return $matches[1].'_v'.((int) $matches[2] + 1);
        }

        return $current.'_v2';
    }

    private function graphVersion(): string
    {
        return (string) config('calling.graph_version');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(PlatformUser $actor, LeadSource $source, string $outcome, array $context): void
    {
        Log::info('WHATSAPP CALL PERMISSION TEMPLATE CREATE', [
            'tenant_id' => $source->tenant_id,
            'lead_source_id' => $source->id,
            'outcome' => $outcome,
            'platform_user_id' => $actor->id,
            ...$context,
        ]);
    }
}
