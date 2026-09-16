<?php

namespace App\Livewire\Tenant\LeadManagement;

use App\Models\Lead;
use App\Models\LeadCustomFieldDefinition;
use App\Models\LeadSource;
use App\Models\LeadSourceCost;
use App\Models\WebsiteForm;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Lead Management module hub — the module's dashboard page. Every other
 * page of the module (Leads table, Lead Sources, Custom Fields, Source
 * Costs, Website Forms, ...) is a nested page reached from here; this page
 * itself only surfaces entry links plus read-only module stats.
 *
 * Authorization intentionally mirrors the loosest gate of any nested page
 * (LeadPolicy::viewAny — module "view" permission), so every user who can
 * see the module at all can see the hub; each nested page still applies its
 * own, stricter gate on arrival (e.g. Client-Admin-only catalogs).
 */
#[Title('Lead Management')]
class Dashboard extends Component
{
    public function mount(): void
    {
        Gate::authorize('viewAny', Lead::class);
    }

    /**
     * Same bar LeadPolicy::create() uses, driving the "Import Leads" button
     * — a user who couldn't create a lead has no use for the importer.
     */
    #[Computed]
    public function canCreateLead(): bool
    {
        return Gate::allows('create', Lead::class);
    }

    /**
     * Same "call it as a method from PHP, property-style from Blade"
     * #[Computed] convention as FiltersLeadsQuery::isClientAdmin() — used
     * in the view only for the "Reports & Analytics" hero button's
     * visibility, matching LeadReports\Index::mount()'s own gate.
     */
    #[Computed]
    public function isClientAdmin(): bool
    {
        return Auth::user()->isClientAdmin();
    }

    /**
     * The Client-Admin-only bar every catalog page below the hub enforces
     * on arrival (LeadCustomFieldDefinitionPolicy / LeadSourceCostPolicy /
     * WebsiteFormPolicy::viewAny all share it) — used only to soften those
     * tiles' entry-count badge into a "who manages this" hint. Route
     * access itself is always enforced server-side by the destination page.
     */
    #[Computed]
    public function canManageCatalogs(): bool
    {
        $user = Auth::user();

        return $user->isClientAdmin() && $user->hasModulePermission('lead-management', 'update');
    }

    #[Computed]
    public function totalLeads(): int
    {
        return Lead::query()->where('tenant_id', Auth::user()->tenant_id)->count();
    }

    #[Computed]
    public function totalSources(): int
    {
        return LeadSource::query()->where('tenant_id', Auth::user()->tenant_id)->count();
    }

    #[Computed]
    public function totalCustomFields(): int
    {
        return LeadCustomFieldDefinition::query()->where('tenant_id', Auth::user()->tenant_id)->count();
    }

    #[Computed]
    public function totalWebsiteForms(): int
    {
        return WebsiteForm::query()->where('tenant_id', Auth::user()->tenant_id)->count();
    }

    #[Computed]
    public function totalSourceCostEntries(): int
    {
        return LeadSourceCost::query()->where('tenant_id', Auth::user()->tenant_id)->count();
    }
}
