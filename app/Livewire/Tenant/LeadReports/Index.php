<?php

namespace App\Livewire\Tenant\LeadReports;

use App\Models\Currency;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\TenantUser;
use App\Services\LeadReportingService;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Feature #8 Phase 1 — read-only tenant-wide lead reporting: volume, daily
 * breakdown, current status distribution, source performance, and current
 * assignment distribution, all scoped to one shared date range. Client-
 * Admin-only (no separate Client User view exists for this page, same
 * precedent as LeadScoring\Index/LeadTags\Index). Never mutates a Lead or
 * any related record — every query in App\Services\LeadReportingService is
 * a plain SELECT.
 */
#[Title('Reports & Analytics')]
class Index extends Component
{
    use WithPagination;

    private const int MAX_RANGE_DAYS = 92;

    private const int DEFAULT_RANGE_DAYS = 30;

    public string $fromDate = '';

    public string $toDate = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', Lead::class);
        abort_unless(Auth::user()->isClientAdmin(), 403);

        $this->toDate = now()->toDateString();
        $this->fromDate = now()->subDays(self::DEFAULT_RANGE_DAYS - 1)->toDateString();
    }

    public function updatedFromDate(): void
    {
        $this->resetPage();
    }

    public function updatedToDate(): void
    {
        $this->resetPage();
    }

    /**
     * Null when the selected range is usable; otherwise the exact message
     * to show instead of any metric — an invalid range is rejected
     * outright, never silently truncated or clamped.
     */
    #[Computed]
    public function rangeError(): ?string
    {
        if ($this->fromDate === '' || $this->toDate === '') {
            return __('Please select both a from date and a to date.');
        }

        $from = Carbon::parse($this->fromDate)->startOfDay();
        $to = Carbon::parse($this->toDate)->startOfDay();

        if ($from->greaterThan($to)) {
            return __('The from date must be on or before the to date.');
        }

        if ($from->diffInDays($to) + 1 > self::MAX_RANGE_DAYS) {
            return __('The selected range is too wide — please select :max days or fewer.', ['max' => self::MAX_RANGE_DAYS]);
        }

        return null;
    }

    private function from(): Carbon
    {
        return Carbon::parse($this->fromDate)->startOfDay();
    }

    /**
     * Exclusive upper bound — matches FiltersLeadsQuery's own toDate
     * semantics exactly (created_at < startOfDay(toDate + 1 day)).
     */
    private function to(): Carbon
    {
        return Carbon::parse($this->toDate)->addDay()->startOfDay();
    }

    #[Computed]
    public function volumeCount(): int
    {
        if ($this->rangeError !== null) {
            return 0;
        }

        return app(LeadReportingService::class)->volumeCount(Auth::user()->tenant, $this->from(), $this->to());
    }

    /**
     * @return LengthAwarePaginator<int, array{date: string, count: int}>
     */
    #[Computed]
    public function dailyBreakdown(): LengthAwarePaginator
    {
        if ($this->rangeError !== null) {
            return new LengthAwarePaginator([], 0, 50, $this->getPage());
        }

        $days = app(LeadReportingService::class)->dailyVolumeBreakdown(Auth::user()->tenant, $this->from(), $this->to());

        $page = $this->getPage();
        $perPage = 50;

        return new LengthAwarePaginator(
            array_slice($days, ($page - 1) * $perPage, $perPage),
            count($days),
            $perPage,
            $page,
            ['path' => request()->url(), 'pageName' => 'page'],
        );
    }

    /**
     * Feature #12 Phase 1 — Cost per Lead, calendar-month granularity only.
     * See App\Services\LeadReportingService::sourceCostSummary() for the
     * full semantics (fully-covered-months-only, single-currency-per-
     * source, no proration, no blending).
     *
     * @return array{
     *     rows: Collection<int, array{source: LeadSource, currency: Currency, total_cost: string, lead_count: int, cost_per_lead: float|null}>,
     *     excluded_months: array<int, string>,
     *     totals_by_currency: Collection<int, array{currency: Currency, total: string}>,
     * }
     */
    #[Computed]
    public function sourceCostSummary(): array
    {
        if ($this->rangeError !== null) {
            return ['rows' => collect(), 'excluded_months' => [], 'totals_by_currency' => collect()];
        }

        return app(LeadReportingService::class)->sourceCostSummary(Auth::user()->tenant, $this->from(), $this->to());
    }

    /**
     * @return array<int, array{status: string, count: int}>
     */
    #[Computed]
    public function statusBreakdown(): array
    {
        if ($this->rangeError !== null) {
            return [];
        }

        return app(LeadReportingService::class)->statusBreakdown(Auth::user()->tenant, $this->from(), $this->to());
    }

    /**
     * The tenant's own configured pipeline, keyed by LeadStatus id, so the
     * Blade view can resolve statusBreakdown()'s bare status ids to a
     * name/color without a second query per row.
     *
     * @return Collection<string, LeadStatus>
     */
    #[Computed]
    public function tenantStatusesById(): Collection
    {
        return Auth::user()->tenant->leadStatuses->keyBy('id');
    }

    /**
     * @return Collection<int, array{source: LeadSource, count: int}>
     */
    #[Computed]
    public function sourceBreakdown(): Collection
    {
        if ($this->rangeError !== null) {
            return collect();
        }

        return app(LeadReportingService::class)->sourceBreakdown(Auth::user()->tenant, $this->from(), $this->to());
    }

    /**
     * @return Collection<int, array{assignee: TenantUser|null, count: int}>
     */
    #[Computed]
    public function assignmentBreakdown(): Collection
    {
        if ($this->rangeError !== null) {
            return collect();
        }

        return app(LeadReportingService::class)->assignmentBreakdown(Auth::user()->tenant, $this->from(), $this->to());
    }

    /**
     * Feature #10 Phase 1 — first-response-time summary. not_responded_count
     * is derived here from the already-computed volumeCount() rather than a
     * second COUNT(*) query in the service (see
     * LeadReportingService::firstResponseSummary()'s docblock).
     *
     * @return array{responded_count: int, not_responded_count: int, average_hours: float|null, median_hours: float|null}
     */
    #[Computed]
    public function firstResponseSummary(): array
    {
        if ($this->rangeError !== null) {
            return ['responded_count' => 0, 'not_responded_count' => 0, 'average_hours' => null, 'median_hours' => null];
        }

        $summary = app(LeadReportingService::class)->firstResponseSummary(Auth::user()->tenant, $this->from(), $this->to());

        return [
            ...$summary,
            'not_responded_count' => $this->volumeCount - $summary['responded_count'],
        ];
    }

    public function render(): View
    {
        return view('livewire.tenant.lead-reports');
    }
}
