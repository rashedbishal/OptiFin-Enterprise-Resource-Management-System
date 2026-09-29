<?php

namespace App\Enums;

/**
 * How a LeadCall was routed at arrival — frozen on the row for the call's
 * whole lifetime (a later reassignment of the lead never changes it).
 *
 * Assignee: the lead's assignee was rung. BackupFallback (WhatsApp Call
 * Backup / Overflow Receiver): the lead HAD an assignee, but that assignee
 * was ineligible or already busy on another active call, and one or more
 * other TenantUsers with is_whatsapp_backup_receiver=true were eligible and
 * not busy — every one of them was rung simultaneously, same fan-out shape
 * as AdminFallback below. Never touches Lead.assigned_to; this is
 * call-level overflow coverage only, decided once by
 * App\Services\CallRouter at arrival and never re-evaluated mid-ring (no
 * live re-routing exists in this app). AdminFallback: the lead was
 * unassigned (including every brand-new caller — auto-assignment runs in a
 * queued listener, after the call arrives), or its assignee was unusable
 * AND no eligible backup receiver existed either, so every active Client
 * Admin with module access was rung. None: nobody was eligible, so the call
 * is recorded as Missed without ringing.
 *
 * AgentInitiated (WhatsApp Outbound Calling): there was no routing decision
 * at all — the agent who placed the call is the sole entry in
 * routed_user_ids, set by App\Actions\Tenant\InitiateOutboundCall. Never
 * produced by CallRouter, which is inbound-only. Outbound calling is
 * deliberately NOT extended to backup receivers — placing a call remains
 * assignee-or-Client-Admin only (App\Policies\LeadPolicy::update()),
 * unrelated to is_whatsapp_backup_receiver.
 */
enum CallRoutingMode: string
{
    case Assignee = 'assignee';
    case BackupFallback = 'backup_fallback';
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
