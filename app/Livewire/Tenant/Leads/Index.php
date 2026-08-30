<?php

namespace App\Livewire\Tenant\Leads;

use App\Enums\LeadSourceType;
use App\Livewire\Tenant\Leads\Concerns\FiltersLeadsQuery;
use App\Models\Lead;
use App\Models\LeadSource;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Title('Leads')]
class Index extends Component
{
    use FiltersLeadsQuery, WithPagination;

    public string $sourceType = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', Lead::class);
    }

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        return [
            'echo-private:tenant.'.Auth::user()->tenant_id.',.lead.received' => '$refresh',
        ];
    }

    public function updating(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function leads()
    {
        return $this->applyDropdownFilters($this->applyAssignmentScope($this->filteredQuery()))
            ->with(['source', 'assignedUser'])
            ->latest()
            ->paginate(25);
    }

    /**
     * Facebook Lead Ads submissions don't get a row per lead here like every
     * other source — they'd flood this list, and an Instant Form submission
     * has a very different shape (dynamic per-form fields) than a
     * conversational lead. Instead each Facebook Lead Ads source gets one
     * aggregate row, always shown regardless of the status/priority/
     * assigned-to dropdowns above (they represent many leads with a mix of
     * statuses/assignees, so those filters don't apply), linking through to
     * a dedicated spreadsheet view. Still respects assignment-based
     * visibility for non-admin agents: a source only appears for them if
     * they have at least one lead assigned to them from it.
     */
    #[Computed]
    public function facebookLeadAdsSources()
    {
        $user = Auth::user();

        $query = LeadSource::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('type', LeadSourceType::FacebookLeadAds);

        if ($this->isClientAdmin) {
            $query->withCount('leads')->withMax('leads', 'created_at');
        } else {
            $query
                ->withCount(['leads as leads_count' => fn (Builder $q) => $q->where('assigned_to', $user->id)])
                ->withMax(['leads as leads_max_created_at' => fn (Builder $q) => $q->where('assigned_to', $user->id)], 'created_at')
                ->whereHas('leads', fn (Builder $q) => $q->where('assigned_to', $user->id));
        }

        return $query->orderBy('name')->get();
    }

    public function delete(string $id): void
    {
        $lead = Lead::where('tenant_id', Auth::user()->tenant_id)->findOrFail($id);

        Gate::authorize('delete', $lead);

        $lead->delete();

        Flux::toast(variant: 'success', text: __('Lead deleted.'));
    }

    /**
     * Removes an empty Facebook Lead Ads source directly from this page —
     * the one tenant-side exception to Lead Sources otherwise being
     * Platform-Admin-managed (see LeadSourcePolicy::delete()). Only reaches
     * a source with zero leads in practice: the "Delete" button in the view
     * is only rendered when `leads_count` is 0, but the count is re-checked
     * here too since it's a data-state guard, not something the policy
     * itself encodes.
     */
    public function deleteSource(string $id): void
    {
        $source = LeadSource::where('tenant_id', Auth::user()->tenant_id)
            ->where('type', LeadSourceType::FacebookLeadAds)
            ->findOrFail($id);

        Gate::authorize('delete', $source);

        if ($source->leads()->exists()) {
            Flux::toast(variant: 'danger', text: __('This source has leads and can no longer be deleted.'));

            return;
        }

        $source->delete();

        Flux::toast(variant: 'success', text: __('Lead source deleted.'));
    }

    public function export(): StreamedResponse
    {
        Gate::authorize('viewAny', Lead::class);

        $leads = $this->applyDropdownFilters($this->applyAssignmentScope($this->filteredQuery()))
            ->with(['source', 'assignedUser'])
            ->get();

        return response()->streamDownload(function () use ($leads): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                throw new RuntimeException('Unable to open output stream.');
            }

            fputcsv($handle, ['Name', 'Email', 'Phone', 'Source', 'Status', 'Priority', 'Assigned To', 'Created At', 'Closed At']);

            foreach ($leads as $lead) {
                fputcsv($handle, [
                    $lead->name,
                    $lead->email,
                    $lead->phone,
                    $lead->source->type->label(),
                    $lead->status->value,
                    $lead->priority->value,
                    $lead->assignedUser->name ?? 'Unassigned',
                    $lead->created_at?->toDateTimeString(),
                    $lead->closed_at?->toDateTimeString(),
                ]);
            }

            fclose($handle);
        }, 'leads.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * @return Builder<Lead>
     */
    private function filteredQuery(): Builder
    {
        $user = Auth::user();

        $query = Lead::query()
            ->where('tenant_id', $user->tenant_id)
            ->whereHas('source', fn (Builder $q) => $q->where('type', '!=', LeadSourceType::FacebookLeadAds));

        if ($this->sourceType !== '') {
            $sourceType = $this->sourceType;
            $query->whereHas('source', fn (Builder $q) => $q->where('type', $sourceType));
        }

        return $query;
    }

    /**
     * Facebook Lead Ads is excluded: those leads never appear as individual
     * rows in this list, so filtering by that source type here would always
     * return nothing.
     *
     * @return array<int, string>
     */
    public function sourceTypeOptions(): array
    {
        return array_values(array_filter(
            LeadSourceType::values(),
            fn (string $value): bool => $value !== LeadSourceType::FacebookLeadAds->value,
        ));
    }
}
