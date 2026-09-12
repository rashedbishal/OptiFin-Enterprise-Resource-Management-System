<?php

namespace App\Models;

use Database\Factories\WebsiteFormFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Feature #13 — one tenant-designed public lead-capture form. Tenant
 * self-service, like LeadTag/LeadScoringRule/LeadCustomFieldDefinition/
 * LeadSourceCost: direct tenant_id, no BelongsToTenant trait, every query
 * filters tenant_id explicitly instead. A tenant may have any number of
 * these — Phase 1 deliberately supports multiple forms per tenant from the
 * start, never a single-form-per-tenant assumption.
 *
 * This app never hosts or renders the form itself — the tenant's own
 * developer builds the HTML and POSTs to this form's public submission
 * endpoint (see App\Http\Controllers\Api\LeadWebhookController::
 * websiteFormSubmit()), authenticated by `token_hash` rather than by
 * anything client-supplied.
 *
 * Every WebsiteForm for a tenant — and that tenant's pre-existing legacy
 * Website Form webhook integration, if any — shares ONE `LeadSource` of
 * type WebsiteForm for attribution (see leadSource()'s docblock). Which
 * specific form produced a given Lead is recorded separately, as a
 * point-in-time snapshot in that Lead's own `source_data` (never a hard FK
 * from Lead to WebsiteForm), so deleting a form never touches any existing
 * Lead.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $lead_source_id
 * @property string $name
 * @property string $token_hash
 * @property bool $is_active
 * @property-read Tenant $tenant
 * @property-read LeadSource $leadSource
 */
#[Fillable(['tenant_id', 'lead_source_id', 'name', 'token_hash', 'is_active'])]
class WebsiteForm extends Model
{
    /** @use HasFactory<WebsiteFormFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * The tenant's ONE shared "Website Form" LeadSource — lazily created
     * (firstOrCreate) the first time a tenant creates a WebsiteForm, the
     * same way CreateManualLead/CreateLeadFromImport lazily provision their
     * own singleton sources. A tenant that already has a legacy Website
     * Form source (Platform-Admin-configured, with its own api_key/
     * allowed_domains) gets that same row reused, not a second one — so
     * Source Performance/Cost reporting always shows one combined
     * "Website Form" total across both the legacy integration and every
     * builder-managed form, by design (see docs/lead-management/PROGRESS.md
     * Feature #13 entry for why per-form reporting is deferred).
     *
     * @return BelongsTo<LeadSource, $this>
     */
    public function leadSource(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class);
    }

    /**
     * @return HasMany<WebsiteFormField, $this>
     */
    public function fields(): HasMany
    {
        return $this->hasMany(WebsiteFormField::class);
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
