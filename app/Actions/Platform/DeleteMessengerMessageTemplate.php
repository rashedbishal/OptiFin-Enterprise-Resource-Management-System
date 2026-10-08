<?php

namespace App\Actions\Platform;

use App\Models\MessengerMessageTemplate;
use App\Models\PlatformUser;
use App\Models\Tenant;
use App\Support\MessengerTemplateActionResult;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Messenger Utility Message Templates, Platform Admin side — the other
 * half of CreateMessengerMessageTemplate. Since an approved template's
 * content is permanently fixed on Meta's side (confirmed with Meta: no
 * edit endpoint exists), and this app enforces at most one template per
 * source (see MessengerMessageTemplate's own docblock), the only way to
 * change a template's wording is to delete the current one and create a
 * replacement — this is that delete.
 *
 * Calls Meta's real `DELETE /{page_id}/message_templates?name=...` first;
 * the local catalog row is only removed once Meta confirms it, never
 * speculatively — a failed Meta delete must never leave this app
 * believing a template is gone when it is still live and sendable there.
 * Meta reporting the name as already-gone (e.g. deleted some other way) is
 * treated as success here too, so this action stays safely re-runnable.
 */
class DeleteMessengerMessageTemplate
{
    public function handle(PlatformUser $actor, Tenant $tenant, string $leadSourceId): MessengerTemplateActionResult
    {
        Gate::forUser($actor)->authorize('update', $tenant);

        $source = $tenant->leadSources()->findOrFail($leadSourceId);
        $template = MessengerMessageTemplate::where('lead_source_id', $source->id)->first();

        if ($template === null) {
            return new MessengerTemplateActionResult('danger', __('This source has no template to delete.'));
        }

        $config = is_array($source->config) ? $source->config : [];
        $pageId = $config['page_id'] ?? null;
        $pageAccessToken = $config['page_access_token'] ?? null;

        if (! is_string($pageId) || $pageId === '' || ! is_string($pageAccessToken) || $pageAccessToken === '') {
            return new MessengerTemplateActionResult('danger', __('Add the Page id and access token to this source before deleting its template.'));
        }

        // Meta's delete endpoint expects name/access_token as URL query
        // parameters, not a request body — Laravel's Http::delete($url,
        // $data) sends $data as a JSON body by default (same as post()),
        // which Meta's endpoint would simply ignore. withQueryParameters()
        // is what actually puts them in the query string.
        $response = Http::timeout(15)
            ->withQueryParameters(['access_token' => $pageAccessToken, 'name' => $template->name])
            ->delete("https://graph.facebook.com/v26.0/{$pageId}/message_templates");

        // Meta reports an already-nonexistent template as a normal error
        // response rather than a 2xx — treated as success here too, since
        // the end state (no live template by this name) is identical.
        $alreadyGone = str_contains(strtolower((string) $response->json('error.message')), 'does not exist');

        if (! $response->successful() && ! $alreadyGone) {
            $this->log($actor, $template, 'meta_rejected', ['error' => $response->json('error')]);

            return new MessengerTemplateActionResult('danger', $response->json('error.message') ?? __('Meta could not delete this template.'));
        }

        $templateName = $template->name;
        $template->delete();

        $this->log($actor, $template, $alreadyGone ? 'deleted_already_gone' : 'deleted', ['name' => $templateName]);

        return new MessengerTemplateActionResult('success', __('Template deleted — you can create a replacement now.'));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(PlatformUser $actor, MessengerMessageTemplate $template, string $outcome, array $context): void
    {
        Log::info('MESSENGER TEMPLATE DELETE', [
            'tenant_id' => $template->tenant_id,
            'lead_source_id' => $template->lead_source_id,
            'outcome' => $outcome,
            'platform_user_id' => $actor->id,
            ...$context,
        ]);
    }
}
