<?php

namespace App\Enums;

/**
 * WhatsApp inbound calling (Phase 1) — a LeadCall's own lifecycle, driven by
 * OUR state, never inferred from Meta's terminate `status` alone (Meta only
 * documents FAILED|COMPLETED there — see the approved architecture's
 * transition matrix). Ringing/Answering/Connected are the "active" states;
 * Completed/Missed/Rejected/Failed are terminal and final — the only writes
 * legal after a terminal state are fill-only ones (meta_status,
 * meta_terminated_at, ended_at, duration_seconds), never a status change.
 *
 * allowedNext() encodes the STRUCTURAL edges of that matrix only (which
 * status may follow which). The guards on each edge (who may trigger it, the
 * Meta status/error that selects between Completed/Missed/Failed, the
 * timeouts) belong to the later state-machine service, not to this enum.
 * A row's very first status (Ringing on connect, or Missed for a
 * terminate-before-connect / no-recipient arrival) is creation, not a
 * transition, so it is not an edge here.
 */
enum CallStatus: string
{
    case Ringing = 'ringing';
    case Answering = 'answering';
    case Connected = 'connected';
    case Completed = 'completed';
    case Missed = 'missed';
    case Rejected = 'rejected';
    case Failed = 'failed';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The values a call is "in flight" in — what deletion/merge guards and
     * the stale-call sweep key off.
     *
     * @return array<int, string>
     */
    public static function activeValues(): array
    {
        return array_values(array_map(
            fn (self $status): string => $status->value,
            array_filter(self::cases(), fn (self $status): bool => $status->isActive()),
        ));
    }

    public function isActive(): bool
    {
        return match ($this) {
            self::Ringing, self::Answering, self::Connected => true,
            default => false,
        };
    }

    public function isTerminal(): bool
    {
        return ! $this->isActive();
    }

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Ringing => [self::Answering, self::Rejected, self::Missed, self::Failed],
            self::Answering => [self::Connected, self::Completed, self::Missed, self::Rejected, self::Failed],
            self::Connected => [self::Completed, self::Failed],
            self::Completed, self::Missed, self::Rejected, self::Failed => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Ringing => 'Ringing',
            self::Answering => 'Answering',
            self::Connected => 'Connected',
            self::Completed => 'Completed',
            self::Missed => 'Missed',
            self::Rejected => 'Rejected',
            self::Failed => 'Failed',
        };
    }
}
