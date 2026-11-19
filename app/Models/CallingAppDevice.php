<?php

namespace App\Models;

use App\Enums\TenantStatus;
use Database\Factories\CallingAppDeviceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * WhatsApp calling companion app — the one phone a TenantUser has paired
 * (product decision: at most one per user, re-pairing replaces it). The app
 * has no login screen; it is linked once from the user's already-logged-in
 * browser via App\Actions\Tenant\PairCallingAppDevice and then
 * authenticates every calling API request with the Sanctum token below.
 *
 * No BelongsToTenant global scope (the app's API requests never bind a
 * tenant before resolving the device) — every query filters tenant_id or
 * tenant_user_id explicitly, same as Conversation/Message/LeadCall.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $tenant_user_id
 * @property int $personal_access_token_id
 * @property string|null $device_name
 * @property string|null $browser_marker_hash
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read TenantUser $tenantUser
 * @property-read PersonalAccessToken $token
 */
#[Fillable(['tenant_id', 'tenant_user_id', 'personal_access_token_id', 'device_name', 'browser_marker_hash', 'last_seen_at'])]
class CallingAppDevice extends Model
{
    /** @use HasFactory<CallingAppDeviceFactory> */
    use HasFactory, HasUlids;

    /**
     * The Sanctum token ability every calling-app token carries — the
     * calling-app API routes require it, so a token minted for anything
     * else in the future could never reach them (and vice versa).
     */
    public const TOKEN_ABILITY = 'calling-app';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * The same "account usable" rule EnsureTenantActive applies to browser
     * sessions (user active AND tenant active), shared by pairing and by
     * every calling-app API request. That middleware can't run on the app's
     * session-less API (it logs out and redirects), so the rule lives here
     * for both callers rather than being re-typed in each.
     */
    public static function userMayUse(TenantUser $user): bool
    {
        $tenant = $user->tenant;

        return $user->is_active && $tenant instanceof Tenant && $tenant->status === TenantStatus::Active;
    }

    /**
     * @return BelongsTo<TenantUser, $this>
     */
    public function tenantUser(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class);
    }

    /**
     * @return BelongsTo<PersonalAccessToken, $this>
     */
    public function token(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'personal_access_token_id');
    }
}
