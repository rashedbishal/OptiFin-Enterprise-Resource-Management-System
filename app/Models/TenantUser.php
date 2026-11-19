<?php

namespace App\Models;

use App\Concerns\HasModulePermissions;
use App\Enums\TenantUserType;
use Database\Factories\TenantUserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use NotificationChannels\WebPush\HasPushSubscriptions;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property string $id
 * @property string $tenant_id
 * @property TenantUserType $user_type
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string $role_id
 * @property bool $is_active
 * @property Carbon|null $deactivated_by_platform_at
 * @property bool $is_whatsapp_backup_receiver
 * @property Carbon|null $softphone_last_seen_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Role $role
 */
#[Fillable(['tenant_id', 'name', 'email', 'password', 'user_type', 'role_id', 'is_active', 'deactivated_by_platform_at', 'is_whatsapp_backup_receiver', 'last_assigned_at'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class TenantUser extends Authenticatable
{
    /** @use HasFactory<TenantUserFactory> */
    use HasApiTokens, HasFactory, HasModulePermissions, HasPushSubscriptions, HasUlids, LogsActivity, Notifiable, TwoFactorAuthenticatable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_type' => TenantUserType::class,
            'is_active' => 'boolean',
            'deactivated_by_platform_at' => 'datetime',
            'is_whatsapp_backup_receiver' => 'boolean',
            'softphone_last_seen_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_assigned_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * The one phone this user has paired with the WhatsApp calling
     * companion app, if any (see CallingAppDevice).
     *
     * @return HasOne<CallingAppDevice, $this>
     */
    public function callingAppDevice(): HasOne
    {
        return $this->hasOne(CallingAppDevice::class);
    }

    public function assignedLeads(): HasMany
    {
        return $this->hasMany(Lead::class, 'assigned_to');
    }

    public function isClientAdmin(): bool
    {
        return $this->user_type === TenantUserType::ClientAdmin;
    }

    /**
     * Transient, in-memory only — never persisted (not a database column,
     * not in #[Fillable] above). Set momentarily by
     * App\Actions\Platform\ManageClientAdminActivation::deactivate() right
     * before it calls update() on the tenant's own Client Admin, so
     * TenantUserObserver's "can't deactivate the tenant's last active
     * owner" guard (meant to stop a Client Admin disabling themselves —
     * see TenantUserPolicy::update()) doesn't also block this one
     * deliberate, Platform-Admin-initiated action. Nothing else should
     * ever set this to true.
     */
    public bool $allowsPlatformOwnerActivationChange = false;

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'is_active', 'role_id', 'is_whatsapp_backup_receiver'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
