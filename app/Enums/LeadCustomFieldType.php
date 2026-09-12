<?php

namespace App\Enums;

use Illuminate\Validation\Rule;

/**
 * Feature #11 Phase 1's fixed set of supported custom field types —
 * deliberately small (no multi-select/checkbox), matching the same
 * "start narrow, extend the enum later" approach LeadScoringField/
 * LeadScoringOperator already validated twice. Kept separate from those two
 * enums: this describes the shape of a tenant-defined value slot, not how to
 * evaluate an existing Lead column against a rule.
 */
enum LeadCustomFieldType: string
{
    case Text = 'text';
    case Number = 'number';
    case Date = 'date';
    case Select = 'select';

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
            self::Text => 'Text',
            self::Number => 'Number',
            self::Date => 'Date',
            self::Select => 'Single Select',
        };
    }

    /**
     * The Laravel validation rules a value submitted for a field of this
     * type/required-ness/options must satisfy — the single source of truth
     * for both the manual-create form and the Lead detail edit form, so the
     * two can never silently drift apart. $options is only meaningful for
     * Select (Rule::in($options ?? [])); ignored for every other type.
     *
     * @param  array<int, string>|null  $options
     * @return array<int, mixed>
     */
    public function validationRules(bool $required, ?array $options = null): array
    {
        $presence = $required ? 'required' : 'nullable';

        return match ($this) {
            self::Text => [$presence, 'string', 'max:255'],
            self::Number => [$presence, 'numeric', 'regex:/^-?\d{1,12}(\.\d{1,2})?$/'],
            self::Date => [$presence, 'date'],
            self::Select => [$presence, Rule::in($options ?? [])],
        };
    }
}
