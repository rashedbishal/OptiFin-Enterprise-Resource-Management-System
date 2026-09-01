<?php

namespace App\Enums;

enum LeadStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Qualified = 'qualified';
    case ProposalSent = 'proposal_sent';
    case Negotiation = 'negotiation';
    case Won = 'won';
    case Lost = 'lost';
    case Unqualified = 'unqualified';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get the human-readable label for this status.
     */
    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Contacted => 'Contacted',
            self::Qualified => 'Qualified',
            self::ProposalSent => 'Proposal Sent',
            self::Negotiation => 'Negotiation',
            self::Won => 'Won',
            self::Lost => 'Lost',
            self::Unqualified => 'Unqualified',
        };
    }

    /**
     * Get the Flux badge/indicator color representing this status, for the
     * Kanban board columns and status badges.
     */
    public function color(): string
    {
        return match ($this) {
            self::New => 'blue',
            self::Contacted => 'sky',
            self::Qualified => 'indigo',
            self::ProposalSent => 'amber',
            self::Negotiation => 'orange',
            self::Won => 'green',
            self::Lost => 'red',
            self::Unqualified => 'zinc',
        };
    }

    /**
     * The Kanban column's resting background — a subtle tint of this
     * status's color() rather than plain zinc, so each column reads at a
     * glance instead of all of them looking identical.
     */
    public function columnBackgroundClasses(): string
    {
        return match ($this) {
            self::New => 'bg-blue-50/60 dark:bg-blue-950/20',
            self::Contacted => 'bg-sky-50/60 dark:bg-sky-950/20',
            self::Qualified => 'bg-indigo-50/60 dark:bg-indigo-950/20',
            self::ProposalSent => 'bg-amber-50/60 dark:bg-amber-950/20',
            self::Negotiation => 'bg-orange-50/60 dark:bg-orange-950/20',
            self::Won => 'bg-green-50/60 dark:bg-green-950/20',
            self::Lost => 'bg-red-50/60 dark:bg-red-950/20',
            self::Unqualified => 'bg-zinc-100/60 dark:bg-zinc-800/40',
        };
    }

    /**
     * The same column, deepened, while a dragged card is hovering over it
     * as a valid drop target — same hue as columnBackgroundClasses() so the
     * highlight reads as "this column, more so" rather than an unrelated
     * generic accent color.
     */
    public function columnDropTargetClasses(): string
    {
        return match ($this) {
            self::New => 'bg-blue-100 ring-2 ring-inset ring-blue-400 dark:bg-blue-900/40 dark:ring-blue-500',
            self::Contacted => 'bg-sky-100 ring-2 ring-inset ring-sky-400 dark:bg-sky-900/40 dark:ring-sky-500',
            self::Qualified => 'bg-indigo-100 ring-2 ring-inset ring-indigo-400 dark:bg-indigo-900/40 dark:ring-indigo-500',
            self::ProposalSent => 'bg-amber-100 ring-2 ring-inset ring-amber-400 dark:bg-amber-900/40 dark:ring-amber-500',
            self::Negotiation => 'bg-orange-100 ring-2 ring-inset ring-orange-400 dark:bg-orange-900/40 dark:ring-orange-500',
            self::Won => 'bg-green-100 ring-2 ring-inset ring-green-400 dark:bg-green-900/40 dark:ring-green-500',
            self::Lost => 'bg-red-100 ring-2 ring-inset ring-red-400 dark:bg-red-900/40 dark:ring-red-500',
            self::Unqualified => 'bg-zinc-200 ring-2 ring-inset ring-zinc-400 dark:bg-zinc-700/60 dark:ring-zinc-500',
        };
    }

    /**
     * The next step in the linear sales pipeline (New → Contacted →
     * Qualified → Proposal Sent → Negotiation → Won), for the Kanban
     * board's one-click "advance" action. Won, Lost and Unqualified are
     * terminal — reached by a deliberate choice, not a "next" step — so
     * they have none.
     */
    public function nextInPipeline(): ?self
    {
        return match ($this) {
            self::New => self::Contacted,
            self::Contacted => self::Qualified,
            self::Qualified => self::ProposalSent,
            self::ProposalSent => self::Negotiation,
            self::Negotiation => self::Won,
            self::Won, self::Lost, self::Unqualified => null,
        };
    }
}
