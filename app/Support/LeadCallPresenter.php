<?php

namespace App\Support;

use App\Enums\CallEndReason;
use App\Enums\CallStatus;

/**
 * WhatsApp inbound calling — how a LeadCall is worded on the Lead Calls card.
 * Presentation only: it maps the stored status / end reason onto short
 * agent-facing text and a badge colour, and invents no data. Kept out of the
 * enums so their (closed) definitions stay untouched.
 */
final class LeadCallPresenter
{
    public static function statusColor(CallStatus $status): string
    {
        return match ($status) {
            CallStatus::Initiated, CallStatus::Ringing, CallStatus::Answering => 'sky',
            CallStatus::Connected => 'emerald',
            CallStatus::Completed => 'green',
            CallStatus::Missed => 'amber',
            CallStatus::Rejected => 'zinc',
            CallStatus::Failed => 'red',
        };
    }

    public static function endReason(?CallEndReason $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        return match ($reason) {
            CallEndReason::AgentRejected => __('Declined by an agent'),
            CallEndReason::AgentCancelledDuringAnswer => __('Cancelled while answering'),
            CallEndReason::AgentHangup => __('Ended by an agent'),
            CallEndReason::CallerEndedOrNotAnswered => __('Caller hung up or nobody answered'),
            CallEndReason::RingTimeout => __('Not answered in time'),
            CallEndReason::NoRecipient => __('No one available to take the call'),
            CallEndReason::TerminateBeforeConnect, CallEndReason::EndedBeforeConnect => __('Ended before it connected'),
            CallEndReason::CompletedUnconfirmedLocally, CallEndReason::RemoteTerminated, CallEndReason::ClientReportedEnd => __('Ended'),
            CallEndReason::StaleNoTerminate => __('Ended without confirmation'),
            CallEndReason::PreAcceptFailed, CallEndReason::AcceptFailed, CallEndReason::AnswerTimeout => __('Could not be answered'),
            CallEndReason::MetaFailedAfterConnect, CallEndReason::IceFailedClientReported => __('Connection lost'),
            CallEndReason::SdpCacheFailure => __('Could not be answered'),
            CallEndReason::MetaError, CallEndReason::MetaCallingDisabled, CallEndReason::MetaApiError, CallEndReason::MetaUnreachable => __('WhatsApp reported a problem'),
            CallEndReason::AgentCancelledOutbound => __('Cancelled by an agent'),
            CallEndReason::OutboundConnectFailed => __('Could not be started'),
            CallEndReason::ConsumerDeclinedOutbound => __('Declined by the recipient'),
            CallEndReason::ConsumerNoAnswerOutbound => __('Not answered'),
        };
    }

    /**
     * "1m 05s" / "42s" — null when the call never recorded a duration.
     */
    public static function duration(?int $seconds): ?string
    {
        if ($seconds === null) {
            return null;
        }

        if ($seconds < 60) {
            return $seconds.'s';
        }

        return intdiv($seconds, 60).'m '.str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT).'s';
    }
}
