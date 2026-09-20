<?php

namespace App\Livewire\Tenant\LeadImports;

use App\Enums\LeadImportRowStatus;
use App\Models\Lead;
use App\Models\LeadImport;
use App\Models\LeadImportRow;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * One CSV import's row-by-row results. Tenant isolation is explicit
 * (never relies on BelongsToTenant alone) — a cross-tenant import id must
 * 404, not leak another tenant's import history.
 */
#[Title('Import Details')]
class Show extends Component
{
    use WithPagination;

    public LeadImport $leadImport;

    public function mount(LeadImport $leadImport): void
    {
        Gate::authorize('create', Lead::class);

        abort_unless($leadImport->tenant_id === Auth::user()->tenant_id, 404);

        $this->leadImport = $leadImport;
    }

    public function rows()
    {
        return $this->leadImport->rows()
            ->with(['lead', 'duplicateOfLead'])
            ->orderBy('row_number')
            ->paginate(50);
    }

    /**
     * Reverb is the primary transport for the status badge (see
     * App\Events\LeadImportStatusChanged, fired at the import's two
     * terminal finalization points) — this just re-queries on receipt,
     * same as every other Echo listener in this app (NotificationBell,
     * Leads\Index). The Blade view's slow wire:poll is a reconciliation
     * fallback for a missed broadcast, not the primary mechanism.
     *
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        return [
            'echo-private:lead-import.'.$this->leadImport->id.',.lead-import.status-changed' => 'refreshStatus',
        ];
    }

    public function refreshStatus(): void
    {
        $this->leadImport->refresh();
    }

    public function render(): View
    {
        $rows = $this->rows();

        return view('livewire.tenant.lead-imports.show', [
            'rows' => $rows,
            'partialMatches' => $this->partialMatches($rows->getCollection()),
        ]);
    }

    /**
     * INC-1 C — makes a partial match understandable on the row list: for a
     * Duplicate row that is linked to an existing lead (duplicate_of_lead_id),
     * says which incoming identity field(s) matched that lead and which
     * incoming identity value did NOT. Purely derived at render time from
     * data rows() already eager-loads (the row's own normalized values and
     * the linked lead's normalized values) — no query, no persisted state,
     * and nothing about the existing lead is ever returned or shown: only
     * the labels and the incoming row's own unmatched value.
     *
     * Rows with no linked lead (Imported, Failed, in-file duplicates, and
     * A1/existing-lead conflict rows, whose stored message is already
     * authoritative) get no entry. So does a linked row whose lead's email
     * and phone no longer match either incoming value (edited since the
     * import): no label is better than a misleading one.
     *
     * @param  Collection<int, LeadImportRow>  $rows
     * @return array<string, array{matched: array<int, string>, unmatched: array{field: string, value: string}|null}>
     */
    private function partialMatches(Collection $rows): array
    {
        $matches = [];

        foreach ($rows as $row) {
            $lead = $row->duplicateOfLead;

            if ($row->status !== LeadImportRowStatus::Duplicate || $lead === null) {
                continue;
            }

            $emailMatched = $row->normalized_email !== null && $row->normalized_email === $lead->email_normalized;
            $phoneMatched = $row->normalized_phone !== null && $row->normalized_phone === $lead->phone_normalized;

            if (! $emailMatched && ! $phoneMatched) {
                continue;
            }

            $unmatched = null;

            if ($emailMatched && ! $phoneMatched && $row->normalized_phone !== null) {
                $unmatched = ['field' => 'phone', 'value' => $this->incomingValue($row, 'phone', $row->normalized_phone)];
            } elseif ($phoneMatched && ! $emailMatched && $row->normalized_email !== null) {
                $unmatched = ['field' => 'email', 'value' => $this->incomingValue($row, 'email', $row->normalized_email)];
            }

            $matches[$row->id] = [
                'matched' => array_values(array_filter([$emailMatched ? 'email' : null, $phoneMatched ? 'phone' : null])),
                'unmatched' => $unmatched,
            ];
        }

        return $matches;
    }

    /**
     * The value exactly as it appeared in the CSV row, falling back to the
     * normalized value the row also stores.
     */
    private function incomingValue(LeadImportRow $row, string $key, string $fallback): string
    {
        $raw = $row->raw_data[$key] ?? null;

        return is_string($raw) && trim($raw) !== '' ? trim($raw) : $fallback;
    }
}
