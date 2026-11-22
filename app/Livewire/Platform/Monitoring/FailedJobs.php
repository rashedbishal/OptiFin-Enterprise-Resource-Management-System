<?php

namespace App\Livewire\Platform\Monitoring;

use App\Enums\FailedJobAdvice;
use App\Enums\MonitoringTool;
use App\Enums\PermissionSlug;
use App\Livewire\Platform\Monitoring\Concerns\AuthorizesMonitoringTool;
use App\Services\FailedJobService;
use Flux\Flux;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

/**
 * Admin → Monitoring → Horizon → Failed jobs: every failed background job in
 * plain language, each with a suggestion (Retry / Delete / Ask a developer /
 * Not sure) and the reason — see FailedJobService::advise(). Part of the
 * Horizon tool: `view` to see the list, `update` to retry or delete.
 *
 * @phpstan-import-type FailedJob from FailedJobService
 */
#[Title('Failed jobs')]
#[Layout('layouts.admin')]
class FailedJobs extends Component
{
    use AuthorizesMonitoringTool, WithPagination;

    private const PER_PAGE = 20;

    #[Url]
    public string $advice = '';

    #[Locked]
    public ?string $selectedJobId = null;

    public bool $showDetailModal = false;

    #[Locked]
    public ?string $confirmingDeleteJobId = null;

    public bool $showDeleteModal = false;

    public bool $showDeleteSuggestedModal = false;

    public function mount(): void
    {
        $this->authorizeMonitoring();
    }

    protected function monitoringTool(): MonitoringTool
    {
        return MonitoringTool::Horizon;
    }

    public function updatedAdvice(): void
    {
        if (FailedJobAdvice::tryFrom($this->advice) === null) {
            $this->advice = '';
        }

        $this->resetPage();
    }

    #[Computed]
    public function canManage(): bool
    {
        return $this->allowsMonitoring(PermissionSlug::Update);
    }

    /**
     * Loaded once per request (several parts of the page read it).
     *
     * @var Collection<int, FailedJob>|null
     */
    private ?Collection $loadedJobs = null;

    /**
     * @return Collection<int, FailedJob>
     */
    private function allJobs(): Collection
    {
        return $this->loadedJobs ??= app(FailedJobService::class)->recent();
    }

    /**
     * @return array<string, int> advice value => count
     */
    #[Computed]
    public function adviceCounts(): array
    {
        return $this->allJobs()->countBy(fn (array $job): string => $job['advice']->value)->all();
    }

    #[Computed]
    public function listedCount(): int
    {
        return $this->allJobs()->count();
    }

    #[Computed]
    public function totalFailed(): int
    {
        return app(FailedJobService::class)->total();
    }

    /**
     * @return LengthAwarePaginator<int, FailedJob>
     */
    #[Computed]
    public function jobs(): LengthAwarePaginator
    {
        $filter = FailedJobAdvice::tryFrom($this->advice);
        $jobs = $filter === null ? $this->allJobs() : $this->allJobs()->filter(fn (array $job): bool => $job['advice'] === $filter)->values();
        $page = $this->getPage();

        return new LengthAwarePaginator($jobs->forPage($page, self::PER_PAGE)->values(), $jobs->count(), self::PER_PAGE, $page);
    }

    /**
     * @return FailedJob|null
     */
    #[Computed]
    public function selectedJob(): ?array
    {
        return $this->selectedJobId === null ? null : app(FailedJobService::class)->find($this->selectedJobId);
    }

    public function showJob(string $jobId): void
    {
        $this->selectedJobId = $jobId;
        unset($this->selectedJob);

        if ($this->selectedJob() === null) {
            $this->selectedJobId = null;
            Flux::toast(variant: 'danger', text: __('That failed job no longer exists.'));
            $this->refreshList();

            return;
        }

        $this->showDetailModal = true;
    }

    public function retry(string $jobId): void
    {
        $this->authorizeMonitoring(PermissionSlug::Update);

        try {
            $retried = app(FailedJobService::class)->retry($jobId);
        } catch (Throwable $exception) {
            report($exception);
            Flux::toast(variant: 'danger', text: __('The job could not be put back on the queue. Is Redis running?'));

            return;
        }

        $this->closeDetail();
        $this->refreshList();

        Flux::toast(
            variant: $retried ? 'success' : 'danger',
            text: $retried ? __('Job sent back to the queue. If it fails again it will reappear here.') : __('That failed job no longer exists.'),
        );
    }

    public function confirmDelete(string $jobId): void
    {
        $this->authorizeMonitoring(PermissionSlug::Update);

        $this->confirmingDeleteJobId = $jobId;
        $this->showDeleteModal = true;
    }

    public function deleteConfirmed(): void
    {
        $this->authorizeMonitoring(PermissionSlug::Update);

        if ($this->confirmingDeleteJobId !== null) {
            app(FailedJobService::class)->delete($this->confirmingDeleteJobId);
        }

        $this->showDeleteModal = false;
        $this->confirmingDeleteJobId = null;
        $this->closeDetail();
        $this->refreshList();

        Flux::toast(variant: 'success', text: __('Failed job deleted.'));
    }

    public function confirmDeleteSuggested(): void
    {
        $this->authorizeMonitoring(PermissionSlug::Update);

        $this->showDeleteSuggestedModal = true;
    }

    /**
     * Deletes every listed job whose suggestion is "Delete" — the ones the
     * page has already judged safe to remove.
     */
    public function deleteSuggestedConfirmed(): void
    {
        $this->authorizeMonitoring(PermissionSlug::Update);

        $ids = $this->allJobs()
            ->filter(fn (array $job): bool => $job['advice'] === FailedJobAdvice::Delete)
            ->map(fn (array $job): string => $job['id'])
            ->values()
            ->all();

        $deleted = app(FailedJobService::class)->deleteMany($ids);

        $this->showDeleteSuggestedModal = false;
        $this->refreshList();

        Flux::toast(variant: 'success', text: trans_choice(':count failed job deleted.|:count failed jobs deleted.', $deleted));
    }

    /**
     * The modal closed some other way (backdrop click, Escape).
     */
    public function updatedShowDetailModal(bool $open): void
    {
        if (! $open) {
            $this->selectedJobId = null;
        }
    }

    public function closeDetail(): void
    {
        $this->showDetailModal = false;
        $this->selectedJobId = null;
    }

    private function refreshList(): void
    {
        $this->loadedJobs = null;
        unset($this->adviceCounts, $this->listedCount, $this->totalFailed, $this->jobs, $this->selectedJob);

        if ($this->getPage() > max(1, $this->jobs()->lastPage())) {
            $this->resetPage();
        }
    }
}
