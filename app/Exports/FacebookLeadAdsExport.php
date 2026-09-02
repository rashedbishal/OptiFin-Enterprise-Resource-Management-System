<?php

namespace App\Exports;

use App\Models\Lead;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Mirrors the columns shown on the Facebook Lead Ads spreadsheet view
 * (`App\Livewire\Tenant\Leads\SourceLeads`): the lead's mapped name, then
 * one column per distinct Instant Form field name found across the
 * exported leads, then the fixed Form/Status/Submitted columns.
 *
 * @implements FromCollection<int, Lead>
 */
class FacebookLeadAdsExport implements FromCollection, WithHeadings, WithMapping
{
    /**
     * @param  Collection<int, Lead>  $leads
     * @param  array<int, string>  $fieldColumns
     */
    public function __construct(
        private readonly Collection $leads,
        private readonly array $fieldColumns,
    ) {}

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
            ...['Name'],
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
            ...[$lead->name],
            ...array_map(fn (string $column): ?string => $fieldValues[$column] ?? null, $this->fieldColumns),
            ...[
                $lead->source_data['form_name'] ?? null,
                $lead->status->label(),
                $lead->created_at?->toDateTimeString(),
            ],
        ];
    }
}
