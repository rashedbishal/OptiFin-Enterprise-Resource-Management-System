<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadSourceCost;
use App\Models\Tenant;
use App\Models\TenantUser;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Feature #8 Phase 1 — read-only aggregation for the Reporting & Analytics
 * page. Every method builds its own query from scratch, explicitly filtered
 * by tenant_id (never relying on BelongsToTenant's global scope alone, same
 * discipline as every other cross-lead query in this module) and excludes
 * merged-away leads (whereNull('merged_into_lead_id'), the same exclusion
 * Leads\Index::filteredQuery() already applies). All four metrics are
 * current-state snapshots of leads created within [$from, $to) — never
 * historical stage-transition/reassignment-history analysis, by explicit
 * Phase 1 scope decision.
 */
class LeadReportingService
{
    /**
     * @return Builder<Lead>
     */
    private function baseQuery(Tenant $tenant, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return Lead::query()
            ->where('tenant_id', $tenant->id)
            ->whereNull('merged_into_lead_id')
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to);
    }

    public function volumeCount(Tenant $tenant, CarbonInterface $from, CarbonInterface $to): int
    {
        return $this->baseQuery($tenant, $from, $to)->count();
    }

    /**
     * One row per calendar day in [$from, $to), zero-filled for days with
     * no leads — same zero-fill philosophy as Lead::countsByStatus(): the
     * query only returns days that actually have rows, then every day in
     * the requested range is filled in afterward so a quiet day doesn't
     * just vanish from the table.
     *
     * @return array<int, array{date: string, count: int}>
     */
    public function dailyVolumeBreakdown(Tenant $tenant, CarbonInterface $from, CarbonInterface $to): array
    {
        // Postgres in production; SQLite date() in the test suite
        // (phpunit.xml runs DB_CONNECTION=sqlite, which has no date_trunc).
        $dayExpression = config('database.default') === 'pgsql'
            ? "date_trunc('day', created_at)"
            : 'date(created_at)';

        $counts = $this->baseQuery($tenant, $from, $to)
            ->selectRaw("{$dayExpression} as day, count(*) as count")
            ->groupBy('day')
            ->pluck('count', 'day');

        $countsByDate = $counts->mapWithKeys(
            fn (int $count, string $day): array => [Carbon::parse($day)->toDateString() => $count]
        );

        $days = [];

        foreach (CarbonPeriod::create($from->copy(), '1 day', $to->copy()->subDay()) as $day) {
            $date = $day->toDateString();

            $days[] = [
                'date' => $date,
                'count' => (int) ($countsByDate[$date] ?? 0),
            ];
        }

        return $days;
    }

    /**
     * Current status distribution of leads created in range, zero-filled
     * against the tenant's actual configured pipeline — delegates to the
     * existing Lead::countsByStatus(), the same method the Kanban board's
     * column headers already use.
     *
     * @return array<int, array{status: string, count: int}>
     */
    public function statusBreakdown(Tenant $tenant, CarbonInterface $from, CarbonInterface $to): array
    {
        return Lead::countsByStatus($this->baseQuery($tenant, $from, $to), $tenant->leadStatuses);
    }

    /**
     * Current source distribution of leads created in range. Inactive
     * sources are deliberately NOT filtered out — a source's historical
     * performance must not disappear from the report just because it was
     * later deactivated (LeadSource::active() scope is never applied here).
     *
     * @return Collection<int, array{source: LeadSource, count: int}>
     */
    public function sourceBreakdown(Tenant $tenant, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $counts = $this->baseQuery($tenant, $from, $to)
            ->selectRaw('source_id, count(*) as count')
            ->groupBy('source_id')
            ->pluck('count', 'source_id');

        $sources = LeadSource::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('id', $counts->keys())
            ->get()
            ->keyBy('id');

        return $counts->map(fn (int $count, string $sourceId): array => [
            'source' => $sources[$sourceId],
            'count' => $count,
        ])->sortByDesc('count')->values();
    }

    /**
     * Current assignment distribution of leads created in range. Unassigned
     * leads (assigned_to IS NULL) are counted separately rather than
     * grouped in the same query — pluck() coerces a NULL group-by key to
     * an empty-string array key, which would make "unassigned" and "a real
     * user id of ''" indistinguishable, so the two are queried apart.
     *
     * @return Collection<int, array{assignee: TenantUser|null, count: int}>
     */
    public function assignmentBreakdown(Tenant $tenant, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $assignedCounts = $this->baseQuery($tenant, $from, $to)
            ->whereNotNull('assigned_to')
            ->selectRaw('assigned_to, count(*) as count')
            ->groupBy('assigned_to')
            ->pluck('count', 'assigned_to');

        $unassignedCount = $this->baseQuery($tenant, $from, $to)
            ->whereNull('assigned_to')
            ->count();

        $users = TenantUser::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('id', $assignedCounts->keys())
            ->get()
            ->keyBy('id');

        $rows = $assignedCounts->map(fn (int $count, string $userId): array => [
            'assignee' => $users[$userId],
            'count' => $count,
        ])->values()->all();

        if ($unassignedCount > 0) {
            $rows[] = ['assignee' => null, 'count' => $unassignedCount];
        }

        usort($rows, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return collect($rows);
    }

    /**
     * Feature #10 Phase 1 — first-response-time summary over leads created
     * in range. Deliberately returns only responded_count/average/median —
     * NOT a total or not-responded count, since the caller already has the
     * window's total via volumeCount() and can derive not-responded as
     * (total - responded) without a second COUNT(*) query.
     *
     * Average/median are computed in PHP from a single fetched result set
     * (created_at, first_contacted_at pairs for responded leads only) rather
     * than in SQL — no EXTRACT(EPOCH ...)/date_trunc/percentile_cont —
     * deliberately, to stay fully portable between Postgres (production)
     * and SQLite (tests) without a driver branch, unlike
     * dailyVolumeBreakdown()'s date expression above.
     *
     * @return array{responded_count: int, average_hours: float|null, median_hours: float|null}
     */
    public function firstResponseSummary(Tenant $tenant, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = $this->baseQuery($tenant, $from, $to)
            ->whereNotNull('first_contacted_at')
            ->get(['created_at', 'first_contacted_at']);

        $hours = $rows
            ->map(fn (Lead $lead): float => $lead->created_at->diffInSeconds($lead->first_contacted_at) / 3600)
            ->sort()
            ->values();

        return [
            'responded_count' => $hours->count(),
            'average_hours' => $hours->isEmpty() ? null : round($hours->average(), 1),
            'median_hours' => $hours->isEmpty() ? null : round($this->median($hours), 1),
        ];
    }

    /**
     * @param  Collection<int, float>  $sortedValues  Must already be sorted ascending.
     */
    private function median(Collection $sortedValues): float
    {
        $count = $sortedValues->count();
        $middle = intdiv($count, 2);

        if ($count % 2 === 0) {
            return ($sortedValues[$middle - 1] + $sortedValues[$middle]) / 2;
        }

        return $sortedValues[$middle];
    }

    /**
     * Feature #12 Phase 1 — Cost per Lead, calendar-month granularity only.
     * Deliberately never prorates a partial month: a calendar month that
     * only partially overlaps [$from, $to) contributes nothing to any
     * source's totals and is instead returned in 'excluded_months' so the
     * caller can render an explicit disclaimer, per the approved
     * architecture. A fully-covered month with NO recorded LeadSourceCost
     * entry for a given source is treated as "not tracked" — it is not
     * assumed to be $0 spend, and simply contributes nothing to that
     * source's sum (never inflates lead count without a matching cost, and
     * never treats a gap as free leads).
     *
     * Every source's own cost rows are guaranteed single-currency by the
     * write path (App\Livewire\Tenant\LeadSourceCosts\Index::save()'s
     * currency-consistency check) — this method relies on that invariant
     * rather than re-deriving it, so summing one source's amounts across
     * months is always safe. Different sources may use different
     * currencies; nothing here ever blends or converts between them —
     * 'totals_by_currency' groups the grand total by currency instead of
     * collapsing it into one blended number.
     *
     * @return array{
     *     rows: Collection<int, array{source: LeadSource, currency: Currency, total_cost: string, lead_count: int, cost_per_lead: float|null}>,
     *     excluded_months: array<int, string>,
     *     totals_by_currency: Collection<int, array{currency: Currency, total: string}>,
     * }
     */
    public function sourceCostSummary(Tenant $tenant, CarbonInterface $from, CarbonInterface $to): array
    {
        $fullyCoveredMonths = [];
        $excludedMonths = [];

        foreach ($this->overlappingMonths($from, $to) as $monthStart) {
            $monthEndExclusive = $monthStart->copy()->startOfMonth()->addMonthNoOverflow();

            if ($monthStart->greaterThanOrEqualTo($from) && $monthEndExclusive->lessThanOrEqualTo($to)) {
                $fullyCoveredMonths[] = $monthStart;
            } else {
                $excludedMonths[] = $monthStart->format('F Y');
            }
        }

        if ($fullyCoveredMonths === []) {
            return ['rows' => collect(), 'excluded_months' => $excludedMonths, 'totals_by_currency' => collect()];
        }

        // A range comparison (>=/<), never an exact-equality whereIn: SQLite
        // (the test driver) has no real DATE column type and stores
        // whatever string Eloquent's 'date' cast serializes verbatim,
        // including a trailing "00:00:00" — an exact string match against a
        // bare Y-m-d value silently fails there even though it works on
        // Postgres (production), where the DATE column type normalizes
        // storage regardless of what's inserted. A >=/< range is safe on
        // both, since "2026-09-01" sorts before "2026-09-01 00:00:00"
        // lexicographically either way.
        $allCosts = LeadSourceCost::query()
            ->where('tenant_id', $tenant->id)
            ->where(function (Builder $query) use ($fullyCoveredMonths): void {
                foreach ($fullyCoveredMonths as $month) {
                    $query->orWhere(fn (Builder $q) => $q
                        ->where('month', '>=', $month->toDateString())
                        ->where('month', '<', $month->copy()->addMonthNoOverflow()->toDateString()));
                }
            })
            ->with(['source', 'currency'])
            ->get();

        $rows = $allCosts->groupBy('lead_source_id')->map(function (Collection $sourceCosts) use ($tenant): array {
            /** @var LeadSourceCost $first */
            $first = $sourceCosts->first();

            $totalCost = $sourceCosts->sum(fn (LeadSourceCost $cost): float => (float) $cost->amount);

            $monthRanges = $sourceCosts
                ->map(fn (LeadSourceCost $cost): array => [
                    $cost->month->copy()->startOfMonth(),
                    $cost->month->copy()->startOfMonth()->addMonthNoOverflow(),
                ])
                ->all();

            $leadCount = Lead::query()
                ->where('tenant_id', $tenant->id)
                ->where('source_id', $first->lead_source_id)
                ->whereNull('merged_into_lead_id')
                ->where(function (Builder $query) use ($monthRanges): void {
                    foreach ($monthRanges as [$start, $end]) {
                        $query->orWhere(fn (Builder $q) => $q->where('created_at', '>=', $start)->where('created_at', '<', $end));
                    }
                })
                ->count();

            return [
                'source' => $first->source,
                'currency' => $first->currency,
                'total_cost' => number_format($totalCost, 2, '.', ''),
                'lead_count' => $leadCount,
                'cost_per_lead' => $leadCount > 0 ? round($totalCost / $leadCount, 2) : null,
            ];
        })->sortByDesc('total_cost')->values();

        $totalsByCurrency = $allCosts->groupBy('currency_id')->map(function (Collection $group): array {
            /** @var LeadSourceCost $first */
            $first = $group->first();

            return [
                'currency' => $first->currency,
                'total' => number_format($group->sum(fn (LeadSourceCost $cost): float => (float) $cost->amount), 2, '.', ''),
            ];
        })->values();

        return ['rows' => $rows, 'excluded_months' => $excludedMonths, 'totals_by_currency' => $totalsByCurrency];
    }

    /**
     * Every calendar month (as its first-day Carbon instance) whose day
     * range overlaps [$from, $to) at all, in order — including partially
     * overlapping boundary months, which the caller separates out.
     *
     * @return array<int, CarbonInterface>
     */
    private function overlappingMonths(CarbonInterface $from, CarbonInterface $to): array
    {
        $months = [];
        $cursor = $from->copy()->startOfMonth();
        $lastMonth = $to->copy()->subDay()->startOfMonth();

        while ($cursor->lessThanOrEqualTo($lastMonth)) {
            $months[] = $cursor->copy();
            $cursor = $cursor->addMonthNoOverflow();
        }

        return $months;
    }
}
