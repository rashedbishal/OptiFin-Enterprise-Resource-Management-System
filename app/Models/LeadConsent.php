<?php

namespace App\Models;

use App\Enums\ConsentSource;
use App\Enums\ConsentStatus;
use App\Enums\ConsentType;
use Database\Factories\LeadConsentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Feature #14 — a Lead's CURRENT effective consent state for one
 * App\Enums\ConsentType, one row per (lead_id, consent_type), upserted on
 * every grant/withdraw (see App\Actions\Tenant\RecordLeadConsent). "Never
 * provided" is the absence of a row entirely, never a third status value
 * here — same "no row and blank are the same state" discipline
 * LeadCustomFieldValue already established.
 *
 * The permanent, append-only history of every state change lives in
 * App\Models\LeadConsentEvent instead — this row only ever reflects "right
 * now," and is safely replaced (never soft-deleted) by a later grant or
 * withdrawal.
 *
 * DB-1 — this row is PRESERVED, not cascade-deleted, when its Lead is hard-
 * deleted: `lead_id` is nullable/nullOnDelete rather than cascadeOnDelete,
 * so it goes NULL instead of the row disappearing. `lead_identifier`/
 * `lead_name` are a point-in-time identity snapshot written only by
 * App\Actions\Tenant\RecordLeadDeletionAudit at the moment of deletion
 * (never during ordinary grant/withdraw — RecordLeadConsent does not touch
 * them) so a detached row stays queryable/identifiable on its own even
 * after its Lead is gone. This model remains a "current state, replaced in
 * place" record, not an append-only history one — see LeadConsentEvent for
 * that.
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
class LeadConsent extends Model
{
    /** @use HasFactory<LeadConsentFactory> */
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
