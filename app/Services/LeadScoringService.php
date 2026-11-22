<?php

namespace App\Services;

use App\Enums\LeadScoringField;
use App\Enums\LeadScoringOperator;
use App\Models\Lead;
use App\Models\LeadScoreLog;
use App\Models\LeadScoringRule;
use App\Models\TenantUser;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rule-based Lead Scoring: on every call, computes a lead's score FROM
 * SCRATCH against its tenant's current active LeadScoringRule rows and the
 * lead's current column values — never increments/decrements the
 * previously stored score, so firing the same trigger twice (or running the
 * scheduled sweep twice) on an unchanged lead always yields the same
 * result. See .ai conversation history for the full approved architecture;
 * this class is the single place that reads lead_scoring_rules and writes
 * leads.lead_score/lead_score_computed_at and lead_score_logs.
 */
class LeadScoringService
{
    private const int MIN_SCORE = 0;

    private const int MAX_SCORE = 100;

    /**
     * Lead columns App\Livewire\Tenant\Leads\Show and
     * App\Services\LeadMergeService check against their own $changed/
     * $updates sets before calling computeFor() — the single source of
     * truth for "which detail-form/merge field changes are scoring-
     * relevant", so the two call sites can never drift out of sync with
     * each other or with LeadScoringField's Numeric/boolean-kind cases.
     *
     * @var array<int, string>
     */
    /**
     * The trigger leads:recalculate-scores passes.
     */
    public const string SWEEP_TRIGGER = 'scheduled_sweep';

    /**
     * @var array<string, Collection<int, LeadScoringRule>>
     */
    private array $sweepRulesByTenant = [];

    public const array SCORING_RELEVANT_DETAIL_FIELDS = [
        'estimated_budget', 'deal_value', 'win_probability', 'email', 'phone',
    ];

    /**
     * Recomputes $lead's score from its tenant's current active scoring
     * rules and $lead's current state, writes leads.lead_score/
     * lead_score_computed_at, and — only if the result actually differs
     * from the lead's last LeadScoreLog — appends a new log row. Returns
     * the new score, or null when the tenant has no active rules at all
     * (see the class docblock and .ai/rules — null means "not scored",
     * distinct from a real computed 0).
     *
     * $trustLoadedRelations (M6, default false — every existing caller is
     * unaffected): opt-in only, for a caller that can prove by
     * construction its $lead's source/status relations were JUST set from
     * fresh, correctly-resolved instances in this same unbroken execution
     * (currently only CreateLeadFromImport::processRow(), whose $lead is
     * always newly Lead::create()'d, never reused/passed around). Skips
     * the reload below via loadMissing() instead of load(). Never set
     * true for a $lead that could have arrived with a stale cached
     * relation from elsewhere in the request — see the load() note below.
     */
    public function computeFor(Lead $lead, string $trigger, ?TenantUser $triggeredBy = null, bool $trustLoadedRelations = false): ?int
    {
        $isSweep = $trigger === self::SWEEP_TRIGGER;

        // The sweep's own chunk query eager-loads source/status fresh for
        // exactly this run, so the stale-relation concern below can't apply.
        $trustLoadedRelations = $trustLoadedRelations || $isSweep;

        // load(), not loadMissing(), BY DEFAULT: $lead often arrives already
        // carrying a STALE cached source/status relation from earlier in
        // the same request (e.g. Show::updateStatus() just changed
        // status_id and saved, but the old LeadStatus instance is still
        // sitting in the relation cache) — loadMissing() would skip
        // reloading it and LeadScoringField::Status/SourceType would then
        // evaluate against yesterday's value. $trustLoadedRelations is the
        // sole, explicit, opt-in exception to this — see its own docblock
        // above.
        if ($trustLoadedRelations) {
            $lead->loadMissing(['source', 'status']);
        } else {
            $lead->load(['source', 'status']);
        }

        $rules = $isSweep ? $this->sweepRulesFor($lead->tenant_id) : $this->activeRulesFor($lead->tenant_id);

        if ($rules->isEmpty()) {
            // Sweep only: a lead that is already unscored needs no write.
            // Without this, every lead of every tenant without scoring rules
            // got a pointless UPDATE every day.
            if ($isSweep && $lead->lead_score === null && $lead->lead_score_computed_at !== null) {
                return null;
            }

            DB::table('leads')->where('id', $lead->id)->update([
                'lead_score' => null,
                'lead_score_computed_at' => now(),
            ]);

            return null;
        }

        $rawTotal = 0;
        $breakdown = [];

        foreach ($rules as $rule) {
            $match = $this->evaluateRule($rule, $lead);

            if ($match === null) {
                continue;
            }

            $rawTotal += $rule->points;
            $breakdown[] = [
                'rule_id' => $rule->id,
                'rule_name' => $rule->name,
                'field' => $rule->field,
                'operator' => $rule->operator,
                'value' => $rule->value,
                'matched_value' => $match,
                'points' => $rule->points,
            ];
        }

        $score = max(self::MIN_SCORE, min(self::MAX_SCORE, $rawTotal));

        // M3 fix: the score write and its conditional LeadScoreLog write
        // are wrapped in one transaction so a failure partway through can't
        // leave leads.lead_score updated with no matching audit row.
        // DB::transaction() safely nests — a caller that already has its
        // own open transaction (LeadMergeService::merge(), CreateManualLead,
        // CreateLeadFromImport, Show::updateStatus(),
        // Index::applyBulkStatusChange()/applyBulkPriorityChange()) simply
        // has this participate in that same transaction; a standalone
        // caller (Show::recalculateScore(), the queued
        // RecomputeLeadScoreOnReceipt listener, the leads:recalculate-scores
        // sweep) gets its own small transaction scoped to just this one
        // lead's recompute.
        DB::transaction(function () use ($lead, $score, $rawTotal, $breakdown, $trigger, $triggeredBy): void {
            // Raw query builder update, not $lead->save()/update(): a score
            // recompute must never touch updated_at, or every background sweep
            // run would spuriously trip Lead::hasUnseenUpdate()'s "recently
            // updated" highlight for every viewer — same reasoning as
            // Lead::markUpdateSeen() and LeadMergeService's loser update.
            DB::table('leads')->where('id', $lead->id)->update([
                'lead_score' => $score,
                'lead_score_computed_at' => now(),
            ]);

            $this->logIfChanged($lead, $score, $rawTotal, $breakdown, $trigger, $triggeredBy);
        });

        return $score;
    }

    /**
     * @return Collection<int, LeadScoringRule>
     */
    private function activeRulesFor(string $tenantId): Collection
    {
        return LeadScoringRule::query()
            ->where('tenant_id', $tenantId)
            ->active()
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * The daily sweep scores every lead of a tenant in one run, so it reads
     * that tenant's rules once instead of once per lead. Scoped to the sweep
     * trigger only, so every other caller still sees rule edits immediately.
     *
     * @return Collection<int, LeadScoringRule>
     */
    private function sweepRulesFor(string $tenantId): Collection
    {
        return $this->sweepRulesByTenant[$tenantId] ??= $this->activeRulesFor($tenantId);
    }

    /**
     * Returns the field's matched current value (for the breakdown) if the
     * rule matched, or null if it didn't match OR couldn't be evaluated at
     * all (unknown field/operator, an operator not valid for the field's
     * kind, or an exception while resolving/evaluating) — every case is
     * treated identically by computeFor(): silently skipped, never fatal.
     * Unsupported rules are logged server-side so a tenant's misconfigured
     * rule is discoverable without ever breaking lead creation, editing, a
     * merge, or a recalculation.
     */
    private function evaluateRule(LeadScoringRule $rule, Lead $lead): mixed
    {
        try {
            $field = LeadScoringField::tryFrom($rule->field);
            $operator = LeadScoringOperator::tryFrom($rule->operator);

            if ($field === null || $operator === null || ! in_array($operator, $field->allowedOperators(), true)) {
                Log::warning('Skipped unsupported lead scoring rule.', [
                    'rule_id' => $rule->id,
                    'tenant_id' => $rule->tenant_id,
                    'field' => $rule->field,
                    'operator' => $rule->operator,
                ]);

                return null;
            }

            $actual = $field->resolve($lead);

            if ($actual === null) {
                return null;
            }

            return $operator->evaluate($actual, $rule->value) ? $actual : null;
        } catch (Throwable $e) {
            Log::warning('Failed to evaluate lead scoring rule.', [
                'rule_id' => $rule->id,
                'tenant_id' => $rule->tenant_id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<int, array{rule_id: string, rule_name: string, field: string, operator: string, value: mixed, matched_value: mixed, points: int}>  $breakdown
     */
    private function logIfChanged(Lead $lead, int $score, int $rawTotal, array $breakdown, string $trigger, ?TenantUser $triggeredBy): void
    {
        $lastLog = LeadScoreLog::query()
            ->where('lead_id', $lead->id)
            ->latest('computed_at')
            ->first();

        // Only score/breakdown decide whether this is a "meaningful"
        // change — trigger is metadata about WHY we recomputed, not part
        // of "did the result change", so a manual recalculation that
        // reproduces the same score/breakdown an automatic one already
        // logged does not append a redundant row.
        if ($lastLog !== null && $lastLog->score === $score && $lastLog->breakdown === $breakdown) {
            return;
        }

        LeadScoreLog::create([
            'tenant_id' => $lead->tenant_id,
            'lead_id' => $lead->id,
            'score' => $score,
            'raw_total' => $rawTotal,
            'breakdown' => $breakdown,
            'trigger' => $trigger,
            'triggered_by' => $triggeredBy?->id,
            'computed_at' => now(),
        ]);
    }
}
