<?php

namespace App\Models;

use App\Enums\AutoDistributionMethod;
use App\Enums\LeadDistributionMode;
use App\Enums\TenantStatus;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, HasUlids, LogsActivity;

    protected $fillable = [
        'name',
        'legal_name',
        'tenant_code',
        'contact_name',
        'email',
        'phone',
        'address',
        'country',
        'currency',
        'timezone',
        'status',
        'created_by',
        'lead_distribution_mode',
        'auto_distribution_method',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'lead_distribution_mode' => LeadDistributionMode::class,
            'auto_distribution_method' => AutoDistributionMethod::class,
        ];
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    public function tenantUsers(): HasMany
    {
        return $this->hasMany(TenantUser::class);
    }

    /**
     * @return HasMany<LeadSource, $this>
     */
    public function leadSources(): HasMany
    {
        return $this->hasMany(LeadSource::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'tenant_modules')
            ->withPivot(['is_active', 'assigned_by', 'assigned_at'])
            ->withTimestamps();
    }

    public function activeModules(): BelongsToMany
    {
        return $this->modules()->wherePivot('is_active', true);
    }

    public function hasModule(string $moduleSlug): bool
    {
        return $this->activeModules()->where('slug', $moduleSlug)->exists();
    }

    /**
     * This tenant's configured lead pipeline, in order — the Platform
     * Admin selects the subset of the global LeadStatus catalog and its
     * order on the client's Lead Sources config page.
     *
     * @return BelongsToMany<LeadStatus, $this>
     */
    public function leadStatuses(): BelongsToMany
    {
        return $this->belongsToMany(LeadStatus::class, 'tenant_lead_statuses')
            ->withPivot('position')
            ->withTimestamps()
            ->orderByPivot('position');
    }

    /**
     * The pipeline's first stage — every newly captured lead (webhook,
     * email, manual entry) starts here, replacing the old hardcoded
     * LeadStatus::New default.
     */
    public function initialLeadStatus(): ?LeadStatus
    {
        return $this->leadStatuses()->orderByPivot('position')->first();
    }

    /**
     * The tenant's protected, non-deletable owner role.
     */
    public function ownerRole(): ?Role
    {
        return $this->roles()->where('is_owner_role', true)->first();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'legal_name', 'status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
