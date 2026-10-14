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
 * The other half of CreateCallPermissionRequestTemplate. Since an
 * approved WhatsApp template's content is permanently fixed on Meta's
 * side (no edit endpoint exists, same as Messenger Utility templates —
 * see DeleteMessengerMessageTemplate's own docblock), the only way to
 * change this template's wording is to delete it and submit a
 * replacement — this is that delete. No local catalog row to remove (see
 * CreateCallPermissionRequestTemplate's own docblock: WhatsApp templates
 * are never tracked in this app's own tables), so this only ever talks
 * to Meta.
 *
 * Meta reporting the name as already-gone is treated as success here too
 * (same "stays safely re-runnable" reasoning as
 * DeleteMessengerMessageTemplate) — clicking Delete when nothing exists
 * just confirms there's nothing to delete rather than erroring.
 *
 * UNLIKE Messenger templates, deleting this does NOT free up its (name,
 * language) for immediate reuse — confirmed live, 2026-10-14: Meta locks
 * that exact pair for roughly 30 days after a delete ("New English
 * content can't be added while the existing English content is being
 * deleted. Try again in 4 weeks or consider creating a new message
 * template."). A Create attempt under the same name during that window
 * fails outright, even though nothing currently exists by that name. The
 * success message below reflects this — it deliberately does NOT claim
 * an immediate replacement is possible. If one is needed right away, bump
 * config('calling.call_permission_request_template')['name'] to a new
 * value (e.g. the next _vN suffix) instead of waiting out the lock.
 */
class DeleteCallPermissionRequestTemplate
{
    public function handle(PlatformUser $actor, Tenant $tenant, string $leadSourceId): MessengerTemplateActionResult
    {
        Gate::forUser($actor)->authorize('update', $tenant);

        $source = $tenant->leadSources()->findOrFail($leadSourceId);

        if ($source->type !== LeadSourceType::Whatsapp) {
            return new MessengerTemplateActionResult('danger', __('This template can only be deleted for WhatsApp sources.'));
        }

        $config = is_array($source->config) ? $source->config : [];
        $wabaId = $config['whatsapp_business_account_id'] ?? null;
        $accessToken = $config['access_token'] ?? null;

        if (! is_string($wabaId) || $wabaId === '' || ! is_string($accessToken) || $accessToken === '') {
            return new MessengerTemplateActionResult('danger', __('Add the WhatsApp Business Account ID and Access Token to this source before deleting this template.'));
        }

        $name = (string) config('calling.call_permission_request_template')['name'];

        // Same query-parameter contract as DeleteMessengerMessageTemplate
        // — Meta's delete endpoint expects name/access_token in the URL,
        // not a request body.
        $response = Http::timeout(15)
            ->withQueryParameters(['access_token' => $accessToken, 'name' => $name])
            ->delete("https://graph.facebook.com/{$this->graphVersion()}/{$wabaId}/message_templates");

        $alreadyGone = str_contains(strtolower((string) $response->json('error.message')), 'does not exist');

        if (! $response->successful() && ! $alreadyGone) {
            $this->log($actor, $source, 'meta_rejected', ['error' => $response->json('error')]);

            return new MessengerTemplateActionResult('danger', $response->json('error.message') ?? __('Meta could not delete this template.'));
        }

        $this->log($actor, $source, $alreadyGone ? 'deleted_already_gone' : 'deleted', ['name' => $name]);

        return new MessengerTemplateActionResult('success', __('Call permission template deleted. Meta locks this exact name for about 30 days afterward — to submit a replacement now, change the configured template name to a new value first.'));
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
        Log::info('WHATSAPP CALL PERMISSION TEMPLATE DELETE', [
            'tenant_id' => $source->tenant_id,
            'lead_source_id' => $source->id,
            'outcome' => $outcome,
            'platform_user_id' => $actor->id,
            ...$context,
        ]);
    }
}
