<?php

namespace App\Livewire\Tenant\Leads;

use App\Enums\LeadSourceType;
use App\Exports\FacebookLeadAdsExport;
use App\Livewire\Tenant\Leads\Concerns\FiltersLeadsQuery;
use App\Models\Lead;
use App\Models\LeadSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The "excel-looking" spreadsheet view for a single Facebook Lead Ads
 * source — every other lead source type still lists individual leads as
 * rows in the main Leads index; this one shows a table whose columns are
 * whatever the connected Instant Form(s) actually asked, since submissions
 * carry no fixed shape the way a conversational Messenger/WhatsApp lead
 * does. See `Index::facebookLeadAdsSources()` for the aggregate row this
 * page is linked from.
 */
#[Title('Facebook Lead Ads')]
class SourceLeads extends Component
{
    use FiltersLeadsQuery, WithPagination;

    public LeadSource $leadSource;

    /**
     * Starts false on every fresh mount so the very first paint can skip
     * leads()'s actual row query and show the skeleton immediately — same
     * reasoning and wire:init trigger as Index::$initialLoadComplete.
     * Deliberately does NOT also gate fieldColumns() (used to build both
     * the real header's dynamic Instant Form columns and the skeleton's
     * matching cell count): fieldColumns() needs to return the true field
     * set from the start so the header's column count is stable before and
     * after loading, rather than growing once real data arrives.
     */
    public bool $initialLoadComplete = false;

    public function mount(LeadSource $leadSource): void
    {
        Gate::authorize('viewAny', Lead::class);

        abort_unless($leadSource->type === LeadSourceType::FacebookLeadAds, 404);

        $this->leadSource = $leadSource;
    }

    public function completeInitialLoad(): void
    {
        $this->initialLoadComplete = true;
    }

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        return [
            'echo-private:tenant.'.Auth::user()->tenant_id.',.lead.received' => '$refresh',
            'echo-private:tenant.'.Auth::user()->tenant_id.',.lead.updated' => '$refresh',
        ];
    }

    public function updating(): void
    {
        $this->resetPage();
        unset($this->leads);
    }

    public function updated(): void
    {
        $this->resetPage();
        unset($this->leads);
    }

    #[Computed]
    public function leads()
    {
        if (! $this->initialLoadComplete) {
            return Lead::query()->whereRaw('1 = 0')->paginate(25);
        }

        return $this->applyLeadIdSearch($this->applyDropdownFilters($this->baseQuery()))
            ->with(['assignedUser', 'status'])
            ->latest()
            ->paginate(25);
    }

    /**
     * The union of every distinct Instant Form field name submitted through
     * this source, in first-seen order. Computed from every lead this user
     * is allowed to see (ignoring the status/priority/assigned-to dropdowns
     * below) so columns stay stable as those filters change instead of
     * appearing and disappearing.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function fieldColumns(): array
    {
        $names = [];

        foreach ($this->baseQuery()->get(['id', 'source_data', 'source_id', 'tenant_id']) as $lead) {
            foreach (array_keys($lead->leadAdFieldValues()) as $name) {
                if (! in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }
        }

        return $names;
    }

    /**
     * Exports exactly what the table on screen shows: the same
     * status/priority/assigned-to filters, in the same column order, just
     * unpaginated. Re-authorizes independently of `mount()` since this is a
     * separate Livewire action call on an already-hydrated component.
     */
    public function downloadXlsx(): BinaryFileResponse
    {
        Gate::authorize('viewAny', Lead::class);

        $leads = $this->applyLeadIdSearch($this->applyDropdownFilters($this->baseQuery()))->with('status')->latest()->get();

        $filename = Str::slug($this->leadSource->name).'-'.now()->format('Y-m-d_His').'.xlsx';

        return Excel::download(new FacebookLeadAdsExport($leads, $this->fieldColumns()), $filename);
    }

    /**
     * @return Builder<Lead>
     */
    private function baseQuery(): Builder
    {
        return $this->applyAssignmentScope(
            // whereNull('merged_into_lead_id'): keeps a lead merged into
            // another one (see App\Services\LeadMergeService) out of this
            // spreadsheet view and its export, same as Index::filteredQuery().
            Lead::query()->where('source_id', $this->leadSource->id)->whereNull('merged_into_lead_id')
        );
    }

    /**
     * Unlike the main Leads list's applySearchFilter() (matches name, phone,
     * email, company, location, win probability, and deal value too), this
     * matches Lead ID only — Facebook Lead Ads submissions carry no fixed
     * contact-form shape, so those other columns aren't reliably populated,
     * and the dynamic Instant Form fields shown as this page's columns have
     * no consistent set of names to search across either. LOWER() rather
     * than relying on driver/collation defaults for case-insensitivity, same
     * reasoning as applySearchFilter().
     *
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    private function applyLeadIdSearch(Builder $query): Builder
    {
        if ($this->search === '') {
            return $query;
        }

        $term = '%'.self::escapeLikeValue(mb_strtolower($this->search)).'%';

        return $query->whereRaw('LOWER(identifier) LIKE ? ESCAPE '.self::LIKE_ESCAPE_LITERAL, [$term]);
    }
}
