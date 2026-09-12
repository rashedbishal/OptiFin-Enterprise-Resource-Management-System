<?php

namespace App\Models;

use Database\Factories\LeadSourceCostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Feature #12 — one tenant-recorded spend figure for one LeadSource for one
 * calendar month. Tenant self-service, like LeadTag/LeadScoringRule/
 * LeadCustomFieldDefinition — this is the tenant's own recorded marketing
 * spend, not Platform-Admin-configured source infrastructure (credentials/
 * webhook state stay on LeadSource itself, untouched by this model). No
 * BelongsToTenant trait: every query filters tenant_id explicitly instead,
 * same discipline as those three siblings.
 *
 * Every row for the same lead_source_id must share the same currency_id —
 * enforced in App\Livewire\Tenant\LeadSourceCosts\Index::save(), never at
 * the schema level — so a source's cost history is always in one currency
 * by construction, never blended or converted.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $lead_source_id
 * @property Carbon $month
 * @property string $amount
 * @property string $currency_id
 * @property-read Tenant $tenant
 * @property-read LeadSource $source
 * @property-read Currency $currency
 */
#[Fillable(['tenant_id', 'lead_source_id', 'month', 'amount', 'currency_id'])]
class LeadSourceCost extends Model
{
    /** @use HasFactory<LeadSourceCostFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'month' => 'date',
            'amount' => 'decimal:2',
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
     * @return BelongsTo<LeadSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'lead_source_id');
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
