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

    /**
     * The inverse of LeadSource::unassignedRoles() — every lead source this
     * role has been granted "receive unassigned leads" access to.
     *
     * @return BelongsToMany<LeadSource, $this>
     */
    public function unassignedLeadSources(): BelongsToMany
    {
        return $this->belongsToMany(LeadSource::class, 'lead_source_unassigned_roles')->withTimestamps();
    }

    /**
     * Per-instance, per-request memoization for hasModule()/
     * hasPermissionForModule() below — a real, measured bottleneck: a
     * single page render can call these dozens of times (once per
     * canUpdate()/canAssign()/canDelete()/per-row policy check across a
     * rich page like Tenant\Leads\Show), and neither method cached its own
     * result, so every call was a fresh query even when asking the exact
     * same question twice in the same request. Safe to cache: a role's
     * module/permission grants never change mid-request (role editing
     * happens in a separate Livewire component, in a separate request),
     * and this app doesn't run Octane, so there's no risk of a cached
     * value leaking into a later, unrelated request — a fresh Role
     * instance (and therefore a fresh, empty cache) is resolved every
     * time. Keyed by slug, not module/permission id, matching these
     * methods' own string parameters.
     *
     * @var array<string, bool>
     */
    private array $hasModuleCache = [];

    /**
     * @var array<string, bool>
     */
    private array $hasPermissionForModuleCache = [];

    public function hasModule(string $moduleSlug): bool
    {
        return $this->hasModuleCache[$moduleSlug] ??= $this->modules()->where('slug', $moduleSlug)->exists();
    }

    public function hasPermissionForModule(string $moduleSlug, string $permissionSlug): bool
    {
        $key = $moduleSlug.':'.$permissionSlug;

        return $this->hasPermissionForModuleCache[$key] ??= $this->modulePermissions()
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
