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
 * LeadSources::saveCallPermissionTemplate()'s own validation) — Meta
 * doesn't care what the wording says, only that the body contains
 * exactly the two placeholders {{1}} (lead name) and {{2}} (lead
 * inquiry) RequestLeadCallPermission::sendTemplate() always sends, in
 * that exact shape. A body approved with a different variable count
 * would pass Meta's review and then silently fail every real send
 * afterward — this action's caller is responsible for that validation,
 * not this action itself (same separation as every other Platform Action
 * in this app: Gate/shape checks happen once, here, but field-level
 * content validation belongs to the Livewire component's own validate()).
 *
 * Confirmed live in production (2026-10-14): a missing/unapproved
 * template is exactly what makes RequestLeadCallPermission fail with
 * Meta error 132001, and since this template is per-WABA, every tenant
 * needs their own copy actually created — nothing does this
 * automatically, so Platform Admin gets a dedicated form for it on the
 * Lead Sources page.
 *
 * Unlike Messenger Utility templates (instant approve/reject, see
 * CreateMessengerMessageTemplate), WhatsApp template review is
 * asynchronous — a successful create response here only ever means
 * "submitted," reported via Meta's own `status` (normally PENDING);
 * approval/rejection happens later, outside this request. No local
 * catalog row is written for the template's CONTENT — nothing in this
 * app tracks WhatsApp template approval status, matching
 * WhatsAppMessageTemplate's existing "self-registered, never
 * Meta-tracked" philosophy. Only the NAME currently in use is tracked
 * (see TEMPLATE_NAME_CONFIG_KEY), and only because sending/deleting
 * later both need to agree on it.
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
