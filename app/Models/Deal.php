<?php

namespace App\Models;

use App\Enums\DealStatus;
use Database\Factories\DealFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One specific commercial opportunity tied to a Lead — a Lead may have many
 * Deals over its lifetime (repeat business, upsells, a lost deal followed by
 * a fresh one), see Lead::deals()/currentDeal(). Deliberately independent of
 * Lead's own configurable status pipeline (LeadStatus) — see
 * App\Enums\DealStatus's docblock for why. No permission class of its own:
 * every Deal action is authorized against its parent Lead via the existing
 * LeadPolicy (see App\Livewire\Tenant\Leads\Show's Deal methods).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $lead_id
 * @property string $name
 * @property string|null $value
 * @property string|null $currency_id
 * @property int|null $probability
 * @property DealStatus $status
 * @property Carbon|null $expected_close_date
 * @property Carbon|null $actual_close_date
 * @property string|null $owner_id
 * @property string|null $created_by
 * @property string|null $notes
 * @property-read Lead $lead
 * @property-read Currency|null $currency
 * @property-read TenantUser|null $owner
 * @property-read TenantUser|null $createdBy
 */
#[Fillable([
    'tenant_id', 'lead_id', 'name', 'value', 'currency_id', 'probability', 'status',
    'expected_close_date', 'actual_close_date', 'owner_id', 'created_by', 'notes',
])]
class Deal extends Model
{
    /** @use HasFactory<DealFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'probability' => 'integer',
            'status' => DealStatus::class,
            'expected_close_date' => 'date',
            'actual_close_date' => 'date',
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
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * @return BelongsTo<TenantUser, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'owner_id');
    }

    /**
     * @return BelongsTo<TenantUser, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'created_by');
    }

    /**
     * @return HasMany<DealStatusLog, $this>
     */
    public function statusLogs(): HasMany
    {
        return $this->hasMany(DealStatusLog::class);
    }
}
