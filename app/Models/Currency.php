<?php

namespace App\Models;

use Database\Factories\CurrencyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A Platform-Admin-managed catalog entry, same shape as LeadStatus: not
 * tenant-scoped itself, shared across every tenant that enables it via
 * `tenant_currencies` (see Tenant::currencies()). A currency in use —
 * enabled by any tenant, or set on any Lead's estimated_budget_currency_id
 * / deal_value_currency_id — can't be deleted (enforced in
 * App\Livewire\Platform\LeadManagement\Settings::deleteCurrency()).
 *
 * @property string $id
 * @property string $code
 * @property string $name
 */
#[Fillable(['code', 'name'])]
class Currency extends Model
{
    /** @use HasFactory<CurrencyFactory> */
    use HasFactory, HasUlids;

    /**
     * @return BelongsToMany<Tenant, $this>
     */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_currencies')->withTimestamps();
    }

    public function label(): string
    {
        return "{$this->code} — {$this->name}";
    }
}
