<?php

namespace App\Livewire\Platform\LeadManagement;

use App\Models\Currency;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\LeadSourceCost;
use App\Models\LeadStatus;
use Flux\Flux;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Platform-wide configuration for the Lead Management module: the Lead
 * Status pipeline catalog (was its own /admin/lead-statuses page) and the
 * Currency catalog every tenant's Est. Budget / Deal Value fields pick
 * from — combined here since both are the same shape of thing (a global
 * catalog every tenant draws a subset from) and both belong to the same
 * module's configuration, reached the same way (Modules index's
 * "Configuration" button — see LeadStatusPolicy/CurrencyPolicy, both
 * gated by 'module-management', not 'lead-management': this is a Platform
 * Admin master-data page, not a tenant-scope one).
 */
#[Title('Lead Management Settings')]
#[Layout('layouts.admin')]
class Settings extends Component
{
    public bool $showStatusModal = false;

    public ?string $editingStatusId = null;

    public string $statusName = '';

    public string $statusColor = 'blue';

    public bool $statusIsClosed = false;

    public bool $showCurrencyModal = false;

    public ?string $editingCurrencyId = null;

    public string $currencyCode = '';

    public string $currencyName = '';

    public bool $showDeleteStatusModal = false;

    public ?string $confirmingDeleteStatusId = null;

    public bool $showDeleteCurrencyModal = false;

    public ?string $confirmingDeleteCurrencyId = null;

    /**
     * Starts false on every fresh mount so the very first paint can skip
     * both statuses()/currencies() queries and show skeletons instead —
     * same technique as App\Livewire\Tenant\Leads\Index::$initialLoadComplete,
     * scaled down: this page has no filters at all, so completeInitialLoad()
     * (fired via wire:init) is the whole $listLoadingTargets for both
     * tables.
     */
    public bool $initialLoadComplete = false;

    public function mount(): void
    {
        Gate::forUser(Auth::guard('platform')->user())->authorize('viewAny', LeadStatus::class);
        Gate::forUser(Auth::guard('platform')->user())->authorize('viewAny', Currency::class);
    }

    public function completeInitialLoad(): void
    {
        $this->initialLoadComplete = true;
    }

    #[Computed]
    public function statuses()
    {
        if (! $this->initialLoadComplete) {
            return LeadStatus::query()->whereRaw('1 = 0')->get();
        }

        return LeadStatus::query()->orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * @return array<int, string>
     */
    public function colorOptions(): array
    {
        return LeadStatus::availableColors();
    }

    public function createStatus(): void
    {
        Gate::forUser(Auth::guard('platform')->user())->authorize('create', LeadStatus::class);

        $this->reset('editingStatusId', 'statusName', 'statusIsClosed');
        $this->statusColor = 'blue';
        $this->showStatusModal = true;
    }

    public function editStatus(string $id): void
    {
        $status = LeadStatus::findOrFail($id);

        Gate::forUser(Auth::guard('platform')->user())->authorize('update', $status);

        $this->editingStatusId = $status->id;
        $this->statusName = $status->name;
        $this->statusColor = $status->color;
        $this->statusIsClosed = $status->is_closed;
        $this->showStatusModal = true;
    }

    public function saveStatus(): void
    {
        $actor = Auth::guard('platform')->user();

        $validated = $this->validate([
            'statusName' => ['required', 'string', 'max:255'],
            'statusColor' => ['required', Rule::in(LeadStatus::availableColors())],
            'statusIsClosed' => ['boolean'],
        ]);

        $slug = Str::slug($validated['statusName']);

        $slugTaken = LeadStatus::query()
            ->where('slug', $slug)
            ->when($this->editingStatusId, fn ($query) => $query->whereKeyNot($this->editingStatusId))
            ->exists();

        if ($slugTaken) {
            $this->addError('statusName', __('A status with this name already exists.'));

            return;
        }

        // The slugTaken check above and this insert/update aren't atomic —
        // two admins submitting the same name at the exact same moment
        // could both pass that check. slug has a real unique() constraint
        // (see the migration), so the race can't produce a duplicate row,
        // but without this catch the loser would get an uncaught
        // UniqueConstraintViolationException (a raw 500) instead of the
        // same graceful validation error the check above was meant to give
        // everyone.
        try {
            if ($this->editingStatusId) {
                $status = LeadStatus::findOrFail($this->editingStatusId);
                Gate::forUser($actor)->authorize('update', $status);

                $status->update([
                    'name' => $validated['statusName'],
                    'slug' => Str::slug($validated['statusName']),
                    'color' => $validated['statusColor'],
                    'is_closed' => $validated['statusIsClosed'],
                ]);
            } else {
                Gate::forUser($actor)->authorize('create', LeadStatus::class);

                LeadStatus::create([
                    'name' => $validated['statusName'],
                    'slug' => Str::slug($validated['statusName']),
                    'color' => $validated['statusColor'],
                    'is_closed' => $validated['statusIsClosed'],
                    'sort_order' => LeadStatus::query()->max('sort_order') + 1,
                ]);
            }
        } catch (UniqueConstraintViolationException) {
            $this->addError('statusName', __('A status with this name already exists.'));

            return;
        }

        $this->showStatusModal = false;
        Flux::toast(variant: 'success', text: __('Lead status saved.'));
    }

    public function confirmDeleteStatus(string $id): void
    {
        $this->confirmingDeleteStatusId = $id;
        $this->showDeleteStatusModal = true;
    }

    public function deleteStatusConfirmed(): void
    {
        if ($this->confirmingDeleteStatusId) {
            $this->deleteStatus($this->confirmingDeleteStatusId);
        }

        $this->showDeleteStatusModal = false;
        $this->confirmingDeleteStatusId = null;
    }

    public function deleteStatus(string $id): void
    {
        $status = LeadStatus::findOrFail($id);

        Gate::forUser(Auth::guard('platform')->user())->authorize('delete', $status);

        if (Lead::where('status_id', $status->id)->exists() || DB::table('tenant_lead_statuses')->where('lead_status_id', $status->id)->exists()) {
            Flux::toast(variant: 'danger', text: __('This status is in use by a client and cannot be deleted.'));

            return;
        }

        $status->delete();

        Flux::toast(variant: 'success', text: __('Lead status deleted.'));
    }

    #[Computed]
    public function currencies()
    {
        if (! $this->initialLoadComplete) {
            return Currency::query()->whereRaw('1 = 0')->get();
        }

        return Currency::query()->orderBy('code')->get();
    }

    public function createCurrency(): void
    {
        Gate::forUser(Auth::guard('platform')->user())->authorize('create', Currency::class);

        $this->reset('editingCurrencyId', 'currencyCode', 'currencyName');
        $this->showCurrencyModal = true;
    }

    public function editCurrency(string $id): void
    {
        $currency = Currency::findOrFail($id);

        Gate::forUser(Auth::guard('platform')->user())->authorize('update', $currency);

        $this->editingCurrencyId = $currency->id;
        $this->currencyCode = $currency->code;
        $this->currencyName = $currency->name;
        $this->showCurrencyModal = true;
    }

    public function saveCurrency(): void
    {
        $actor = Auth::guard('platform')->user();

        $validated = $this->validate([
            'currencyCode' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z]+$/'],
            'currencyName' => ['required', 'string', 'max:255'],
        ], [
            'currencyCode.regex' => __('Currency code may only contain letters.'),
        ]);

        $code = Str::upper($validated['currencyCode']);

        $codeTaken = Currency::query()
            ->where('code', $code)
            ->when($this->editingCurrencyId, fn ($query) => $query->whereKeyNot($this->editingCurrencyId))
            ->exists();

        if ($codeTaken) {
            $this->addError('currencyCode', __('A currency with this code already exists.'));

            return;
        }

        // See the matching comment in saveStatus(): the codeTaken check
        // above and this insert/update aren't atomic, and code has a real
        // unique() constraint — this turns the race's failure mode from an
        // uncaught UniqueConstraintViolationException into the same
        // graceful validation error the check above was meant to give
        // everyone.
        try {
            if ($this->editingCurrencyId) {
                $currency = Currency::findOrFail($this->editingCurrencyId);
                Gate::forUser($actor)->authorize('update', $currency);

                $currency->update(['code' => $code, 'name' => $validated['currencyName']]);
            } else {
                Gate::forUser($actor)->authorize('create', Currency::class);

                Currency::create(['code' => $code, 'name' => $validated['currencyName']]);
            }
        } catch (UniqueConstraintViolationException) {
            $this->addError('currencyCode', __('A currency with this code already exists.'));

            return;
        }

        $this->showCurrencyModal = false;
        Flux::toast(variant: 'success', text: __('Currency saved.'));
    }

    public function confirmDeleteCurrency(string $id): void
    {
        $this->confirmingDeleteCurrencyId = $id;
        $this->showDeleteCurrencyModal = true;
    }

    public function deleteCurrencyConfirmed(): void
    {
        if ($this->confirmingDeleteCurrencyId) {
            $this->deleteCurrency($this->confirmingDeleteCurrencyId);
        }

        $this->showDeleteCurrencyModal = false;
        $this->confirmingDeleteCurrencyId = null;
    }

    public function deleteCurrency(string $id): void
    {
        $currency = Currency::findOrFail($id);

        Gate::forUser(Auth::guard('platform')->user())->authorize('delete', $currency);

        $inUseByLead = Lead::where('estimated_budget_currency_id', $currency->id)
            ->orWhere('deal_value_currency_id', $currency->id)
            ->exists();

        // Feature #4 (Deal / Opportunity): a currency referenced only by a
        // Deal (never by any Lead) must block deletion too, or that Deal's
        // currency_id would be silently null-on-deleted out from under it.
        $inUseByDeal = Deal::where('currency_id', $currency->id)->exists();

        // Feature #12: a currency referenced only by a recorded source cost
        // entry (never by any Lead or Deal) must block deletion too, or
        // that entry's currency_id would be silently null-on-deleted out
        // from under it — same reasoning Feature #4 already established
        // for $inUseByDeal above.
        $inUseByLeadSourceCost = LeadSourceCost::where('currency_id', $currency->id)->exists();

        if ($inUseByLead || $inUseByDeal || $inUseByLeadSourceCost || DB::table('tenant_currencies')->where('currency_id', $currency->id)->exists()) {
            Flux::toast(variant: 'danger', text: __('This currency is in use by a client and cannot be deleted.'));

            return;
        }

        $currency->delete();

        Flux::toast(variant: 'success', text: __('Currency deleted.'));
    }
}
