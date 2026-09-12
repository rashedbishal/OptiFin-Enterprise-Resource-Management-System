<?php

namespace App\Models;

use App\Enums\DealStatus;
use Database\Factories\DealStatusLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One real status transition for a Deal — a row is written only for actual
 * transitions (see Show::changeDealStatus()), never for a Deal's initial
 * creation. Keyed to deal_id only, deliberately (see the
 * create_deal_status_logs_table migration's docblock): App\Services\
 * LeadMergeService reparents a Deal's lead_id but never touches its id, so
 * this table needs no merge-time handling of its own.
 *
 * @property string $id
 * @property string $deal_id
 * @property string|null $changed_by
 * @property DealStatus|null $old_status
 * @property DealStatus $new_status
 * @property string|null $note
 * @property-read Deal $deal
 * @property-read TenantUser|null $changedBy
 */
#[Fillable(['deal_id', 'changed_by', 'old_status', 'new_status', 'note'])]
class DealStatusLog extends Model
{
    /** @use HasFactory<DealStatusLogFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_status' => DealStatus::class,
            'new_status' => DealStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Deal, $this>
     */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /**
     * @return BelongsTo<TenantUser, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'changed_by');
    }
}
