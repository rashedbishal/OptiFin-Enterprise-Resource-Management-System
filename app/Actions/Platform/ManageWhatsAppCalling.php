<?php

namespace App\Actions\Platform;

use App\Enums\CallingApiError;
use App\Enums\LeadSourceType;
use App\Models\LeadSource;
use App\Models\PlatformUser;
use App\Models\Tenant;
use App\Services\WhatsAppCallingClient;
use App\Support\CallingActionResult;
use App\Support\CallingApiResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp inbound calling, Platform Admin side — enable, disable and check the
 * calling switch of one WhatsApp lead source at Meta.
 *
 * Three pieces of state stay strictly separate:
 *  - `calling_enabled`         our local INTENT, changed only by enable/disable;
 *  - `calling_meta_status`     the status Meta was last OBSERVED to report;
 *  - `calling_meta_checked_at` when that observation was taken.
 * A Meta reply, a restriction or an error never rewrites the intent. Check only
 * ever writes the two observation columns.
 *
 * Concurrency: no lock is ever held across a Meta request and there is no
 * distributed lock. Observations carry the time their request STARTED and are
 * written with a conditional UPDATE (`checked_at IS NULL OR checked_at <= stamp`),
 * so a slow, older observation cannot overwrite a newer one. Enable sets the
 * intent in the same conditional UPDATE, so a stale confirmation cannot turn it
 * on either. Racing admins can still leave intent and Meta disagreeing — that is
 * shown as Mismatch and cleared by Check, never hidden.
 *
 * Eligibility restrictions parsed from Meta's settings are shown transiently
 * after Check/Enable and are deliberately not stored (no schema for them yet);
 * they disappear on the next page load.
 */
class ManageWhatsAppCalling
{
    /**
     * Why Enable cannot be used for this source, or null when it can. Shared by
     * the action and the UI so the disabled reason and the enforcement agree.
     */
    public function enableBlockedReason(LeadSource $source): ?string
    {
        if (! config('calling.enable_action_available')) {
            return __('Calling is prepared but browser answering is not available yet, so it cannot be enabled.');
        }

        if (! $source->is_active) {
            return __('Activate this source before enabling calling.');
        }

        $config = is_array($source->config) ? $source->config : [];

        foreach (['app_secret' => __('app secret'), 'phone_number_id' => __('phone number ID'), 'access_token' => __('access token')] as $key => $label) {
            if (! is_string($config[$key] ?? null) || $config[$key] === '') {
                return __('Add the :field to this source before enabling calling.', ['field' => $label]);
            }
        }

        return null;
    }

    public function enable(PlatformUser $actor, Tenant $tenant, string $leadSourceId): CallingActionResult
    {
        $source = $this->resolve($actor, $tenant, $leadSourceId);

        if ($notWhatsapp = $this->rejectNonWhatsapp($source)) {
            return $notWhatsapp;
        }

        if (($reason = $this->enableBlockedReason($source)) !== null) {
            $this->log($actor, $source, 'enable', 'blocked');

            return new CallingActionResult('danger', $reason);
        }

        $client = new WhatsAppCallingClient($source);

        $post = $client->setCallingStatus(true);

        if (! $post->successful) {
            $this->log($actor, $source, 'enable', 'meta_rejected', $post);

            $message = $post->error === CallingApiError::Network
                ? $post->userMessage().' '.__('The request may or may not have reached WhatsApp — run Check to see the current status.')
                : $post->userMessage();

            return new CallingActionResult('danger', $message);
        }

        $observedAt = Carbon::now();
        $get = $client->getSettings();

        if (! $get->successful) {
            $this->log($actor, $source, 'enable', 'unconfirmed', $get);

            return new CallingActionResult('warning', $this->unconfirmedMessage());
        }

        $metaStatus = $this->observedStatus($get);

        if ($metaStatus !== 'ENABLED') {
            if ($metaStatus !== null) {
                $this->writeObservation($source, $metaStatus, $observedAt);
            }

            $this->log($actor, $source, 'enable', 'disagreed');

            return new CallingActionResult('warning', $this->unconfirmedMessage(), $this->restrictions($get), $this->warnings($get));
        }

        $applied = $this->writeObservation($source, 'ENABLED', $observedAt, ['calling_enabled' => true]);

        if (! $applied) {
            $this->log($actor, $source, 'enable', 'superseded');

            return new CallingActionResult('warning', __('Another change to this source\'s calling status happened at the same time, so nothing was turned on here. Run Check to see the current status.'));
        }

        $this->log($actor, $source, 'enable', 'enabled', $get);

        return new CallingActionResult('success', __('Calling is now on for this WhatsApp number.'), $this->restrictions($get), $this->warnings($get));
    }

    public function disable(PlatformUser $actor, Tenant $tenant, string $leadSourceId): CallingActionResult
    {
        $source = $this->resolve($actor, $tenant, $leadSourceId);

        if ($notWhatsapp = $this->rejectNonWhatsapp($source)) {
            return $notWhatsapp;
        }

        // Local intent first: whatever happens at Meta, this source is no longer
        // considered callable by us.
        LeadSource::query()
            ->whereKey($source->id)
            ->where('tenant_id', $tenant->id)
            ->update(['calling_enabled' => false]);

        if (! $this->hasApiCredentials($source)) {
            $this->log($actor, $source, 'disable', 'local_only');

            return new CallingActionResult('warning', __('Calling is off here, but WhatsApp could not be updated because this source has no phone number ID or access token. Turn calling off in Meta if it is still on there.'));
        }

        $post = (new WhatsAppCallingClient($source))->setCallingStatus(false);

        if (! $post->successful) {
            $this->log($actor, $source, 'disable', 'meta_failed', $post);

            return new CallingActionResult('warning', __('Calling is off here, but WhatsApp could not be updated: :reason Run Check to see whether it is still on there.', ['reason' => $post->userMessage()]));
        }

        $this->writeObservation($source, 'DISABLED', Carbon::now());

        $this->log($actor, $source, 'disable', 'disabled', $post);

        return new CallingActionResult('success', __('Calling is now off for this WhatsApp number.'));
    }

    public function check(PlatformUser $actor, Tenant $tenant, string $leadSourceId): CallingActionResult
    {
        $source = $this->resolve($actor, $tenant, $leadSourceId);

        if ($notWhatsapp = $this->rejectNonWhatsapp($source)) {
            return $notWhatsapp;
        }

        if (! $this->hasApiCredentials($source)) {
            $this->log($actor, $source, 'check', 'blocked');

            return new CallingActionResult('danger', __('Add the phone number ID and access token to this source before checking calling.'));
        }

        $observedAt = Carbon::now();
        $get = (new WhatsAppCallingClient($source))->getSettings();

        if (! $get->successful) {
            $this->log($actor, $source, 'check', 'failed', $get);

            return new CallingActionResult('danger', $get->userMessage());
        }

        $metaStatus = $this->observedStatus($get);

        if ($metaStatus === null) {
            $this->log($actor, $source, 'check', 'unknown_status', $get);

            return new CallingActionResult('warning', __('WhatsApp did not report a calling status for this number, so nothing was recorded.'), $this->restrictions($get), $this->warnings($get));
        }

        $this->writeObservation($source, $metaStatus, $observedAt);

        $this->log($actor, $source, 'check', 'checked', $get);

        $metaOn = $metaStatus === 'ENABLED';
        $local = (bool) $source->calling_enabled;

        $message = $metaOn === $local
            ? ($metaOn ? __('Calling is on at WhatsApp, matching this source.') : __('Calling is off at WhatsApp, matching this source.'))
            : ($metaOn ? __('Calling is on at WhatsApp but off here. Use Disable to turn it off there, or leave it.') : __('Calling is off at WhatsApp but on here. Use Enable to turn it on there.'));

        return new CallingActionResult($metaOn === $local ? 'success' : 'warning', $message, $this->restrictions($get), $this->warnings($get));
    }

    /**
     * Authorize, then resolve the source THROUGH the tenant so a source id from
     * another tenant is simply not found.
     */
    private function resolve(PlatformUser $actor, Tenant $tenant, string $leadSourceId): LeadSource
    {
        Gate::forUser($actor)->authorize('update', $tenant);

        return $tenant->leadSources()->findOrFail($leadSourceId);
    }

    private function rejectNonWhatsapp(LeadSource $source): ?CallingActionResult
    {
        return $source->type === LeadSourceType::Whatsapp
            ? null
            : new CallingActionResult('danger', __('Calling can only be managed for WhatsApp sources.'));
    }

    private function hasApiCredentials(LeadSource $source): bool
    {
        $config = is_array($source->config) ? $source->config : [];

        return is_string($config['phone_number_id'] ?? null) && $config['phone_number_id'] !== ''
            && is_string($config['access_token'] ?? null) && $config['access_token'] !== '';
    }

    /**
     * The only two statuses stored; anything else Meta might say is treated as
     * unknown rather than written into a 16-character column.
     */
    private function observedStatus(CallingApiResult $result): ?string
    {
        $status = $result->data['calling_status'] ?? null;

        return in_array($status, ['ENABLED', 'DISABLED'], true) ? $status : null;
    }

    /**
     * Conditional snapshot write — see the class docblock. `$intent` columns
     * ride in the same UPDATE so they are only applied together with a
     * non-stale observation. Returns whether a row was updated.
     *
     * @param  array<string, mixed>  $intent
     */
    private function writeObservation(LeadSource $source, string $metaStatus, Carbon $observedAt, array $intent = []): bool
    {
        return LeadSource::query()
            ->whereKey($source->id)
            ->where('tenant_id', $source->tenant_id)
            ->where(fn ($query) => $query
                ->whereNull('calling_meta_checked_at')
                ->orWhere('calling_meta_checked_at', '<=', $observedAt))
            ->update([
                ...$intent,
                'calling_meta_status' => $metaStatus,
                'calling_meta_checked_at' => $observedAt,
            ]) === 1;
    }

    /**
     * @return array<int, array{type: string, expiration: ?int}>
     */
    private function restrictions(CallingApiResult $result): array
    {
        $restrictions = $result->data['restrictions'] ?? [];

        return is_array($restrictions) ? $restrictions : [];
    }

    /**
     * @return array<int, string>
     */
    private function warnings(CallingApiResult $result): array
    {
        $warnings = [];

        if (($result->data['sip_status'] ?? null) === 'ENABLED') {
            $warnings[] = __('SIP calling is enabled for this number. The Graph API call actions do not work on SIP-enabled numbers, so browser answering will not be possible for it.');
        }

        return $warnings;
    }

    private function unconfirmedMessage(): string
    {
        return __('WhatsApp accepted the request, but calling could not be confirmed as on. It stays off here — run Check to see the current status.');
    }

    private function log(PlatformUser $actor, LeadSource $source, string $action, string $outcome, ?CallingApiResult $api = null): void
    {
        Log::channel('whatsapp')->info('WA CALLING ADMIN', [
            'tenant_id' => $source->tenant_id,
            'lead_source_id' => $source->id,
            'action' => $action,
            'outcome' => $outcome,
            'category' => $api?->error?->value,
            'meta_code' => $api?->metaCode,
            'http_status' => $api?->httpStatus,
            'fbtrace_id' => $api?->fbtraceId,
            'duration_ms' => $api?->durationMs,
            'platform_user_id' => $actor->id,
        ]);
    }
}
