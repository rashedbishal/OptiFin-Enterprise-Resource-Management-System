<?php

namespace App\Models;

use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory, HasUlids, LogsActivity;

    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'is_system',
        'is_owner_role',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_owner_role' => 'boolean',
        ];
    }

    /**
     * A role is platform-scoped when it has no tenant.
     */
    public function isPlatformScoped(): bool
    {
        return $this->tenant_id === null;
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'role_modules');
    }

    public function modulePermissions(): HasMany
    {
        return $this->hasMany(RoleModulePermission::class);
    }

    public function platformUsers(): HasMany
    {
        return $this->hasMany(PlatformUser::class);
    }

    public function tenantUsers(): HasMany
    {
        return $this->hasMany(TenantUser::class);
    }

    public function hasModule(string $moduleSlug): bool
    {
        return $this->modules()->where('slug', $moduleSlug)->exists();
    }

    public function hasPermissionForModule(string $moduleSlug, string $permissionSlug): bool
    {
        return $this->modulePermissions()
            ->whereHas('module', fn ($query) => $query->where('slug', $moduleSlug))
            ->whereHas('permission', fn ($query) => $query->where('slug', $permissionSlug))
            ->exists();
    }

    /**
     * This role's granted permission ids, grouped by module id, for
     * pre-filling the per-module permission form when editing a role.
     *
     * @return array<string, array<int, string>>
     */
    public function permissionIdsByModule(): array
    {
        return $this->modulePermissions
            ->groupBy('module_id')
            ->map(fn (Collection $grants) => $grants->pluck('permission_id')->all())
            ->all();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'slug'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
