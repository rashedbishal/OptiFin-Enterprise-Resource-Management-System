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
