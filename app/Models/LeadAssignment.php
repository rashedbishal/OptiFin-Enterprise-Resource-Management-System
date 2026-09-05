<?php

namespace App\Models;

use Database\Factories\LeadAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['lead_id', 'assigned_to', 'assigned_by', 'assignment_note'])]
class LeadAssignment extends Model
{
    /** @use HasFactory<LeadAssignmentFactory> */
    use HasFactory, HasUlids;

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'assigned_to');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'assigned_by');
    }
}
