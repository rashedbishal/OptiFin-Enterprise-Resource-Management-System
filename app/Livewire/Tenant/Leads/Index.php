<?php

namespace App\Livewire\Tenant\Leads;

use App\Actions\Tenant\CreateManualLead;
use App\Enums\LeadPriority;
use App\Enums\LeadSourceType;
use App\Events\LeadStatusChanged;
use App\Events\LeadUpdated;
use App\Livewire\Tenant\Leads\Concerns\FiltersLeadsQuery;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatusLog;
use App\Models\TenantUser;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Title('Leads')]
class Index extends Component
{
    use FiltersLeadsQuery, WithPagination;

    public string $sourceType = '';

    public bool $showCreateLeadModal = false;

    public string $leadName = '';

    public string $leadCompany = '';

    public string $leadWebsite = '';

    public string $leadIndustry = '';

    public string $leadLocation = '';

    public string $leadServiceInterest = '';

    public string $leadSource = '';

    public string $leadEmail = '';

    public string $leadPhone = '';

    public string $leadMessage = '';

    public string $leadStatus = '';

    public string $leadPriority = '';

    public string $leadAssigneeId = '';

    public string $leadEstimatedBudget = '';

    public string $leadEstimatedBudgetCurrencyId = '';

    public string $leadDealValue = '';

    public string $leadDealValueCurrencyId = '';

    public string $leadWinProbability = '';

    public string $leadNextFollowUpAt = '';

    public string $leadMeetingDate = '';

    /**
     * Caps how many leads are fetched for the Kanban board / mobile list in
     * one query — grouped in PHP afterwards, since a per-column SQL limit
     * would need a window function. `boardLeadCounts()` is queried
     * separately (a cheap grouped count) so column headers stay accurate
     * even if a single status has more open leads than this cap.
     */
    private const BOARD_LEAD_LIMIT = 500;

    public ?string $selectedLeadId = null;

    public bool $showLeadPanel = false;

    public ?string $recentlyCreatedLeadId = null;

    public ?string $recentlyUpdatedLeadId = null;

    public bool $showMeetingAlertModal = false;

    public string $meetingAlertDaysInput = '0';

    public bool $showFollowUpAlertModal = false;

    public string $followUpAlertDaysInput = '0';

    public function mount(): void
    {
        Gate::authorize('viewAny', Lead::class);
    }

    /**
     * Client Admins keep the original filterable table (bulk oversight,
     * pagination, per-row actions); the Kanban board/detail flyout is only
     * for the individual Client User (sales rep) managing their own
     * assigned leads day to day.
     */
    public function render(): View
    {
        return view($this->isClientAdmin ? 'livewire.tenant.leads' : 'livewire.tenant.leads-kanban');
    }

    #[Computed]
    public function canCreateLead(): bool
    {
        return Gate::allows('create', Lead::class);
    }

    /**
     * Whether the current user can update ANY lead they can see here — used
     * to gate the Kanban "…" move menu and the table's per-row actions.
     * Not evaluated per-lead: `update` also requires being assigned to the
     * lead for a non-admin, but every lead reaching this page's queries is
     * already scoped that way (applyAssignmentScope()), so the module
     * permission is the only variable left. Computing it once here instead
     * of calling Gate::allows() per row avoids an N+1 of permission checks
     * across a board with hundreds of cards.
     */
    #[Computed]
    public function canUpdateLeads(): bool
    {
        return Auth::user()->hasModulePermission('lead-management', 'update');
    }

    /**
     * Same reasoning as canUpdateLeads(): `delete` requires isClientAdmin,
     * which is already this page's own $this->isClientAdmin, so the module
     * permission is the only remaining variable.
     */
    #[Computed]
    public function canDeleteLeads(): bool
    {
        return $this->isClientAdmin && Auth::user()->hasModulePermission('lead-management', 'delete');
    }

    /**
     * This tenant's enabled currencies, for the Est. Budget / Deal Value
     * dropdowns — a Platform Admin subset of the Currency catalog, picked
     * on the client's Lead Sources config page (see Tenant::currencies()).
     * Always at least one: LeadSources::create()/save() refuse to let a
     * tenant have zero, and AssignModuleToTenant seeds one the first time
     * Lead Management is activated for a tenant.
     */
    #[Computed]
    public function tenantCurrencies()
    {
        return Auth::user()->tenant->currencies()->orderBy('code')->get();
    }

    public function createLead(): void
    {
        if (Gate::denies('create', Lead::class)) {
            Flux::toast(variant: 'danger', text: __("You don't have permission to create leads."));

            return;
        }

        $this->reset(
            'leadName', 'leadCompany', 'leadWebsite', 'leadIndustry', 'leadLocation', 'leadServiceInterest',
            'leadSource', 'leadEmail', 'leadPhone', 'leadMessage', 'leadAssigneeId',
            'leadEstimatedBudget', 'leadDealValue', 'leadWinProbability', 'leadNextFollowUpAt', 'leadMeetingDate',
        );
        $this->leadStatus = (string) Auth::user()->tenant->initialLeadStatus()?->id;
        $this->leadPriority = LeadPriority::Medium->value;
        // Pre-selected rather than left blank: a tenant almost always has
        // just one enabled currency, so defaulting it here means most
        // users never have to touch the dropdown at all — same reasoning
        // as leadStatus defaulting to the pipeline's first stage above.
        $defaultCurrencyId = (string) $this->tenantCurrencies->first()?->id;
        $this->leadEstimatedBudgetCurrencyId = $defaultCurrencyId;
        $this->leadDealValueCurrencyId = $defaultCurrencyId;
        $this->showCreateLeadModal = true;
    }

    public function saveLead(CreateManualLead $action): void
    {
        if (Gate::denies('create', Lead::class)) {
            Flux::toast(variant: 'danger', text: __("You don't have permission to create leads."));

            return;
        }

        $tenant = Auth::user()->tenant;

        $validated = $this->validate([
            // Letters (incl. accented/Unicode), spaces, apostrophes, periods
            // and hyphens only - covers "Md. Rafiqul Islam", "O'Brien",
            // "Jean-Pierre" without allowing digits or stray symbols.
            'leadName' => ['required', 'string', 'min:2', 'max:255', 'regex:/^[\p{L}\p{M}][\p{L}\p{M}\s\'.-]*$/u'],
            'leadCompany' => ['nullable', 'string', 'max:255', 'regex:/^[\p{L}\p{N}][\p{L}\p{N}\s.,&\'()-]*$/u'],
            'leadWebsite' => ['nullable', 'url', 'max:255', 'regex:#^https?://#i'],
            'leadIndustry' => ['nullable', 'string', 'max:255', 'regex:#^[\p{L}][\p{L}\s&,/-]*$#u'],
            'leadLocation' => ['nullable', 'string', 'max:255', 'regex:/^[\p{L}][\p{L}\s,.-]*$/u'],
            'leadServiceInterest' => ['nullable', 'string', 'max:255', 'regex:#^[\p{L}\p{N}][\p{L}\p{N}\s&,./()-]*$#u'],
            'leadSource' => ['nullable', 'string', 'max:255', 'regex:#^[\p{L}\p{N}][\p{L}\p{N}\s&,./()-]*$#u'],
            'leadEmail' => ['nullable', 'email', 'max:255'],
            // Digits, spaces and the punctuation real phone numbers use
            // (+880 1712-345678, +1 (555) 123-4567); the lookahead requires
            // at least 7 digits so "(--)  --" can't slip through.
            'leadPhone' => ['required', 'regex:/^(?=(?:\D*\d){7,})[0-9+\-\s()]{7,20}$/'],
            'leadMessage' => ['nullable', 'string', 'max:2000'],
            'leadStatus' => ['required', Rule::exists('tenant_lead_statuses', 'lead_status_id')->where('tenant_id', $tenant->id)],
            'leadPriority' => ['required', Rule::enum(LeadPriority::class)],
            'leadAssigneeId' => ['nullable', 'string'],
            // Plain decimal amounts only (no scientific notation, no thousands separators).
            'leadEstimatedBudget' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'leadEstimatedBudgetCurrencyId' => ['nullable', 'string', Rule::exists('tenant_currencies', 'currency_id')->where('tenant_id', $tenant->id)],
            'leadDealValue' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'leadDealValueCurrencyId' => ['nullable', 'string', Rule::exists('tenant_currencies', 'currency_id')->where('tenant_id', $tenant->id)],
            'leadWinProbability' => ['nullable', 'integer', 'min:0', 'max:100'],
            'leadNextFollowUpAt' => ['nullable', 'date', 'after_or_equal:today'],
            'leadMeetingDate' => ['nullable', 'date', 'after_or_equal:today'],
        ], [
            'leadName.regex' => __('Contact person may only contain letters, spaces, apostrophes, periods and hyphens.'),
            'leadCompany.regex' => __('Company name contains characters that are not allowed.'),
            'leadWebsite.regex' => __('Website URL must start with http:// or https://.'),
            'leadIndustry.regex' => __('Industry sector contains characters that are not allowed.'),
            'leadLocation.regex' => __('Location contains characters that are not allowed.'),
            'leadServiceInterest.regex' => __('Service interested contains characters that are not allowed.'),
            'leadSource.regex' => __('Lead source contains characters that are not allowed.'),
            'leadPhone.regex' => __('Enter a valid phone number (at least 7 digits).'),
            'leadEstimatedBudget.regex' => __('Enter a plain amount with up to 2 decimal places.'),
            'leadDealValue.regex' => __('Enter a plain amount with up to 2 decimal places.'),
            'leadNextFollowUpAt.after_or_equal' => __('Next follow-up date cannot be in the past.'),
            'leadMeetingDate.after_or_equal' => __('Meeting date cannot be in the past.'),
        ]);

        $user = Auth::user();

        $assignee = null;

        if ($this->isClientAdmin) {
            if ($this->leadAssigneeId !== '') {
                $assignee = TenantUser::where('tenant_id', $tenant->id)->findOrFail($this->leadAssigneeId);
            }
        } else {
            // Non-admins can only ever assign to themselves (LeadPolicy::assign
            // requires isClientAdmin) and need visibility into what they just
            // created (LeadPolicy::view requires being assigned) — so a lead
            // they create is always self-assigned rather than left unassigned.
            $assignee = $user;
        }

        $createdLead = $action->handle($tenant, [
            'name' => $validated['leadName'],
            'company' => $validated['leadCompany'] ?: null,
            'website' => $validated['leadWebsite'] ?: null,
            'industry' => $validated['leadIndustry'] ?: null,
            'location' => $validated['leadLocation'] ?: null,
            'service_interest' => $validated['leadServiceInterest'] ?: null,
            'lead_source' => $validated['leadSource'] ?: null,
            'email' => $validated['leadEmail'] ?: null,
            'phone' => $validated['leadPhone'],
            'message' => $validated['leadMessage'] ?: null,
            'status_id' => $validated['leadStatus'],
            'priority' => LeadPriority::from($validated['leadPriority']),
            'estimated_budget' => $validated['leadEstimatedBudget'] ?: null,
            // A currency without an amount means nothing — see the matching
            // comment in Show::updateDeal() for why this stays paired with
            // its own amount rather than always following the dropdown.
            'estimated_budget_currency_id' => $validated['leadEstimatedBudget'] ? ($validated['leadEstimatedBudgetCurrencyId'] ?: null) : null,
            'deal_value' => $validated['leadDealValue'] ?: null,
            'deal_value_currency_id' => $validated['leadDealValue'] ? ($validated['leadDealValueCurrencyId'] ?: null) : null,
            'win_probability' => $validated['leadWinProbability'] ?: null,
            'next_follow_up_at' => $validated['leadNextFollowUpAt'] ?: null,
            'meeting_date' => $validated['leadMeetingDate'] ?: null,
        ], $user, $assignee);

        $this->recentlyCreatedLeadId = $createdLead->id;
        $this->recentlyUpdatedLeadId = null;

        $this->showCreateLeadModal = false;
        $this->refreshBoard();
        Flux::toast(variant: 'success', text: __('Lead created.'));
    }

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        return [
            'echo-private:tenant.'.Auth::user()->tenant_id.',.lead.received' => 'onLeadReceived',
            'echo-private:tenant.'.Auth::user()->tenant_id.',.lead.updated' => 'onLeadUpdated',
            'lead-panel-updated' => 'onPanelLeadUpdated',
            'lead-panel-deleted' => 'onPanelLeadDeleted',
        ];
    }

    public function onLeadReceived($payload = null): void
    {
        if (is_array($payload) && isset($payload['lead']['id'])) {
            $this->recentlyCreatedLeadId = $payload['lead']['id'];
            $this->recentlyUpdatedLeadId = null;
        }
        $this->refreshBoard();
    }

    public function onLeadUpdated($payload = null): void
    {
        if (is_array($payload) && isset($payload['lead']['id'])) {
            $this->recentlyUpdatedLeadId = $payload['lead']['id'];
        }
        $this->refreshBoard();
    }

    public function onPanelLeadUpdated(?string $leadId = null): void
    {
        if ($leadId) {
            $this->recentlyUpdatedLeadId = $leadId;
            $this->recentlyCreatedLeadId = null;
        }
        $this->refreshBoard();
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['status', 'priority', 'sourceType', 'assignedTo'], true)) {
            $this->resetPage();
            unset($this->boardLeads, $this->boardLeadCounts, $this->leads);
        }
    }

    /**
     * The Client Admin table view's paginated, unfiltered-by-status list —
     * unlike boardLeads() below, this isn't grouped, since it's rendered as
     * flat table rows with a "Status" column instead of Kanban columns.
     */
    #[Computed]
    public function leads()
    {
        return $this->applyDropdownFilters($this->applyAssignmentScope($this->filteredQuery()))
            ->with(['source', 'assignedUser', 'status', 'estimatedBudgetCurrency', 'dealValueCurrency'])
            ->latest('updated_at')
            ->paginate(25);
    }

    /**
     * All leads matching the current filters/assignment scope, grouped by
     * status for the Kanban columns / mobile status sections. Capped at
     * BOARD_LEAD_LIMIT total rows for performance — see that constant's
     * docblock. `updated*()` hooks below invalidate this whenever a filter
     * changes, since Livewire only recomputes on a fresh request otherwise.
     *
     * @return Collection<string, Collection<int, Lead>>
     */
    #[Computed]
    public function boardLeads()
    {
        $leads = $this->applyDropdownFilters($this->applyAssignmentScope($this->filteredQuery()))
            ->with(['source', 'assignedUser', 'status', 'estimatedBudgetCurrency', 'dealValueCurrency'])
            ->latest()
            ->limit(self::BOARD_LEAD_LIMIT)
            ->get();

        // Plain Support Collection, not Eloquent Collection: once grouped,
        // the values are per-status collections of leads rather than leads
        // themselves, which doesn't fit Eloquent Collection's Model-typed
        // generic.
        return collect($leads)->groupBy(fn (Lead $lead): string => $lead->status_id);
    }

    /**
     * This tenant's configured pipeline, in order — drives the Kanban
     * board's columns/mobile groups and the "Move to…" menu, replacing the
     * old fixed `LeadStatus::cases()`.
     *
     * @return Collection<int, LeadStatus>
     */
    #[Computed]
    public function tenantLeadStatuses()
    {
        return Auth::user()->tenant->leadStatuses;
    }

    /**
     * True per-status counts (unaffected by BOARD_LEAD_LIMIT) so a column
     * header never understates how many leads are actually in that status.
     *
     * @return array<int, array{status: string, count: int}>
     */
    #[Computed]
    public function boardLeadCounts(): array
    {
        return Lead::countsByStatus(
            $this->applyDropdownFilters($this->applyAssignmentScope($this->filteredQuery())),
            $this->tenantLeadStatuses,
        );
    }

    public function refreshBoard(): void
    {
        unset($this->boardLeads, $this->boardLeadCounts, $this->leads);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'priority', 'sourceType', 'assignedTo'], true)) {
            $this->resetPage();
            $this->refreshBoard();
        }

        // Est. Budget and Deal Value are almost always quoted in the same
        // currency, and a mismatched pair here is more often a slip than
        // an intentional choice — so the two pickers stay mirrored rather
        // than independent, whichever one the user changes.
        if ($property === 'leadEstimatedBudgetCurrencyId') {
            $this->leadDealValueCurrencyId = $this->leadEstimatedBudgetCurrencyId;
        }

        if ($property === 'leadDealValueCurrencyId') {
            $this->leadEstimatedBudgetCurrencyId = $this->leadDealValueCurrencyId;
        }

        // The flyout was closed (Flux's own close button, backdrop click or
        // Escape all sync this property back down via wire:model) — drop
        // the selected lead so the nested detail component un-mounts
        // instead of silently re-querying on every later board action.
        if ($property === 'showLeadPanel' && ! $this->showLeadPanel) {
            $this->selectedLeadId = null;
        }
    }

    /**
     * Opens the lead detail flyout. Leads rendered on the board already
     * belong to the current user's assignment scope, but this is called
     * directly from the client (drag target / card click), so the
     * authorization is re-checked server-side rather than trusted.
     */
    public function viewLead(string $id): void
    {
        $lead = Lead::where('tenant_id', Auth::user()->tenant_id)->find($id);

        if (! $lead || Gate::denies('view', $lead)) {
            Flux::toast(variant: 'danger', text: __("You don't have permission to view this lead."));

            return;
        }

        $this->selectedLeadId = $lead->id;
        $this->showLeadPanel = true;
    }

    #[Computed]
    public function selectedLead(): ?Lead
    {
        if ($this->selectedLeadId === null) {
            return null;
        }

        return Lead::where('tenant_id', Auth::user()->tenant_id)
            ->with(['source', 'assignedUser', 'status', 'estimatedBudgetCurrency', 'dealValueCurrency'])
            ->find($this->selectedLeadId);
    }

    public function onPanelLeadDeleted(string $leadId): void
    {
        if ($this->selectedLeadId === $leadId) {
            $this->showLeadPanel = false;
            $this->selectedLeadId = null;
        }

        $this->refreshBoard();
    }

    /**
     * Moves a lead to a new status — the Kanban drag-and-drop target, and
     * the mobile/keyboard-accessible "move to" menu. Mirrors
     * Show::updateStatus() but only touches status (not priority, which
     * that form edits together) and fails soft with a toast instead of
     * throwing, since this runs from board interactions rather than a
     * dedicated form submission.
     */
    public function moveLead(string $leadId, string $status): void
    {
        $lead = Lead::where('tenant_id', Auth::user()->tenant_id)->find($leadId);

        if (! $lead || Gate::denies('update', $lead)) {
            Flux::toast(variant: 'danger', text: __("You don't have permission to move this lead."));
            $this->refreshBoard();

            return;
        }

        $newStatus = $lead->tenant->leadStatuses->firstWhere('id', $status);

        if ($newStatus === null) {
            return;
        }

        $oldStatus = $lead->status;

        if ($oldStatus->id === $newStatus->id) {
            return;
        }

        LeadStatusLog::create([
            'lead_id' => $lead->id,
            'changed_by' => Auth::id(),
            'old_status_id' => $oldStatus->id,
            'new_status_id' => $newStatus->id,
        ]);

        $updates = ['status_id' => $newStatus->id];

        if ($oldStatus->id === $lead->tenant->initialLeadStatus()?->id) {
            $updates['first_contacted_at'] = $lead->first_contacted_at ?? now();
        }

        if ($newStatus->is_closed) {
            $updates['closed_at'] = now();
        }

        $lead->update($updates);

        event(new LeadStatusChanged($lead, $oldStatus, $newStatus, Auth::user()));
        event(new LeadUpdated($lead));

        $this->refreshBoard();

        Flux::toast(variant: 'success', text: __(':name moved to :status.', ['name' => $lead->name, 'status' => $newStatus->label()]));
    }

    /**
     * Facebook Lead Ads submissions don't get a row per lead here like every
     * other source — they'd flood this list, and an Instant Form submission
     * has a very different shape (dynamic per-form fields) than a
     * conversational lead. Instead each Facebook Lead Ads source gets one
     * aggregate row, always shown regardless of the status/priority/
     * assigned-to dropdowns above (they represent many leads with a mix of
     * statuses/assignees, so those filters don't apply), linking through to
     * a dedicated spreadsheet view. Still respects assignment-based
     * visibility for non-admin agents: a source only appears for them if
     * they have at least one lead assigned to them from it.
     */
    #[Computed]
    public function facebookLeadAdsSources()
    {
        $user = Auth::user();

        $query = LeadSource::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('type', LeadSourceType::FacebookLeadAds);

        if ($this->isClientAdmin) {
            $query->withCount('leads')->withMax('leads', 'created_at');
        } else {
            $query
                ->withCount(['leads as leads_count' => fn (Builder $q) => $q->where('assigned_to', $user->id)])
                ->withMax(['leads as leads_max_created_at' => fn (Builder $q) => $q->where('assigned_to', $user->id)], 'created_at')
                ->whereHas('leads', fn (Builder $q) => $q->where('assigned_to', $user->id));
        }

        return $query->orderBy('name')->get();
    }

    public function delete(string $id): void
    {
        $lead = Lead::where('tenant_id', Auth::user()->tenant_id)->findOrFail($id);

        if (Gate::denies('delete', $lead)) {
            Flux::toast(variant: 'danger', text: __("You don't have permission to delete this lead."));

            return;
        }

        $lead->delete();

        if ($this->selectedLeadId === $id) {
            $this->showLeadPanel = false;
            $this->selectedLeadId = null;
        }

        $this->refreshBoard();

        Flux::toast(variant: 'success', text: __('Lead deleted.'));
    }

    /**
     * Removes an empty Facebook Lead Ads source directly from this page —
     * the one tenant-side exception to Lead Sources otherwise being
     * Platform-Admin-managed (see LeadSourcePolicy::delete()). Only reaches
     * a source with zero leads in practice: the "Delete" button in the view
     * is only rendered when `leads_count` is 0, but the count is re-checked
     * here too since it's a data-state guard, not something the policy
     * itself encodes.
     */
    public function deleteSource(string $id): void
    {
        $source = LeadSource::where('tenant_id', Auth::user()->tenant_id)
            ->where('type', LeadSourceType::FacebookLeadAds)
            ->findOrFail($id);

        if (Gate::denies('delete', $source)) {
            Flux::toast(variant: 'danger', text: __("You don't have permission to delete this lead source."));

            return;
        }

        if ($source->leads()->exists()) {
            Flux::toast(variant: 'danger', text: __('This source has leads and can no longer be deleted.'));

            return;
        }

        $source->delete();

        Flux::toast(variant: 'success', text: __('Lead source deleted.'));
    }

    /**
     * Turning an alert OFF is immediate — nothing to confirm. Turning it ON
     * needs a days-before number first, so this only opens the modal; the
     * setting itself isn't persisted as enabled until saveMeetingAlertDays()
     * runs. Client-admin-only, same as the rest of this tenant's Lead
     * Management configuration.
     */
    public function toggleMeetingAlert(): void
    {
        if (! $this->isClientAdmin) {
            return;
        }

        $tenant = Auth::user()->tenant;

        if ($tenant->meeting_alert_enabled) {
            $tenant->update(['meeting_alert_enabled' => false]);

            return;
        }

        $this->meetingAlertDaysInput = (string) $tenant->meeting_alert_days_before;
        $this->showMeetingAlertModal = true;
    }

    /**
     * Reopens the same days-before editor without touching whether the
     * alert is enabled — the "N days before" text next to an already-on
     * toggle is clickable for exactly this.
     */
    public function openMeetingAlertDaysEditor(): void
    {
        if (! $this->isClientAdmin) {
            return;
        }

        $this->meetingAlertDaysInput = (string) Auth::user()->tenant->meeting_alert_days_before;
        $this->showMeetingAlertModal = true;
    }

    public function saveMeetingAlertDays(): void
    {
        if (! $this->isClientAdmin) {
            return;
        }

        $validated = $this->validate([
            'meetingAlertDaysInput' => ['required', 'integer', 'min:0'],
        ]);

        Auth::user()->tenant->update([
            'meeting_alert_enabled' => true,
            'meeting_alert_days_before' => $validated['meetingAlertDaysInput'],
        ]);

        $this->showMeetingAlertModal = false;
        Flux::toast(variant: 'success', text: __('Meeting date alerts updated.'));
    }

    public function toggleFollowUpAlert(): void
    {
        if (! $this->isClientAdmin) {
            return;
        }

        $tenant = Auth::user()->tenant;

        if ($tenant->followup_alert_enabled) {
            $tenant->update(['followup_alert_enabled' => false]);

            return;
        }

        $this->followUpAlertDaysInput = (string) $tenant->followup_alert_days_before;
        $this->showFollowUpAlertModal = true;
    }

    public function openFollowUpAlertDaysEditor(): void
    {
        if (! $this->isClientAdmin) {
            return;
        }

        $this->followUpAlertDaysInput = (string) Auth::user()->tenant->followup_alert_days_before;
        $this->showFollowUpAlertModal = true;
    }

    public function saveFollowUpAlertDays(): void
    {
        if (! $this->isClientAdmin) {
            return;
        }

        $validated = $this->validate([
            'followUpAlertDaysInput' => ['required', 'integer', 'min:0'],
        ]);

        Auth::user()->tenant->update([
            'followup_alert_enabled' => true,
            'followup_alert_days_before' => $validated['followUpAlertDaysInput'],
        ]);

        $this->showFollowUpAlertModal = false;
        Flux::toast(variant: 'success', text: __('Follow-up alerts updated.'));
    }

    public function export(): StreamedResponse
    {
        Gate::authorize('viewAny', Lead::class);

        $leads = $this->applyDropdownFilters($this->applyAssignmentScope($this->filteredQuery()))
            ->with(['source', 'assignedUser', 'status', 'estimatedBudgetCurrency', 'dealValueCurrency'])
            ->get();

        return response()->streamDownload(function () use ($leads): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                throw new RuntimeException('Unable to open output stream.');
            }

            fputcsv($handle, ['Name', 'Email', 'Phone', 'Source', 'Status', 'Priority', 'Assigned To', 'Created At', 'Closed At']);

            foreach ($leads as $lead) {
                fputcsv($handle, [
                    $lead->name,
                    $lead->email,
                    $lead->phone,
                    $lead->source->type->label(),
                    $lead->status->name,
                    $lead->priority->value,
                    $lead->assignedUser->name ?? 'Unassigned',
                    $lead->created_at?->toDateTimeString(),
                    $lead->closed_at?->toDateTimeString(),
                ]);
            }

            fclose($handle);
        }, 'leads.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * @return Builder<Lead>
     */
    private function filteredQuery(): Builder
    {
        $user = Auth::user();

        $query = Lead::query()
            ->where('tenant_id', $user->tenant_id)
            ->whereHas('source', fn (Builder $q) => $q->where('type', '!=', LeadSourceType::FacebookLeadAds));

        if ($this->sourceType !== '') {
            $sourceType = $this->sourceType;
            $query->whereHas('source', fn (Builder $q) => $q->where('type', $sourceType));
        }

        return $query;
    }

    /**
     * Facebook Lead Ads is excluded: those leads never appear as individual
     * rows in this list, so filtering by that source type here would always
     * return nothing.
     *
     * @return array<int, string>
     */
    public function sourceTypeOptions(): array
    {
        return array_values(array_filter(
            LeadSourceType::values(),
            fn (string $value): bool => $value !== LeadSourceType::FacebookLeadAds->value,
        ));
    }
}
