<?php

namespace App\Models;

use Database\Factories\LeadDetailUpdateLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['lead_id', 'changed_by', 'changed_fields', 'action', 'action_date'])]
class LeadDetailUpdateLog extends Model
{
    /** @use HasFactory<LeadDetailUpdateLogFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'changed_fields' => 'array',
            'action_date' => 'date',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'changed_by');
    }
}
