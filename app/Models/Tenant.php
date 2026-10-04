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
use Illuminate\Support\Str;
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
        'lead_id_prefix',
        'contact_name',
        'email',
        'phone',
        'address',
        'country',
        'timezone',
        'status',
        'created_by',
        'lead_distribution_mode',
        'auto_distribution_method',
        'meeting_alert_enabled',
        'meeting_alert_days_before',
        'followup_alert_enabled',
        'followup_alert_days_before',
        'task_alert_enabled',
        'task_alert_days_before',
        'lead_nurturing_notify_enabled',
        'lead_nurturing_task_enabled',
        'lead_nurturing_inactive_days',
        'whatsapp_call_ringtone_path',
        'whatsapp_call_ringtone_original_name',
        'whatsapp_call_ringtone_size',
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
            'meeting_alert_enabled' => 'boolean',
            'meeting_alert_days_before' => 'integer',
            'followup_alert_enabled' => 'boolean',
            'followup_alert_days_before' => 'integer',
            'task_alert_enabled' => 'boolean',
            'task_alert_days_before' => 'integer',
            'lead_nurturing_notify_enabled' => 'boolean',
            'lead_nurturing_task_enabled' => 'boolean',
            'lead_nurturing_inactive_days' => 'integer',
            'whatsapp_call_ringtone_size' => 'integer',
        ];
    }

    /**
     * The tenant-name portion of every one of this tenant's lead
     * identifiers ({prefix}-LEAD-{n}, see Lead::booted()) — generated once
     * when the tenant is created (App\Actions\Platform\CreateClient) and
     * never changed afterwards, so a tenant's leads keep stable identifiers
     * for its whole lifetime. Just the first word of the name, uppercased/
     * slugified ("Opticore Solutions Ltd" → "OPTICORE", "Acme" → "ACME"),
     * with a numeric suffix appended if another tenant's first word already
     * produced that same slug, since `name` itself isn't unique but this
     * must be.
     */
    public static function generateLeadIdPrefix(string $name): string
    {
        $firstWord = explode(' ', trim($name), 2)[0];
        $base = strtoupper(Str::slug($firstWord));

        if ($base === '') {
            $base = 'TENANT';
        }

        $prefix = $base;

        for ($suffix = 2; static::where('lead_id_prefix', $prefix)->exists(); $suffix++) {
            $prefix = "{$base}-{$suffix}";
        }

        return $prefix;
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
     * The currencies this tenant can pick from for a lead's Est. Budget /
     * Deal Value — a subset of the Platform-Admin-managed Currency catalog,
     * picked on the client's Lead Sources config page. Every tenant must
     * have at least one (enforced in
     * App\Livewire\Platform\Clients\LeadSources::toggleCurrency() and
     * relied on by LeadSources::create()/save(), which refuse to create a
     * new Lead Source for a tenant with none selected).
     *
     * @return BelongsToMany<Currency, $this>
     */
    public function currencies(): BelongsToMany
    {
        return $this->belongsToMany(Currency::class, 'tenant_currencies')->withTimestamps();
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
