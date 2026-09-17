<?php

namespace App\Models;

use Database\Factories\LeadDeletionAuditFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DB-1 — the permanent tombstone for a hard-deleted Lead. Leads are never
 * soft-deleted (deletion is deliberately permanent, with no restore
 * workflow), so this is the only record of a deleted lead's identity that
 * survives it. Written by App\Actions\Tenant\RecordLeadDeletionAudit inside
 * the SAME database transaction as the Lead's own delete — immediately
 * before it, never after — so an audit row exists for every deletion with
 * no possible gap: if the audit write fails, the transaction rolls back and
 * the Lead is never deleted (fail-closed).
 *
 * `lead_id` goes NULL the instant the Lead row it pointed to is gone (which
 * is always, moments after this row is written, in the same transaction) —
 * `lead_identifier`/`lead_name`/`lead_email`/`lead_phone`/`source_type` are
 * frozen snapshots taken from the Lead immediately before deletion, so this
 * row stays fully identifiable regardless. `cascade_summary` is a
 * point-in-time count of what was cascade-destroyed alongside the Lead (see
 * RecordLeadDeletionAudit::cascadeSummary()) — informational only, not a
 * mechanism for reconstructing any of it.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $lead_id
 * @property string $lead_identifier
 * @property string $lead_name
 * @property string|null $lead_email
 * @property string|null $lead_phone
 * @property string $source_type
 * @property string|null $deleted_by
 * @property bool $is_bulk
 * @property array<string, int>|null $cascade_summary
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read Lead|null $lead
 * @property-read TenantUser|null $deletedBy
 */
#[Fillable([
    'tenant_id', 'lead_id', 'lead_identifier', 'lead_name', 'lead_email', 'lead_phone',
    'source_type', 'deleted_by', 'is_bulk', 'cascade_summary',
])]
class LeadDeletionAudit extends Model
{
    /** @use HasFactory<LeadDeletionAuditFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_bulk' => 'boolean',
            'cascade_summary' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
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
    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'deleted_by');
    }
}
