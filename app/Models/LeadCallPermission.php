<?php

namespace App\Models;

use App\Enums\LeadCallPermissionStatus;
use Database\Factories\LeadCallPermissionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * WhatsApp Outbound Calling — a Lead's CURRENT effective WhatsApp call
 * permission state, one row per lead (see the migration for the full
 * mapping/rationale). This row is bookkeeping/UI state only: the backend
 * never treats it as authoritative for a consequential Meta action (sending
 * a permission request, or calling connect) — every such action re-checks
 * live against Meta first. See App\Actions\Tenant\{CheckLeadCallPermission,
 * RequestLeadCallPermission,RecordCallPermissionReply}.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $lead_id
 * @property string $lead_source_id
 * @property LeadCallPermissionStatus $status
 * @property string|null $meta_raw_status
 * @property Carbon|null $expires_at
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $last_requested_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Lead $lead
 * @property-read LeadSource $leadSource
 */
#[Fillable([
    'tenant_id', 'lead_id', 'lead_source_id', 'status', 'meta_raw_status',
    'expires_at', 'last_synced_at', 'last_requested_at',
])]
class LeadCallPermission extends Model
{
    /** @use HasFactory<LeadCallPermissionFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LeadCallPermissionStatus::class,
            'expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'last_requested_at' => 'datetime',
        ];
    }

    /**
     * Whether this Lead can be called RIGHT NOW, purely from local state —
     * belt-and-suspenders bookkeeping only. Never the gate before an actual
     * Meta action: a live GET .../call_permissions always runs first there,
     * since Meta sends no webhook when a temporary permission expires and
     * this column can therefore be stale in exactly that direction.
     */
    public function effectivelyGranted(?Carbon $now = null): bool
    {
        $now ??= Carbon::now();

        return $this->status === LeadCallPermissionStatus::Permanent
            || ($this->status === LeadCallPermissionStatus::Temporary && $this->expires_at !== null && $this->expires_at->isAfter($now));
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<LeadSource, $this>
     */
    public function leadSource(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class);
    }
}
