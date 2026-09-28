<?php

namespace App\Enums;

/**
 * WhatsApp Outbound Calling — a Lead's CURRENT effective call permission
 * state, mirroring Meta's own verified `GET .../call_permissions` and
 * `call_permission_reply` webhook contract:
 *
 *  - NoPermission: Meta's permission.status === "no_permission", or never
 *    checked/requested at all.
 *  - Requested: a call_permission_request template was sent; no reply yet.
 *    Local-only bookkeeping — Meta's own permission.status has no distinct
 *    "pending" value, so this exists purely for UI copy, never as a gate.
 *  - Temporary: permission.status === "temporary" (a live check) or a
 *    call_permission_reply with response=accept, is_permanent=false. Expires
 *    — see LeadCallPermission::expires_at. Meta sends no webhook when a
 *    temporary permission expires; expiry is always computed locally against
 *    expires_at, never pushed.
 *  - Permanent: permission.status === "permanent", or response=accept,
 *    is_permanent=true. Terminal in practice — Meta's own error 138017
 *    ("permanent permission already exists") means re-requesting is refused.
 *  - Rejected: a call_permission_reply with response=reject. A live
 *    permission.status check afterward simply reports "no_permission" again
 *    (Meta has no distinct rejected value there) — this case exists only so
 *    the UI can show "previously declined" copy; it never blocks a later
 *    request, which is still gated purely by Meta's live actions[].limits[].
 */
enum LeadCallPermissionStatus: string
{
    case NoPermission = 'no_permission';
    case Requested = 'requested';
    case Temporary = 'temporary';
    case Permanent = 'permanent';
    case Rejected = 'rejected';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::NoPermission => 'No permission',
            self::Requested => 'Requested',
            self::Temporary => 'Temporary',
            self::Permanent => 'Permanent',
            self::Rejected => 'Rejected',
        };
    }
}
