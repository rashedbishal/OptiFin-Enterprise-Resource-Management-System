<?php

namespace App\Models;

use Database\Factories\CurrencyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use NumberFormatter;

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

    /**
     * Narrow symbols for currencies ICU's "en" locale only renders as their
     * ISO code (e.g. BDT → "BDT" rather than "৳").
     *
     * @var array<string, string>
     */
    private const FALLBACK_SYMBOLS = [
        'BDT' => '৳',
        'PKR' => '₨',
        'NPR' => '₨',
        'LKR' => '₨',
        'NGN' => '₦',
        'PHP' => '₱',
        'THB' => '฿',
        'UAH' => '₴',
        'TRY' => '₺',
        'RUB' => '₽',
        'KZT' => '₸',
        'GHS' => '₵',
        'MYR' => 'RM',
        'IDR' => 'Rp',
        'ZAR' => 'R',
        'AED' => 'د.إ',
        'SAR' => '﷼',
        'QAR' => '﷼',
    ];

    public function label(): string
    {
        return "{$this->code} — {$this->name}";
    }

    /**
     * The currency's display symbol (e.g. "$", "€", "৳"), falling back to
     * its ISO code when no symbol is known.
     */
    public function symbol(): string
    {
        $code = strtoupper($this->code);

        if (class_exists(NumberFormatter::class)) {
            $formatter = new NumberFormatter("en@currency={$code}", NumberFormatter::CURRENCY);
            $symbol = $formatter->getSymbol(NumberFormatter::CURRENCY_SYMBOL);

            if ($symbol !== '' && $symbol !== $code) {
                return $symbol;
            }
        }

        return self::FALLBACK_SYMBOLS[$code] ?? $code;
    }

    /**
     * Compact "symbol code" label for currency pickers, e.g. "$ USD" — just
     * the code when the currency has no symbol distinct from it.
     */
    public function symbolLabel(): string
    {
        $symbol = $this->symbol();

        return $symbol === strtoupper($this->code) ? $this->code : "{$symbol} {$this->code}";
    }
}
