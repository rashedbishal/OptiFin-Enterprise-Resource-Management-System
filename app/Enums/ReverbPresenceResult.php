<?php

namespace App\Enums;

/**
 * WhatsApp Call Backup / Overflow Receiver, Phase 2 — the outcome of asking
 * Reverb whether a TenantUser is currently a member of their tenant's
 * softphone presence channel. Deliberately three-valued, not a bool: Present
 * and Absent are both facts CallRouter (via SoftphonePresence) can route on
 * directly; QueryFailed is NOT "Absent" — collapsing the two would turn a
 * Reverb outage into every assigned call overflowing to backups. QueryFailed
 * instead tells SoftphonePresence to fall back to the existing
 * softphone_last_seen_at heartbeat check.
 */
enum ReverbPresenceResult
{
    case Present;
    case Absent;
    case QueryFailed;
}
