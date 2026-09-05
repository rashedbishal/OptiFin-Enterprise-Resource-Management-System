<?php

namespace App\Models;

use Database\Factories\LeadStatusLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['lead_id', 'changed_by', 'old_status_id', 'new_status_id', 'note'])]
class LeadStatusLog extends Model
{
    /** @use HasFactory<LeadStatusLogFactory> */
    use HasFactory, HasUlids;

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'changed_by');
    }

    /**
     * @return BelongsTo<LeadStatus, $this>
     */
    public function oldStatus(): BelongsTo
    {
        return $this->belongsTo(LeadStatus::class, 'old_status_id');
    }

    /**
     * @return BelongsTo<LeadStatus, $this>
     */
    public function newStatus(): BelongsTo
    {
        return $this->belongsTo(LeadStatus::class, 'new_status_id');
    }
}
