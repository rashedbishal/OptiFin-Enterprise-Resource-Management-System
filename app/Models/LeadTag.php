<?php

namespace App\Models;

use Database\Factories\LeadTagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A tenant's own tag catalog entry — tenant self-service, like
 * LeadScoringRule, not a Platform-Admin-managed shared catalog like
 * LeadStatus/Currency (see the create_lead_tags_table migration). No
 * BelongsToTenant trait: every query filters tenant_id explicitly instead
 * (same discipline LeadScoringRule/its Livewire component already follow),
 * since BelongsToTenant's global scope only activates when
 * app()->bound('currentTenant').
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $slug
 * @property string $color
 * @property-read Tenant $tenant
 */
#[Fillable(['tenant_id', 'name', 'slug', 'color'])]
class LeadTag extends Model
{
    /** @use HasFactory<LeadTagFactory> */
    use HasFactory, HasUlids;

    /**
     * The same fixed, Tailwind-class-mapped palette LeadStatus badges use —
     * kept in lock-step with LeadStatus::availableColors() by delegating to
     * it rather than duplicating the list, since a tag chip is rendered via
     * a plain flux:badge :color prop and needs no custom class mapping of
     * its own.
     *
     * @return array<int, string>
     */
    public static function availableColors(): array
    {
        return LeadStatus::availableColors();
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsToMany<Lead, $this>
     */
    public function leads(): BelongsToMany
    {
        return $this->belongsToMany(Lead::class)->withPivot('tagged_by')->withTimestamps();
    }
}
