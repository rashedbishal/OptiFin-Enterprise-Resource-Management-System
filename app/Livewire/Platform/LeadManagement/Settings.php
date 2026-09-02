<?php

namespace App\Livewire\Platform\LeadManagement;

use App\Models\Currency;
use App\Models\Lead;
use App\Models\LeadStatus;
use Flux\Flux;
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

    public function mount(): void
    {
        Gate::forUser(Auth::guard('platform')->user())->authorize('viewAny', LeadStatus::class);
        Gate::forUser(Auth::guard('platform')->user())->authorize('viewAny', Currency::class);
    }

    #[Computed]
    public function statuses()
    {
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

        $this->showStatusModal = false;
        Flux::toast(variant: 'success', text: __('Lead status saved.'));
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

        if ($this->editingCurrencyId) {
            $currency = Currency::findOrFail($this->editingCurrencyId);
            Gate::forUser($actor)->authorize('update', $currency);

            $currency->update(['code' => $code, 'name' => $validated['currencyName']]);
        } else {
            Gate::forUser($actor)->authorize('create', Currency::class);

            Currency::create(['code' => $code, 'name' => $validated['currencyName']]);
        }

        $this->showCurrencyModal = false;
        Flux::toast(variant: 'success', text: __('Currency saved.'));
    }

    public function deleteCurrency(string $id): void
    {
        $currency = Currency::findOrFail($id);

        Gate::forUser(Auth::guard('platform')->user())->authorize('delete', $currency);

        $inUseByLead = Lead::where('estimated_budget_currency_id', $currency->id)
            ->orWhere('deal_value_currency_id', $currency->id)
            ->exists();

        if ($inUseByLead || DB::table('tenant_currencies')->where('currency_id', $currency->id)->exists()) {
            Flux::toast(variant: 'danger', text: __('This currency is in use by a client and cannot be deleted.'));

            return;
        }

        $currency->delete();

        Flux::toast(variant: 'success', text: __('Currency deleted.'));
    }
}
