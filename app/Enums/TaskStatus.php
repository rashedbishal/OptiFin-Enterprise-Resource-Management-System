<?php

namespace App\Enums;

/**
 * A Task's own lifecycle — structurally the same shape as App\Enums\
 * DealStatus (one active state, two mutually-exclusive terminal outcomes),
 * deliberately echoing that just-approved rule rather than inventing a
 * different one: Pending is the hub state; Completed and Cancelled can each
 * only be reached from (and reopened back to) Pending, never swapped
 * directly into one another.
 */
enum TaskStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

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
            self::Pending => 'Pending',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'blue',
            self::Completed => 'green',
            self::Cancelled => 'red',
        };
    }

    /**
     * Only Pending<->Completed and Pending<->Cancelled are allowed —
     * Completed and Cancelled can never swap directly into one another. See
     * this enum's own docblock and App\Enums\DealStatus::canTransitionTo(),
     * which this deliberately mirrors.
     */
    public function canTransitionTo(self $target): bool
    {
        if ($this === $target) {
            return false;
        }

        return $this === self::Pending || $target === self::Pending;
    }
}
