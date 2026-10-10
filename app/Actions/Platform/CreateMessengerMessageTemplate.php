<?php

namespace App\Actions\Platform;

use App\Enums\LeadSourceType;
use App\Models\LeadSource;
use App\Models\MessengerMessageTemplate;
use App\Models\PlatformUser;
use App\Models\Tenant;
use App\Support\MessengerTemplateActionResult;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Messenger Utility Message Templates, Platform Admin side — creates and
 * submits a template directly via Meta's Graph API (`POST
 * /{page_id}/message_templates`), then registers the matching
 * MessengerMessageTemplate catalog row in the same step.
 *
 * This is deliberately NOT offered to tenant Client Admins (see
 * MessengerMessageTemplate's own docblock: registration there stays
 * "register what already exists," never "create") — a Messenger Page's
 * access token is a Platform-Admin-managed LeadSource credential a tenant
 * never sees, so creation can only ever happen from this side anyway.
 * Writing the catalog row from Meta's own confirmed response (rather than
 * a human re-typing the name afterward) is the actual point of building
 * this: a catalog entry naming a template that doesn't really exist on
 * Meta — the exact failure this app hit once already — becomes
 * structurally impossible through this path.
 *
 * Body-text variables only (no header media/buttons), same Phase 1 scope
 * as the tenant-facing catalogs. The variable count is derived from the
 * body text itself (counts the highest {{n}} placeholder used) rather than
 * asked for separately, and Meta's required example values are generic
 * placeholders ("Example 1", ...) — they only feed Meta's own review, never
 * shown to a customer.
 *
 * Refuses outright if this source already has a template registered at
 * all (see MessengerMessageTemplate's own docblock for why: one per
 * source, DB-enforced) — the only way to change wording is
 * DeleteMessengerMessageTemplate followed by a fresh call here.
 */
class CreateMessengerMessageTemplate
{
    public function handle(PlatformUser $actor, Tenant $tenant, string $leadSourceId, string $name, string $language, string $bodyText): MessengerTemplateActionResult
    {
        Gate::forUser($actor)->authorize('update', $tenant);

        $source = $tenant->leadSources()->findOrFail($leadSourceId);

        if ($source->type !== LeadSourceType::FacebookMessenger) {
            return new MessengerTemplateActionResult('danger', __('Templates can only be created for Messenger sources.'));
        }

        $config = is_array($source->config) ? $source->config : [];
        $pageId = $config['page_id'] ?? null;
        $pageAccessToken = $config['page_access_token'] ?? null;

        if (! is_string($pageId) || $pageId === '' || ! is_string($pageAccessToken) || $pageAccessToken === '') {
            return new MessengerTemplateActionResult('danger', __('Add the Page id and access token to this source before creating a template.'));
        }

        // One template per source, period (see MessengerMessageTemplate's
        // own docblock) — any existing row blocks a new one, not just a
        // name/language collision.
        $existing = MessengerMessageTemplate::query()->where('lead_source_id', $source->id)->exists();

        if ($existing) {
            $this->log($actor, $source, 'blocked_existing', ['name' => $name, 'language' => $language]);

            return new MessengerTemplateActionResult('danger', __('This source already has a template. Delete it first to create a replacement.'));
        }

        $variableCount = $this->countVariables($bodyText);

        $bodyComponent = ['type' => 'BODY', 'text' => $bodyText];

        // Meta requires an example only when the body actually has
        // variables — omit the key entirely for a 0-variable template
        // rather than sending it as null.
        if ($variableCount > 0) {
            $bodyComponent['example'] = ['body_text' => [array_map(fn (int $n): string => __('Example :n', ['n' => $n]), range(1, $variableCount))]];
        }

        $response = Http::timeout(15)->post("https://graph.facebook.com/v26.0/{$pageId}/message_templates", [
            'access_token' => $pageAccessToken,
            'name' => $name,
            'language' => $language,
            'category' => 'UTILITY',
            'components' => [$bodyComponent],
        ]);

        if (! $response->successful()) {
            $this->log($actor, $source, 'meta_rejected', ['name' => $name, 'language' => $language, 'error' => $response->json('error')]);

            return new MessengerTemplateActionResult('danger', $response->json('error.message') ?? __('Meta rejected this template.'));
        }

        // A 2xx response only means Meta accepted the submission for
        // review — Messenger Utility templates approve OR reject
        // instantly (no PENDING queue, see MessengerMessageTemplate's own
        // docblock), so `status` here is Meta's actual, final verdict, not
        // a placeholder. A REJECTED template can never send a message —
        // registering it anyway (the original bug here) silently occupied
        // this source's one-template slot with something permanently
        // useless, forcing an unexplained delete-and-retry. Treat it as a
        // failure instead: no catalog row, and clean up the now-orphaned
        // rejected object on Meta's side so the same name is immediately
        // reusable for a corrected resubmission.
        if ($response->json('status') === 'REJECTED') {
            $reason = $this->fetchRejectedReason($pageId, $pageAccessToken, (string) $response->json('id'));

            $this->log($actor, $source, 'meta_content_rejected', ['name' => $name, 'language' => $language, 'meta_id' => $response->json('id'), 'rejected_reason' => $reason]);

            $this->deleteRejectedTemplate($pageId, $pageAccessToken, $name);

            return new MessengerTemplateActionResult('danger', __(
                'Meta rejected this template\'s content (:reason) — it was never registered. A Utility template must read as an update about something the customer already has with you (an order, a request, an appointment), not an invitation to chat or an offer. Rewrite the wording and try again — the name is free to reuse.',
                ['reason' => $reason ?? __('no reason given')],
            ));
        }

        MessengerMessageTemplate::create([
            'tenant_id' => $tenant->id,
            'lead_source_id' => $source->id,
            'name' => $name,
            'language' => $language,
            'body_text' => $bodyText,
            'variable_count' => $variableCount,
            'is_active' => true,
        ]);

        $this->log($actor, $source, 'created', ['name' => $name, 'language' => $language, 'meta_id' => $response->json('id'), 'status' => $response->json('status')]);

        return new MessengerTemplateActionResult('success', __('Template created and registered — Meta reports it as :status.', ['status' => $response->json('status') ?? 'unknown']));
    }

    /**
     * Meta's create-template response doesn't include `rejected_reason`
     * itself, only `status` — this follow-up GET on the template node is
     * how the Page UI shows the detailed reason too. Best-effort: null
     * (rendered as "no reason given") if this read itself fails, which
     * must never block reporting the rejection to the admin.
     */
    private function fetchRejectedReason(string $pageId, string $pageAccessToken, string $templateId): ?string
    {
        $response = Http::timeout(15)->get("https://graph.facebook.com/v26.0/{$templateId}", [
            'access_token' => $pageAccessToken,
            'fields' => 'rejected_reason',
        ]);

        return $response->successful() ? $response->json('rejected_reason') : null;
    }

    /**
     * Best-effort cleanup of the rejected template object Meta still
     * created despite rejecting it — never lets a cleanup failure change
     * the outcome reported to the admin; a leftover rejected object on
     * Meta's side is a cosmetic issue there, not a correctness issue here
     * (this app's own catalog never references it, since no row was
     * created). Same query-parameter DELETE contract as
     * DeleteMessengerMessageTemplate.
     */
    private function deleteRejectedTemplate(string $pageId, string $pageAccessToken, string $name): void
    {
        try {
            Http::timeout(15)
                ->withQueryParameters(['access_token' => $pageAccessToken, 'name' => $name])
                ->delete("https://graph.facebook.com/v26.0/{$pageId}/message_templates");
        } catch (Throwable $e) {
            Log::warning('MESSENGER TEMPLATE CREATE: failed to clean up a rejected template on Meta', [
                'name' => $name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The highest {{n}} placeholder number used — e.g. a body with {{1}}
     * and {{2}} has 2 variables. A body with no placeholders at all is a
     * valid 0-variable template.
     */
    private function countVariables(string $bodyText): int
    {
        preg_match_all('/\{\{(\d+)\}\}/', $bodyText, $matches);

        $numbers = array_map('intval', $matches[1]);

        return $numbers === [] ? 0 : max($numbers);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(PlatformUser $actor, LeadSource $source, string $outcome, array $context): void
    {
        Log::info('MESSENGER TEMPLATE CREATE', [
            'tenant_id' => $source->tenant_id,
            'lead_source_id' => $source->id,
            'outcome' => $outcome,
            'platform_user_id' => $actor->id,
            ...$context,
        ]);
    }
}
