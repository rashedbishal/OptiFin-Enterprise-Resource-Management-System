<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One platform-wide (vendor-level, never per-tenant) setting, managed from
 * the admin panel instead of the server's .env. `value` is ALWAYS encrypted
 * at rest (APP_KEY) — these hold secrets such as the calling app's Firebase
 * service account (see App\Services\CallingAppFirebase).
 *
 * @property string $id
 * @property string $key
 * @property array<string, mixed>|null $value
 * @property string|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PlatformUser|null $updatedBy
 */
#[Fillable(['key', 'value', 'updated_by'])]
class PlatformSetting extends Model
{
    use HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'encrypted:array',
        ];
    }

    /**
     * @return BelongsTo<PlatformUser, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'updated_by');
    }
}
