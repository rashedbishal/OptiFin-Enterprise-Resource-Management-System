<?php

namespace App\Services;

use App\Enums\ConsentStatus;
use App\Enums\ConsentType;
use App\Enums\MessageChannel;
use App\Models\Conversation;
use App\Models\LeadSource;
use App\Support\EligibilityResult;

/**
 * Feature #15 — the single gate every outbound send goes through, checked
 * once at compose-time (to render the UI's enabled/disabled state) and
 * again, redundantly, inside the queued send job itself — never trust that
 * an earlier UI-time check is still valid by the time the job actually
 * runs (see the Architecture Brief's Messaging Eligibility section).
 *
 * Phase 1 rules, in order:
 *  1. The lead must not have been merged away.
 *  2. Privacy consent (Feature #14) must not be Withdrawn — a conservative
 *     safety rail covering ALL outbound sending, not just marketing (see
 *     the Architecture Brief's Consent & GDPR Integration section).
 *  3. The lead source must be active and have the credentials its channel
 *     requires present.
 *  4. The channel's own messaging window (24h from the last inbound
 *     message, tracked per channel — see MessageChannel::windowHours())
 *     must still be open.
 *
 * Marketing consent is deliberately NOT checked here — Phase 1 only ever
 * sends a direct, within-window reply to a customer-initiated conversation,
 * which is service/transactional in character, not marketing. Business-
 * initiated/template messaging (which WOULD require Marketing consent) is
 * out of Phase 1 scope entirely, so there is nothing here that needs it yet.
 */
class OutboundEligibilityService
{
    public function evaluate(Conversation $conversation): EligibilityResult
    {
        $lead = $conversation->lead;

        if ($lead->merged_into_lead_id !== null) {
            return EligibilityResult::ineligible('This lead has been merged and can no longer be messaged directly.');
        }

        $privacyConsent = $lead->consents()->where('consent_type', ConsentType::Privacy)->first();

        if ($privacyConsent?->status === ConsentStatus::Withdrawn) {
            return EligibilityResult::ineligible('Privacy consent has been withdrawn for this lead — outbound messaging is blocked.');
        }

        $source = $conversation->leadSource;

        if (! $source->is_active) {
            return EligibilityResult::ineligible('This channel is currently disabled.');
        }

        if (! $this->hasRequiredCredentials($conversation->channel, $source)) {
            return EligibilityResult::ineligible("This channel isn't configured for sending yet.");
        }

        if (! $conversation->isWithinMessagingWindow()) {
            return EligibilityResult::ineligible('The reply window has expired.');
        }

        return EligibilityResult::eligible();
    }

    /**
     * Only checks that the credential VALUES are present — not that any
     * OAuth scope on them is actually valid for sending. Whether the stored
     * token's scope still includes pages_messaging/instagram_manage_messages/
     * whatsapp_business_messaging cannot be reliably known without a live
     * Meta debug_token call (see MetaPermissionsController), which this
     * service deliberately does not perform on every compose — a
     * scope-invalid token surfaces as a normal platform-rejection failure
     * at send time instead (see each *Sender's own handling).
     */
    private function hasRequiredCredentials(MessageChannel $channel, LeadSource $source): bool
    {
        $config = $source->config ?? [];

        return match ($channel) {
            MessageChannel::Whatsapp => ! empty($config['phone_number_id']) && ! empty($config['access_token']),
            MessageChannel::Messenger => ! empty($config['page_access_token']),
            MessageChannel::Instagram => ! empty($config['access_token']),
        };
    }
}
