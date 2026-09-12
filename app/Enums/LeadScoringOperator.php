<?php

namespace App\Enums;

enum LeadScoringOperator: string
{
    case GreaterThanOrEqual = '>=';
    case LessThanOrEqual = '<=';
    case GreaterThan = '>';
    case LessThan = '<';
    case Equals = '=';
    case NotEquals = '!=';
    case In = 'in';
    case NotIn = 'not_in';

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
            self::GreaterThanOrEqual => 'is at least',
            self::LessThanOrEqual => 'is at most',
            self::GreaterThan => 'is more than',
            self::LessThan => 'is less than',
            self::Equals => 'is',
            self::NotEquals => 'is not',
            self::In => 'is one of',
            self::NotIn => 'is not one of',
        };
    }

    /**
     * Evaluates $actual (the field's current value, resolved off a Lead by
     * LeadScoringField::resolve()) against $expected (the rule's configured
     * `value`). Never throws — an operator/value combination that doesn't
     * make sense for the given types (e.g. a numeric comparison against a
     * non-numeric value) simply evaluates to false rather than erroring, so
     * a malformed rule is skipped by LeadScoringService, not fatal to it.
     */
    public function evaluate(mixed $actual, mixed $expected): bool
    {
        return match ($this) {
            self::GreaterThanOrEqual => is_numeric($actual) && is_numeric($expected) && (float) $actual >= (float) $expected,
            self::LessThanOrEqual => is_numeric($actual) && is_numeric($expected) && (float) $actual <= (float) $expected,
            self::GreaterThan => is_numeric($actual) && is_numeric($expected) && (float) $actual > (float) $expected,
            self::LessThan => is_numeric($actual) && is_numeric($expected) && (float) $actual < (float) $expected,
            // Numeric operands compare numerically (1500 must equal 1500.0
            // regardless of how each side's original string/int/float
            // representation differs), never lexicographically like the
            // Enum/Boolean-kind fallback below — the same is_numeric() bare
            // gate the four inequality operators above already use.
            self::Equals => is_numeric($actual) && is_numeric($expected)
                ? (float) $actual === (float) $expected
                : self::normalize($actual) === self::normalize($expected),
            self::NotEquals => is_numeric($actual) && is_numeric($expected)
                ? (float) $actual !== (float) $expected
                : self::normalize($actual) !== self::normalize($expected),
            self::In => is_array($expected) && in_array(self::normalize($actual), array_map(self::normalize(...), $expected), true),
            self::NotIn => is_array($expected) && ! in_array(self::normalize($actual), array_map(self::normalize(...), $expected), true),
        };
    }

    /**
     * Reduces $value to a string for Enum/Boolean-kind (non-numeric)
     * equality — never called with an array by evaluate() itself (In/NotIn
     * pre-filter with is_array($expected) before mapping this over each
     * element, and Equals/NotEquals only reach this branch for a
     * non-numeric operand), but guarded anyway: a hand-edited/malformed
     * rule value that somehow ends up an array here would otherwise trip
     * PHP's "Array to string conversion" warning on the bare (string) cast
     * below. Returning null instead makes it a clean non-match, consistent
     * with every other "can't be evaluated" case in this class.
     */
    private static function normalize(mixed $value): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }
}
