<?php

namespace App\Models;

use App\Enums\TokenCheckStatus;
use Database\Factories\LeadSourceTokenCheckFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Latest result of the daily, read-only Meta token check for one lead source
 * (App\Services\MetaTokenChecker), shown on Admin → Monitoring → Integrations.
 * Never holds the token itself.
 *
 * @property string $id
 * @property string $lead_source_id
 * @property TokenCheckStatus $status
 * @property Carbon|null $expires_at
 * @property bool $expiry_known
 * @property string|null $error
 * @property Carbon $checked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read LeadSource $leadSource
 */
#[Fillable(['lead_source_id', 'status', 'expires_at', 'expiry_known', 'error', 'checked_at'])]
class LeadSourceTokenCheck extends Model
{
    /** @use HasFactory<LeadSourceTokenCheckFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TokenCheckStatus::class,
            'expires_at' => 'datetime',
            'expiry_known' => 'boolean',
            'checked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<LeadSource, $this>
     */
    public function leadSource(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class);
    }
}
