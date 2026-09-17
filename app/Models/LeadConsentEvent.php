<?php

namespace App\Models;

use App\Enums\ConsentSource;
use App\Enums\ConsentStatus;
use App\Enums\ConsentType;
use Database\Factories\LeadConsentEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Feature #14 — the permanent, append-only record of every consent grant or
 * withdrawal a Lead has ever had, one row per action, never updated or
 * deleted by any code path in this app (the same "immutable in practice"
 * convention LeadStatusLog/LeadScoreLog already follow — no update()/
 * delete() call site exists against this model anywhere, other than the
 * one-time identity-snapshot update described below). App\Models\
 * LeadConsent holds only the current effective state derived from the
 * latest of these; this table is the full history, reparented (never
 * deleted) onto the surviving lead on merge — see App\Services\
 * LeadMergeService::CHILD_MODELS.
 *
 * DB-1 — this row is PRESERVED, not cascade-deleted, when its Lead is hard-
 * deleted: `lead_id` is nullable/nullOnDelete rather than cascadeOnDelete,
 * so it goes NULL instead of the row disappearing — a hard Lead delete must
 * never erase this history. `lead_identifier`/`lead_name` are a point-in-
 * time identity snapshot written only by App\Actions\Tenant\
 * RecordLeadDeletionAudit at the moment of deletion (the one deliberate
 * exception to "never updated" above — it sets exactly these two columns,
 * nothing else) so a detached row stays queryable/identifiable on its own
 * even after its Lead is gone.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $lead_id
 * @property string|null $lead_identifier
 * @property string|null $lead_name
 * @property ConsentType $consent_type
 * @property ConsentStatus $status
 * @property ConsentSource $source
 * @property string|null $website_form_id
 * @property string|null $website_form_name
 * @property string|null $changed_by
 * @property string|null $policy_version
 * @property string|null $policy_url
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon $occurred_at
 * @property-read Lead|null $lead
 * @property-read TenantUser|null $changedBy
 */
#[Fillable([
    'tenant_id', 'lead_id', 'lead_identifier', 'lead_name', 'consent_type', 'status', 'source',
    'website_form_id', 'website_form_name', 'changed_by',
    'policy_version', 'policy_url', 'ip_address', 'user_agent', 'occurred_at',
])]
class LeadConsentEvent extends Model
{
    /** @use HasFactory<LeadConsentEventFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'consent_type' => ConsentType::class,
            'status' => ConsentStatus::class,
            'source' => ConsentSource::class,
            'occurred_at' => 'datetime',
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
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'changed_by');
    }
}
