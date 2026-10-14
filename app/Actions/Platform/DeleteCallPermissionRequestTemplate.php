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
 * fails outright, even though nothing currently exists by that name.
 *
 * So a successful delete here always bumps this source's OWN stored name
 * (see CreateCallPermissionRequestTemplate::TEMPLATE_NAME_CONFIG_KEY/
 * resolveTemplateName()) to the next _vN suffix and persists it — the
 * very next Create click for this source submits under a fresh name
 * automatically, no admin action needed, and never touches any OTHER
 * source's own stored name.
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

        $name = CreateCallPermissionRequestTemplate::resolveTemplateName($source);

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

        $nextName = $this->nextTemplateName($name);
        $source->update(['config' => array_merge($config, [CreateCallPermissionRequestTemplate::TEMPLATE_NAME_CONFIG_KEY => $nextName])]);

        $this->log($actor, $source, $alreadyGone ? 'deleted_already_gone' : 'deleted', ['name' => $name, 'next_name' => $nextName]);

        return new MessengerTemplateActionResult('success', __('Call permission template ":name" deleted. Meta locks that exact name for about 30 days, so the next template you create for this source will automatically use a new name (":next").', [
            'name' => $name,
            'next' => $nextName,
        ]));
    }

    /**
     * "foo" -> "foo_v2"; "foo_v2" -> "foo_v3"; "foo_v9" -> "foo_v10" — the
     * same scheme already used to recover live, 2026-10-14
     * (call_permission_request -> call_permission_request_v2), just
     * applied automatically from here on instead of by hand.
     */
    private function nextTemplateName(string $current): string
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
        Log::info('WHATSAPP CALL PERMISSION TEMPLATE DELETE', [
            'tenant_id' => $source->tenant_id,
            'lead_source_id' => $source->id,
            'outcome' => $outcome,
            'platform_user_id' => $actor->id,
            ...$context,
        ]);
    }
}
