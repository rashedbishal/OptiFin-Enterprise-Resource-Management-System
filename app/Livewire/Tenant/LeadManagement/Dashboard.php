<?php

namespace App\Livewire\Tenant\LeadManagement;

use App\Enums\LeadSourceType;
use App\Models\Lead;
use App\Models\LeadCustomFieldDefinition;
use App\Models\LeadScoringRule;
use App\Models\LeadSource;
use App\Models\LeadSourceCost;
use App\Models\MessengerMessageTemplate;
use App\Models\WebsiteForm;
use App\Models\WhatsAppMessageTemplate;
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

        // The hub is the Client Admin's module dashboard. Staff/employee
        // users (non-admins) work their leads day-to-day on the Kanban
        // board (tenant-leads.index renders it for them), so the module
        // entry point drops them straight there instead of showing admin
        // catalog tiles they can't act on. Admin-only gate inside the
        // branch is deliberate: this runs AFTER viewAny, so a staff user
        // without even view permission still gets the 403, not a redirect.
        if (! Auth::user()->isClientAdmin()) {
            $this->redirect(route('tenant-leads.index'), navigate: true);
        }
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
     * WebsiteFormPolicy::viewAny all share it). The owner role always holds
     * the module's full permission set (granted by AssignModuleToTenant),
     * so this is true for every admin who can actually reach the hub —
     * staff users are redirected away in mount() before the view renders.
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
    public function totalScoringRules(): int
    {
        return LeadScoringRule::query()->where('tenant_id', Auth::user()->tenant_id)->count();
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

    #[Computed]
    public function hasCustomRingtone(): bool
    {
        return Auth::user()->tenant->whatsapp_call_ringtone_path !== null;
    }

    /**
     * Gates the "Calling Settings"/"WhatsApp Templates" tiles' very
     * presence (not just their lock badge) — a tenant with no WhatsApp
     * lead source can never place/receive a WhatsApp call or message, so
     * either setting would be a dead end.
     */
    #[Computed]
    public function hasWhatsAppSource(): bool
    {
        return LeadSource::query()
            ->where('tenant_id', Auth::user()->tenant_id)
            ->where('type', LeadSourceType::Whatsapp)
            ->exists();
    }

    #[Computed]
    public function totalWhatsAppTemplates(): int
    {
        return WhatsAppMessageTemplate::query()->where('tenant_id', Auth::user()->tenant_id)->count();
    }

    /**
     * Gates the "Messenger Templates" tile's very presence, same reasoning
     * as hasWhatsAppSource() — a tenant with no Messenger lead source can
     * never message through it, so a template catalog for it would be a
     * dead end.
     */
    #[Computed]
    public function hasMessengerSource(): bool
    {
        return LeadSource::query()
            ->where('tenant_id', Auth::user()->tenant_id)
            ->where('type', LeadSourceType::FacebookMessenger)
            ->exists();
    }

    #[Computed]
    public function totalMessengerTemplates(): int
    {
        return MessengerMessageTemplate::query()->where('tenant_id', Auth::user()->tenant_id)->count();
    }
}
