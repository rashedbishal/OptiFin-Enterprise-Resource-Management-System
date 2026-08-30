<?php

namespace App\Livewire\Tenant\Leads;

use App\Enums\LeadPriority;
use App\Enums\LeadSourceType;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\TenantUser;
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
    use WithPagination;

    public string $status = '';

    public string $priority = '';

    public string $sourceType = '';

    public string $assignedTo = '';

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
    public function isClientAdmin(): bool
    {
        return Auth::user()->isClientAdmin();
    }

    #[Computed]
    public function leads()
    {
        return $this->filteredQuery()
            ->with(['source', 'assignedUser'])
            ->latest()
            ->paginate(25);
    }

    #[Computed]
    public function assignableUsers()
    {
        if (! $this->isClientAdmin) {
            return collect();
        }

        return TenantUser::query()
            ->where('tenant_id', Auth::user()->tenant_id)
            ->where('is_active', true)
            ->whereHas('role', fn (Builder $q) => $q->whereHas('modules', fn (Builder $m) => $m->where('slug', 'lead-management')))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function delete(string $id): void
    {
        $lead = Lead::where('tenant_id', Auth::user()->tenant_id)->findOrFail($id);

        Gate::authorize('delete', $lead);

        $lead->delete();

        Flux::toast(variant: 'success', text: __('Lead deleted.'));
    }

    public function export(): StreamedResponse
    {
        Gate::authorize('viewAny', Lead::class);

        $leads = $this->filteredQuery()->with(['source', 'assignedUser'])->get();

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

        $query = Lead::query()->where('tenant_id', $user->tenant_id);

        if (! $this->isClientAdmin) {
            $query->assignedTo($user->id);
        }

        if ($this->status !== '') {
            $query->byStatus(LeadStatus::from($this->status));
        }

        if ($this->priority !== '') {
            $query->byPriority(LeadPriority::from($this->priority));
        }

        if ($this->sourceType !== '') {
            $sourceType = $this->sourceType;
            $query->whereHas('source', fn (Builder $q) => $q->where('type', $sourceType));
        }

        if ($this->isClientAdmin && $this->assignedTo !== '') {
            if ($this->assignedTo === 'unassigned') {
                $query->unassigned();
            } else {
                $query->assignedTo($this->assignedTo);
            }
        }

        return $query;
    }

    public function statusOptions(): array
    {
        return LeadStatus::values();
    }

    public function priorityOptions(): array
    {
        return LeadPriority::values();
    }

    public function sourceTypeOptions(): array
    {
        return LeadSourceType::values();
    }
}
