<?php

namespace App\Exports;

use App\Models\Lead;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;

/**
 * Mirrors the columns shown on the Facebook Lead Ads spreadsheet view
 * (`App\Livewire\Tenant\Leads\SourceLeads`): the lead's mapped name, then
 * one column per distinct Instant Form field name found across the
 * exported leads, then the fixed Form/Status/Submitted columns.
 *
 * WithCustomValueBinder: every value here (the lead's name, and every
 * dynamic Instant Form field value) ultimately comes from a public Meta
 * Instant Form submission — fully attacker-controlled, never sanitized at
 * capture time (see Lead::leadAdFieldValues()/LeadWebhookController's
 * mapLeadgenFieldData(), neither of which this export touches). Without
 * this, PhpSpreadsheet's own DefaultValueBinder classifies any value
 * starting with "=" that parses as a valid formula as DataType::TYPE_FORMULA
 * and writes it into the XLSX as a real, executable formula (verified
 * directly against the installed phpoffice/phpspreadsheet source, not
 * assumed) — e.g. a submitted name of
 * '=HYPERLINK("http://evil.example/","Click here")' would open as a live
 * formula for whoever downloads and opens this export. Delegating to
 * PhpSpreadsheet's own StringValueBinder (used here with its default,
 * unconfigured settings) forces every cell to DataType::TYPE_STRING
 * unconditionally, regardless of a leading =, +, -, or @ — the exact
 * original text is preserved, only its cell type changes. Every value this
 * export currently writes is already a plain string (see map()'s own
 * return type), so this has no effect on any legitimate exported value.
 *
 * @implements FromCollection<int, Lead>
 */
class FacebookLeadAdsExport implements FromCollection, WithCustomValueBinder, WithHeadings, WithMapping
{
    private readonly StringValueBinder $valueBinder;

    /**
     * @param  Collection<int, Lead>  $leads
     * @param  array<int, string>  $fieldColumns
     */
    public function __construct(
        private readonly Collection $leads,
        private readonly array $fieldColumns,
    ) {
        $this->valueBinder = new StringValueBinder;
    }

    public function bindValue(Cell $cell, mixed $value): bool
    {
        return $this->valueBinder->bindValue($cell, $value);
    }

    /**
     * @return Collection<int, Lead>
     */
    public function collection(): Collection
    {
        return $this->leads;
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            ...['Lead ID', 'Name'],
            ...array_map(fn (string $column): string => ucwords(str_replace('_', ' ', $column)), $this->fieldColumns),
            ...['Form', 'Status', 'Submitted At'],
        ];
    }

    /**
     * @param  Lead  $lead
     * @return array<int, string|null>
     */
    public function map($lead): array
    {
        $fieldValues = $lead->leadAdFieldValues();

        return [
            ...[$lead->identifier, $lead->name],
            ...array_map(fn (string $column): ?string => $fieldValues[$column] ?? null, $this->fieldColumns),
            ...[
                $lead->source_data['form_name'] ?? null,
                $lead->status->label(),
                $lead->created_at?->toDateTimeString(),
            ],
        ];
    }
}
