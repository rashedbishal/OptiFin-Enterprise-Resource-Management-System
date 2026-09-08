<?php

namespace App\Models;

use App\Enums\LeadDuplicateLinkStatus;
use Database\Factories\LeadDuplicateLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row per detected same-tenant pair of possibly-duplicate leads, ever —
 * see the migration for why `lead_id`/`duplicate_lead_id` are always stored
 * in a canonical order (pairKeys() below). Created by
 * App\Services\LeadDuplicateDetectionService, resolved (dismissed or merged)
 * from App\Livewire\Tenant\Leads\Show, and the actual data movement for a
 * merge happens in App\Services\LeadMergeService — this row is the audit
 * trail of that decision, not the mechanism itself.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $lead_id
 * @property string $duplicate_lead_id
 * @property LeadDuplicateLinkStatus $status
 * @property array<int, string>|null $matched_fields
 * @property Carbon|null $detected_at
 * @property string|null $resolved_by
 * @property Carbon|null $resolved_at
 * @property array<string, array{chosen: string, survivor_value: mixed, loser_value: mixed}>|null $field_resolutions
 * @property-read Lead $lead
 * @property-read Lead $duplicateLead
 * @property-read TenantUser|null $resolvedBy
 */
#[Fillable(['tenant_id', 'lead_id', 'duplicate_lead_id', 'status', 'matched_fields', 'detected_at', 'resolved_by', 'resolved_at', 'field_resolutions'])]
class LeadDuplicateLink extends Model
{
    /** @use HasFactory<LeadDuplicateLinkFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LeadDuplicateLinkStatus::class,
            'matched_fields' => 'array',
            'detected_at' => 'datetime',
            'resolved_at' => 'datetime',
            'field_resolutions' => 'array',
        ];
    }

    /**
     * Orders two leads' IDs consistently regardless of which one triggered
     * detection first, so the same pair can never produce two rows (see the
     * table's unique(['lead_id', 'duplicate_lead_id']) constraint) — ULIDs
     * sort lexicographically as plain strings, so a simple string comparison
     * is enough.
     *
     * @return array{0: string, 1: string}
     */
    public static function pairKeys(Lead $a, Lead $b): array
    {
        return $a->id <= $b->id ? [$a->id, $b->id] : [$b->id, $a->id];
    }

    /**
     * The other lead in this pair, relative to $lead — for a viewer who only
     * knows "the lead I'm looking at", not which side of the canonical pair
     * it happens to be stored as.
     */
    public function otherLead(Lead $lead): Lead
    {
        return $this->lead_id === $lead->id ? $this->duplicateLead : $this->lead;
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function duplicateLead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'duplicate_lead_id');
    }

    /**
     * @return BelongsTo<TenantUser, $this>
     */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'resolved_by');
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function merged(Builder $query): void
    {
        $query->where('status', LeadDuplicateLinkStatus::Merged);
    }
}
