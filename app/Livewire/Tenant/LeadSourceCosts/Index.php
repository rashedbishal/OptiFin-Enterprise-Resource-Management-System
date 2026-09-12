<?php

namespace App\Livewire\Tenant\LeadSourceCosts;

use App\Enums\LeadSourceType;
use App\Models\Currency;
use App\Models\LeadSource;
use App\Models\LeadSourceCost;
use Flux\Flux;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Feature #12 — tenant self-service CRUD for recorded Lead Source cost
 * history. Client-Admin-only (see App\Policies\LeadSourceCostPolicy), same
 * "reuse the existing 'update' permission + isClientAdmin()" pattern as
 * Lead Scoring Rules/Lead Tags/Custom Fields. Mirrors those pages'
 * create/edit/save/confirmDelete/deleteConfirmed/delete shape.
 */
#[Title('Source Costs')]
class Index extends Component
{
    public bool $showModal = false;

    public ?string $editingId = null;

    public string $sourceId = '';

    /**
     * Bound to a native <input type="month">, e.g. "2026-09" — normalized
     * to that month's first day before persisting (Phase 1 is
     * calendar-month granularity only, no custom ranges).
     */
    public string $month = '';

    public string $amount = '';

    public string $currencyId = '';

    public bool $showDeleteModal = false;

    public ?string $confirmingDeleteId = null;

    public function mount(): void
    {
        // Whole page Client-Admin-gated, same precedent as
        // App\Livewire\Tenant\LeadCustomFields\Index::mount() — an entry
        // that can't be created here can't be usefully viewed either, since
        // this page has no separate read-only mode.
        Gate::authorize('viewAny', LeadSourceCost::class);
    }

    #[Computed]
    public function costs()
    {
        return LeadSourceCost::query()
            ->where('tenant_id', Auth::user()->tenant_id)
            ->with(['source', 'currency'])
            ->orderByDesc('month')
            ->get();
    }

    /**
     * Sources a cost entry can be recorded against — Manual and CsvImport
     * are lazily-provisioned internal attribution buckets, not real paid
     * channels, so they're excluded here the same way
     * Platform\Clients\LeadSources::typeOptions() already excludes both
     * from the source-creation type dropdown.
     *
     * @return Collection<int, LeadSource>
     */
    #[Computed]
    public function eligibleSources()
    {
        return LeadSource::query()
            ->where('tenant_id', Auth::user()->tenant_id)
            ->whereNotIn('type', [LeadSourceType::Manual, LeadSourceType::CsvImport])
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Currency>
     */
    #[Computed]
    public function tenantCurrencies()
    {
        return Auth::user()->tenant->currencies()->orderBy('code')->get();
    }

    public function create(): void
    {
        Gate::authorize('create', [LeadSourceCost::class, Auth::user()->tenant]);

        $this->reset('editingId', 'sourceId', 'month', 'amount', 'currencyId');
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function edit(string $id): void
    {
        $cost = LeadSourceCost::where('tenant_id', Auth::user()->tenant_id)->findOrFail($id);

        Gate::authorize('update', $cost);

        $this->editingId = $cost->id;
        $this->sourceId = $cost->lead_source_id;
        $this->month = $cost->month->format('Y-m');
        $this->amount = (string) $cost->amount;
        $this->currencyId = $cost->currency_id;
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function save(): void
    {
        $tenant = Auth::user()->tenant;

        $validated = $this->validate([
            'sourceId' => [
                'required',
                Rule::exists('lead_sources', 'id')->where('tenant_id', $tenant->id)->whereNotIn('type', [LeadSourceType::Manual->value, LeadSourceType::CsvImport->value]),
            ],
            'month' => ['required', 'date_format:Y-m'],
            'amount' => ['required', 'numeric', 'min:0', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'currencyId' => ['required', Rule::exists('tenant_currencies', 'currency_id')->where('tenant_id', $tenant->id)],
        ]);

        $month = Carbon::createFromFormat('Y-m', $validated['month'])->startOfMonth()->toDateString();

        // Everything from here on runs inside one transaction, locked on
        // the LeadSource row itself — never on an existing LeadSourceCost
        // row, which may not exist yet for a brand-new source's very first
        // entry (the case a lock on a cost row would miss entirely). This
        // is the same "lock a stable parent row to serialize concurrent
        // writes under it" idiom Lead::nextIdentifier() already uses
        // (locking the Tenant row to hand out sequential identifiers) — a
        // second concurrent save() for the SAME source (whether its first
        // entry or its fifth) blocks here until the first one commits,
        // then re-reads the now-current state before deciding anything, so
        // two concurrent "first entry" submissions can never both see "no
        // currency yet" and pick different ones.
        try {
            $error = DB::transaction(function () use ($tenant, $validated, $month): ?array {
                $source = LeadSource::where('tenant_id', $tenant->id)
                    ->whereNotIn('type', [LeadSourceType::Manual, LeadSourceType::CsvImport])
                    ->whereKey($validated['sourceId'])
                    ->lockForUpdate()
                    ->first();

                if ($source === null) {
                    return ['field' => 'sourceId', 'message' => __('This lead source is no longer available.')];
                }

                // Every entry for the same source must share one currency —
                // Phase 1 never blends or converts currencies, so a
                // source's cost history is kept single-currency by
                // construction rather than allowing a per-month currency
                // choice. Reading this AFTER the lock above (not before) is
                // what actually closes the race: a concurrent save() for
                // this same source is now guaranteed to either not have
                // started yet, or to have already committed.
                $existingCurrencyId = LeadSourceCost::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('lead_source_id', $source->id)
                    ->when($this->editingId, fn ($query) => $query->whereKeyNot($this->editingId))
                    ->value('currency_id');

                if ($existingCurrencyId !== null && $existingCurrencyId !== $validated['currencyId']) {
                    return ['field' => 'currencyId', 'message' => __('This source\'s cost history is already tracked in a different currency. Every entry for the same source must use the same currency.')];
                }

                // A range comparison (>=/<), never exact equality: SQLite
                // (the test driver) has no real DATE column type and stores
                // whatever string Eloquent's 'date' cast serializes
                // verbatim, including a trailing "00:00:00" — an exact
                // string match against a bare Y-m-d value silently fails
                // there even though it works on Postgres (production). See
                // the identical fix/reasoning in
                // LeadReportingService::sourceCostSummary().
                $monthTaken = LeadSourceCost::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('lead_source_id', $source->id)
                    ->where('month', '>=', $month)
                    ->where('month', '<', Carbon::parse($month)->addMonthNoOverflow()->toDateString())
                    ->when($this->editingId, fn ($query) => $query->whereKeyNot($this->editingId))
                    ->exists();

                if ($monthTaken) {
                    return ['field' => 'month', 'message' => __('A cost entry for this source and month already exists — edit it instead.')];
                }

                $attributes = [
                    'tenant_id' => $tenant->id,
                    'lead_source_id' => $source->id,
                    'month' => $month,
                    'amount' => $validated['amount'],
                    'currency_id' => $validated['currencyId'],
                ];

                if ($this->editingId) {
                    $cost = LeadSourceCost::where('tenant_id', $tenant->id)->findOrFail($this->editingId);
                    Gate::authorize('update', $cost);

                    $cost->update($attributes);
                } else {
                    Gate::authorize('create', [LeadSourceCost::class, $tenant]);

                    LeadSourceCost::create($attributes);
                }

                return null;
            });
        } catch (UniqueConstraintViolationException) {
            // Defensive backstop only, now effectively unreachable under
            // normal operation: the lock above already serializes every
            // writer for this source, so two transactions can no longer
            // race past the monthTaken check above. Kept because the real
            // unique(tenant_id, lead_source_id, month) constraint is the
            // ultimate source of truth regardless — belt and suspenders,
            // same reasoning LeadCustomFields\Index::save() documents for
            // its own equivalent catch.
            $this->addError('month', __('A cost entry for this source and month already exists — edit it instead.'));

            return;
        }

        if ($error !== null) {
            $this->addError($error['field'], $error['message']);

            return;
        }

        $this->showModal = false;
        unset($this->costs);
        Flux::toast(variant: 'success', text: __('Source cost saved.'));
    }

    public function confirmDelete(string $id): void
    {
        $this->confirmingDeleteId = $id;
        $this->showDeleteModal = true;
    }

    public function deleteConfirmed(): void
    {
        if ($this->confirmingDeleteId) {
            $this->delete($this->confirmingDeleteId);
        }

        $this->showDeleteModal = false;
        $this->confirmingDeleteId = null;
    }

    /**
     * A cost entry is a leaf row with no downstream dependents — unlike
     * Feature #11's custom field definitions, there's no "in use" state to
     * block against; deleting one just removes that month's data point.
     */
    public function delete(string $id): void
    {
        $cost = LeadSourceCost::where('tenant_id', Auth::user()->tenant_id)->findOrFail($id);

        Gate::authorize('delete', $cost);

        $cost->delete();

        unset($this->costs);
        Flux::toast(variant: 'success', text: __('Source cost deleted.'));
    }
}
