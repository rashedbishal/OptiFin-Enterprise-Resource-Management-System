<?php

namespace App\Models;

use Database\Factories\LeadNoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['lead_id', 'tenant_user_id', 'note'])]
class LeadNote extends Model
{
    /** @use HasFactory<LeadNoteFactory> */
    use HasFactory, HasUlids;

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function tenantUser(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class);
    }
}
