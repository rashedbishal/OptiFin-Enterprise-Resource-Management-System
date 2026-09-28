<?php

namespace App\Enums;

/**
 * How a LeadCall was routed at arrival — frozen on the row for the call's
 * whole lifetime (a later reassignment of the lead never changes it).
 *
 * Assignee: the lead's assignee was rung. AdminFallback: the lead was
 * unassigned (including every brand-new caller — auto-assignment runs in a
 * queued listener, after the call arrives) or its assignee was unusable, so
 * every active Client Admin with module access was rung. None: nobody was
 * eligible, so the call is recorded as Missed without ringing.
 *
 * AgentInitiated (WhatsApp Outbound Calling): there was no routing decision
 * at all — the agent who placed the call is the sole entry in
 * routed_user_ids, set by App\Actions\Tenant\InitiateOutboundCall. Never
 * produced by CallRouter, which is inbound-only.
 */
enum CallRoutingMode: string
{
    case Assignee = 'assignee';
    case AdminFallback = 'admin_fallback';
    case None = 'none';
    case AgentInitiated = 'agent_initiated';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
