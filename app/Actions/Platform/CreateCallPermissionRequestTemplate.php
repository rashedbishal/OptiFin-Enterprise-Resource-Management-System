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
 * tenant's own WhatsApp Business Account — config('calling.
 * call_permission_request_template') is the exact name/language/body/
 * footer contract both sides share. Deliberately narrow, NOT general
 * WhatsApp template management: this app otherwise never creates WhatsApp
 * templates via Meta's API at all (see WhatsAppMessageTemplate's own
 * docblock — most stored WhatsApp tokens lack the
 * whatsapp_business_management scope this needs), just the one template
 * Calling cannot function without.
 *
 * Confirmed live in production (2026-10-14): a missing/unapproved
 * template is exactly what makes RequestLeadCallPermission fail with
 * Meta error 132001, and since this template is per-WABA, every tenant
 * needs their own copy actually created — nothing does this
 * automatically, so Platform Admin gets a one-click button for it on the
 * Lead Sources page.
 *
 * Unlike Messenger Utility templates (instant approve/reject, see
 * CreateMessengerMessageTemplate), WhatsApp template review is
 * asynchronous — a successful create response here only ever means
 * "submitted," reported via Meta's own `status` (normally PENDING);
 * approval/rejection happens later, outside this request. No local
 * catalog row is written — nothing in this app tracks WhatsApp template
 * approval status, matching WhatsAppMessageTemplate's existing
 * "self-registered, never Meta-tracked" philosophy.
 */
class CreateCallPermissionRequestTemplate
{
    public function handle(PlatformUser $actor, Tenant $tenant, string $leadSourceId): MessengerTemplateActionResult
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

        $response = Http::timeout(15)->post("https://graph.facebook.com/{$this->graphVersion()}/{$wabaId}/message_templates", [
            'access_token' => $accessToken,
            'name' => $template['name'],
            'language' => $template['language'],
            'category' => 'UTILITY',
            'components' => [
                ['type' => 'BODY', 'text' => $template['body']],
                ['type' => 'FOOTER', 'text' => $template['footer']],
                ['type' => 'call_permission_request'],
            ],
        ]);

        if (! $response->successful()) {
            $this->log($actor, $source, 'meta_rejected', ['error' => $response->json('error')]);

            return new MessengerTemplateActionResult('danger', $response->json('error.error_user_msg') ?? $response->json('error.message') ?? __('Meta rejected this template.'));
        }

        $this->log($actor, $source, 'created', ['meta_id' => $response->json('id'), 'status' => $response->json('status')]);

        return new MessengerTemplateActionResult('success', __('Call permission template submitted — Meta reports it as :status. Approval usually completes within a few hours.', [
            'status' => $response->json('status') ?? 'unknown',
        ]));
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
