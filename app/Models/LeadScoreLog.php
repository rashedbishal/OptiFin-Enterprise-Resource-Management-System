<?php

namespace App\Models;

use Database\Factories\LeadScoreLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row per MEANINGFUL score recomputation for a lead — see
 * App\Services\LeadScoringService::logIfChanged(), which skips writing a row
 * at all when a recompute produces the same score and breakdown as the
 * lead's last log. `breakdown` is a point-in-time snapshot (rule name/
 * field/operator/value/matched value/points for every matched rule), not a
 * live join, so a historical log stays fully readable after a
 * LeadScoringRule it references is later edited or deleted.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $lead_id
 * @property int $score
 * @property int $raw_total
 * @property array<int, array{rule_id: string, rule_name: string, field: string, operator: string, value: mixed, matched_value: mixed, points: int}> $breakdown
 * @property string $trigger
 * @property string|null $triggered_by
 * @property Carbon $computed_at
 */
#[Fillable(['tenant_id', 'lead_id', 'score', 'raw_total', 'breakdown', 'trigger', 'triggered_by', 'computed_at'])]
class LeadScoreLog extends Model
{
    /** @use HasFactory<LeadScoreLogFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'raw_total' => 'integer',
            'breakdown' => 'array',
            'computed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<TenantUser, $this>
     */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'triggered_by');
    }
}
