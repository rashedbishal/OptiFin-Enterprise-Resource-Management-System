<?php

namespace App\Enums;

/**
 * Why a LeadCall reached its terminal state — the `end_reason` column of the
 * approved state machine, one case per documented reason.
 *
 * MetaError deliberately collapses the architecture's `meta_error_<code>`
 * family into one case: a backed enum can't carry a dynamic suffix, and the
 * shape of Meta's error object on a terminate webhook is still unverified
 * (architecture unknowns U1/U2). The raw Meta error code, once known, is
 * stored verbatim in LeadCall::$meta_status — never branched on.
 */
enum CallEndReason: string
{
    case AgentRejected = 'agent_rejected';
    case AgentCancelledDuringAnswer = 'agent_cancelled_during_answer';
    case AgentHangup = 'agent_hangup';
    case CallerEndedOrNotAnswered = 'caller_ended_or_not_answered';
    case RingTimeout = 'ring_timeout';
    case NoRecipient = 'no_recipient';
    case TerminateBeforeConnect = 'terminate_before_connect';
    case EndedBeforeConnect = 'ended_before_connect';
    case CompletedUnconfirmedLocally = 'completed_unconfirmed_locally';
    case RemoteTerminated = 'remote_terminated';
    case ClientReportedEnd = 'client_reported_end';
    case StaleNoTerminate = 'stale_no_terminate';
    case PreAcceptFailed = 'pre_accept_failed';
    case AcceptFailed = 'accept_failed';
    case AnswerTimeout = 'answer_timeout';
    case MetaFailedAfterConnect = 'meta_failed_after_connect';
    case MetaError = 'meta_error';
    case MetaCallingDisabled = 'meta_calling_disabled';
    case MetaApiError = 'meta_api_error';
    case MetaUnreachable = 'meta_unreachable';
    case SdpCacheFailure = 'sdp_cache_failure';
    case IceFailedClientReported = 'ice_failed_client_reported';

    // WhatsApp Outbound Calling — deliberately NOT reusing the inbound-
    // worded cases above, whose semantics differ (e.g. AgentRejected is
    // "an agent declined a call someone else placed", not "the agent who
    // placed this call cancelled their own attempt").
    case AgentCancelledOutbound = 'agent_cancelled_outbound';
    case OutboundConnectFailed = 'outbound_connect_failed';
    case ConsumerDeclinedOutbound = 'consumer_declined_outbound';
    case ConsumerNoAnswerOutbound = 'consumer_no_answer_outbound';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
