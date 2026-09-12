<?php

namespace App\Enums;

/**
 * A Deal's own lifecycle — deliberately a small, fixed 3-value enum, NOT a
 * tenant-configurable catalog like LeadStatus. LeadStatus already models the
 * Lead's qualification-through-close pipeline (new/contacted/qualified/
 * proposal_sent/negotiation/won/lost/...); Deal's status only ever answers
 * "does this specific commercial opportunity still need work, or is it
 * decided" and is completely independent of the Lead's own status — closing
 * or reopening a Deal never touches Lead::status_id, and vice versa.
 */
enum DealStatus: string
{
    case Open = 'open';
    case Won = 'won';
    case Lost = 'lost';

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
            self::Open => 'Open',
            self::Won => 'Won',
            self::Lost => 'Lost',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'blue',
            self::Won => 'green',
            self::Lost => 'red',
        };
    }

    /**
     * The only allowed direct transitions are Open<->Won and Open<->Lost —
     * Won and Lost can never swap directly into one another. A Deal
     * mistakenly marked Lost that turns out to actually be Won (or vice
     * versa) must be explicitly reopened first, then re-closed the other
     * way — two deliberate steps, never a silent one-click flip between two
     * mutually exclusive outcomes.
     */
    public function canTransitionTo(self $target): bool
    {
        if ($this === $target) {
            return false;
        }

        return $this === self::Open || $target === self::Open;
    }
}
