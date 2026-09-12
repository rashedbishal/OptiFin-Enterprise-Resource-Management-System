<?php

namespace App\Enums;

use App\Models\Lead;
use App\Models\LeadStatusLog;

/**
 * The catalog of Lead attributes a scoring rule can evaluate. Deliberately a
 * small, extensible registry rather than a narrow hardcoded set — adding a
 * future field (e.g. "meeting scheduled") is one new case here plus one
 * resolve() branch, not a rule-schema change. Each case declares its
 * LeadScoringFieldKind (which constrains allowedOperators() and which input
 * widget the rule-builder UI shows) and how to read its current value off a
 * Lead (resolve()) — resolve() never throws; App\Services\LeadScoringService
 * treats a resolution failure the same as a non-match, never a fatal error.
 */
enum LeadScoringField: string
{
    case Priority = 'priority';
    case SourceType = 'source_type';
    case Status = 'status';
    case EstimatedBudget = 'estimated_budget';
    case DealValue = 'deal_value';
    case WinProbability = 'win_probability';
    case HasEmail = 'has_email';
    case HasPhone = 'has_phone';
    case DaysSinceCreated = 'days_since_created';
    case DaysSinceLastStatusChange = 'days_since_last_status_change';

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
            self::Priority => 'Priority',
            self::SourceType => 'Source Channel',
            self::Status => 'Status',
            self::EstimatedBudget => 'Estimated Budget',
            self::DealValue => 'Deal Value',
            self::WinProbability => 'Win Probability',
            self::HasEmail => 'Has Email',
            self::HasPhone => 'Has Phone',
            self::DaysSinceCreated => 'Days Since Created',
            self::DaysSinceLastStatusChange => 'Days Since Last Status Change',
        };
    }

    public function kind(): LeadScoringFieldKind
    {
        return match ($this) {
            self::Priority, self::SourceType, self::Status => LeadScoringFieldKind::Enum,
            self::EstimatedBudget, self::DealValue, self::WinProbability,
            self::DaysSinceCreated, self::DaysSinceLastStatusChange => LeadScoringFieldKind::Numeric,
            self::HasEmail, self::HasPhone => LeadScoringFieldKind::Boolean,
        };
    }

    /**
     * @return array<int, LeadScoringOperator>
     */
    public function allowedOperators(): array
    {
        return match ($this->kind()) {
            LeadScoringFieldKind::Numeric => [
                LeadScoringOperator::GreaterThanOrEqual,
                LeadScoringOperator::LessThanOrEqual,
                LeadScoringOperator::GreaterThan,
                LeadScoringOperator::LessThan,
                LeadScoringOperator::Equals,
                LeadScoringOperator::NotEquals,
            ],
            LeadScoringFieldKind::Enum => [
                LeadScoringOperator::Equals,
                LeadScoringOperator::NotEquals,
                LeadScoringOperator::In,
                LeadScoringOperator::NotIn,
            ],
            LeadScoringFieldKind::Boolean => [
                LeadScoringOperator::Equals,
            ],
        };
    }

    /**
     * This field's fixed set of selectable values for the rule-builder UI,
     * for Enum/Boolean-kind fields only — null for Numeric fields, which take
     * a free-form number instead. Source/Status intentionally offer every
     * catalog value (not just ones this tenant has configured/enabled): a
     * rule referencing an inapplicable value is inert, not invalid — see
     * LeadScoringService's "malformed/unsupported rule is safely skipped"
     * guarantee.
     *
     * value => label — matches x-dropdown-select's own associative-array
     * convention (resources/views/components/dropdown-select.blade.php:
     * its fallback branch treats the array KEY as the option's stored value
     * and the array VALUE as its display label). Priority/SourceType used
     * to build this backwards (label() => value), which silently bound
     * wire:model="value" to the enum's LABEL (e.g. "High") instead of its
     * value ("high") whenever a tenant picked an option from the dropdown —
     * LeadScoringOperator::normalize() then case-sensitively compared that
     * against Lead::priority->value ("high"), so an Equals/NotEquals rule
     * built through the UI could never match. HasEmail/HasPhone already
     * follow the correct value => label orientation (fixed for the same
     * bug once before, see LeadScoringRuleManagementTest) — this aligns
     * Priority/SourceType with them rather than leaving two different
     * orientations for the same UI component to trip over again later.
     *
     * @return array<string, string>|null value => label
     */
    public function valueOptions(): ?array
    {
        return match ($this) {
            self::Priority => collect(LeadPriority::cases())->mapWithKeys(fn (LeadPriority $p): array => [$p->value => $p->label()])->all(),
            self::SourceType => collect(LeadSourceType::cases())->mapWithKeys(fn (LeadSourceType $t): array => [$t->value => $t->label()])->all(),
            self::HasEmail, self::HasPhone => ['yes' => 'Yes', 'no' => 'No'],
            default => null,
        };
    }

    /**
     * This field's current value read off $lead — null means "nothing to
     * compare" (e.g. no email set), which LeadScoringOperator::evaluate()
     * and LeadScoringService both treat as a safe non-match, never an error.
     */
    public function resolve(Lead $lead): mixed
    {
        return match ($this) {
            self::Priority => $lead->priority->value,
            self::SourceType => $lead->source?->type->value,
            self::Status => $lead->status->slug,
            self::EstimatedBudget => $lead->estimated_budget !== null ? (float) $lead->estimated_budget : null,
            self::DealValue => $lead->deal_value !== null ? (float) $lead->deal_value : null,
            self::WinProbability => $lead->win_probability,
            self::HasEmail => $lead->email !== null,
            self::HasPhone => $lead->phone !== null,
            self::DaysSinceCreated => $lead->created_at === null ? null : (int) $lead->created_at->diffInDays(now()),
            self::DaysSinceLastStatusChange => $this->daysSinceLastStatusChange($lead),
        };
    }

    /**
     * Falls back to the lead's own created_at when it has never had a
     * status change logged — a lead that's sat in its initial status since
     * creation should still be eligible for "no movement in N days"-style
     * decay rules, not silently excluded for having no LeadStatusLog rows.
     */
    private function daysSinceLastStatusChange(Lead $lead): ?int
    {
        // Queried directly against LeadStatusLog rather than through
        // $lead->statusLogs(), so the relation's return type is a concrete
        // LeadStatusLog, not a generic Model — ->created_at then resolves
        // to a real Carbon property instead of an unknown one.
        $lastChange = LeadStatusLog::query()
            ->where('lead_id', $lead->id)
            ->latest('created_at')
            ->first()
            ?->created_at;

        $since = $lastChange ?? $lead->created_at;

        return $since === null ? null : (int) $since->diffInDays(now());
    }
}
