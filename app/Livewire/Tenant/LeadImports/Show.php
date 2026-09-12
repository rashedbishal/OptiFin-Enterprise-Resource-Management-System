<?php

namespace App\Livewire\Tenant\LeadImports;

use App\Models\Lead;
use App\Models\LeadImport;
use Illuminate\Contracts\View\View;
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
        return view('livewire.tenant.lead-imports.show', [
            'rows' => $this->rows(),
        ]);
    }
}
