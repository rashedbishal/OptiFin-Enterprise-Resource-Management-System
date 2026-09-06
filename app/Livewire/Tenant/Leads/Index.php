<?php

namespace App\Livewire\Tenant\Leads;

use App\Actions\Tenant\CreateManualLead;
use App\Enums\LeadPriority;
use App\Enums\LeadSourceType;
use App\Events\LeadStatusChanged;
use App\Events\LeadUpdated;
use App\Livewire\Tenant\Leads\Concerns\ConfirmsLeadDeletionPassword;
use App\Livewire\Tenant\Leads\Concerns\FiltersLeadsQuery;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatusLog;
use App\Models\TenantUser;
use App\Notifications\FollowUpUpdatedNotification;
use App\Notifications\MeetingUpdatedNotification;
use App\Services\LeadAdminNotifier;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Title('Leads')]
class Index extends Component
{
    use ConfirmsLeadDeletionPassword, FiltersLeadsQuery, WithPagination;

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

    /**
     * Bound to a single <input type="datetime-local">, e.g.
     * "2026-09-05T14:30" — split into the separate meeting_date/meeting_time
     * columns before saving, since the time is record-only and stored apart
     * from the date meetingAlertActive() actually compares against.
     */
    public string $leadMeetingDate = '';

    /**
     * Caps how many leads are fetched for the Kanban board / mobile list in
     * one query — grouped in PHP afterwards, since a per-column SQL limit
     * would need a window function. `boardLeadCounts()` is queried
     * separately (a cheap grouped count) so column headers stay accurate
     * even if a single status has more open leads than this cap.
     */
    private const BOARD_LEAD_LIMIT = 500;

    /**
     * Table view's page size — 15 on the sm+ desktop/tablet table, 10 on
     * the mobile card stack, matching the same `sm` breakpoint the view
     * already switches those two layouts at. Starts at the desktop value
     * since the server can't see the viewport; the view's x-init corrects
     * it to 10 immediately on load for anyone under that breakpoint.
     */
    public int $perPage = 15;

    public ?string $selectedLeadId = null;

    public bool $showLeadPanel = false;

    public ?string $recentlyCreatedLeadId = null;

    public bool $showMeetingAlertModal = false;

    public string $meetingAlertDaysInput = '0';

    public bool $showFollowUpAlertModal = false;

    public string $followUpAlertDaysInput = '0';

    public bool $showDeleteLeadModal = false;

    public ?string $confirmingDeleteLeadId = null;

    public bool $showDeleteSourceModal = false;

    public ?string $confirmingDeleteSourceId = null;

    /**
     * Opens a specific lead's flyout panel on load via a `?lead=` query
     * param — how a bell notification reaches a Client User's board (see
     * NotificationBell::urlFor()): unlike a Client Admin, they have no
     * full-page lead detail view of their own (tenant-leads.show renders
     * the same standalone page for anyone who can reach it, laid out for
     * the admin's table-based workflow, not the Kanban one a Client User
     * actually uses), so this flyout is the only "view this lead" surface
     * they have.
     */
    #[Url(as: 'lead')]
    public ?string $openLeadId = null;

    public function mount(): void
    {
        Gate::authorize('viewAny', Lead::class);

        if ($this->openLeadId !== null) {
            $this->viewLead($this->openLeadId);
        }
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

    public function saveLead(CreateManualLead $action, LeadAdminNotifier $notifier): void
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
            'leadMeetingDate' => ['nullable', 'date_format:Y-m-d\TH:i', 'after_or_equal:today'],
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

        // The single datetime-local field splits back into the separate
        // meeting_date/meeting_time columns here — meeting_time is
        // record-only and never read by meetingAlertActive(), which stays
        // purely date-based.
        [$meetingDate, $meetingTime] = $validated['leadMeetingDate']
            ? explode('T', $validated['leadMeetingDate'])
            : [null, null];

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
            'meeting_date' => $meetingDate,
            'meeting_time' => $meetingTime,
        ], $user, $assignee);

        $this->recentlyCreatedLeadId = $createdLead->id;

        // A brand new lead's meeting/follow-up is always a fresh schedule
        // (rescheduled: false) — there's no "before" to compare against the
        // way Show::updateDeal() has to for an existing lead.
        if ($createdLead->meeting_date !== null) {
            $notifier->notifyExcept($createdLead, $user, new MeetingUpdatedNotification($createdLead, $user, rescheduled: false));
        }

        if ($createdLead->next_follow_up_at !== null) {
            $notifier->notifyExcept($createdLead, $user, new FollowUpUpdatedNotification($createdLead, $user, rescheduled: false));
        }

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
        }
        $this->refreshBoard();
    }

    /**
     * Just a re-render trigger — the "recently updated" highlight itself
     * comes from Lead::hasUnseenUpdate() reading the DB (see leads.blade.php),
     * so nothing needs to be stashed here for it to show up once the board
     * re-queries.
     */
    public function onLeadUpdated(): void
    {
        $this->refreshBoard();
    }

    public function onPanelLeadUpdated(?string $leadId = null): void
    {
        if ($leadId) {
            $this->recentlyCreatedLeadId = null;
        }
        $this->refreshBoard();
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['status', 'priority', 'sourceType', 'assignedTo', 'fromDate', 'toDate', 'search'], true)) {
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
            ->paginate($this->perPage);
    }

    /**
     * Called once from the view's x-init (and again on every sm-breakpoint
     * crossing) rather than trusting a `wire:model` on perPage directly —
     * this only ever needs to reflect the viewport, never arbitrary
     * client input, so anything outside the two real page sizes is ignored
     * instead of validated/rejected.
     */
    public function setPerPage(int $perPage): void
    {
        $perPage = in_array($perPage, [10, 15], true) ? $perPage : 15;

        if ($perPage === $this->perPage) {
            return;
        }

        $this->perPage = $perPage;
        $this->resetPage();
        unset($this->leads);
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
        if (in_array($property, ['status', 'priority', 'sourceType', 'assignedTo', 'fromDate', 'toDate', 'search'], true)) {
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
        // openLeadId is cleared alongside it (not just selectedLeadId) so
        // the ?lead= query param it's bound to actually disappears from the
        // URL too — otherwise a refresh right after closing the panel would
        // silently reopen it.
        if ($property === 'showLeadPanel' && ! $this->showLeadPanel) {
            $this->selectedLeadId = null;
            $this->openLeadId = null;
        }
    }

    /**
     * Opens the lead detail flyout. Leads rendered on the board already
     * belong to the current user's assignment scope, but this is called
     * directly from the client (drag target / card click), so the
     * authorization is re-checked server-side rather than trusted.
     *
     * Also updates openLeadId (the ?lead= query param) to match, not just
     * selectedLeadId — otherwise, after a notification link opens one lead
     * and the viewer then clicks a *different* card, the address bar would
     * keep pointing at the original lead: refreshing the page at that point
     * would reopen it instead of whichever lead the viewer was actually
     * looking at.
     */
    public function viewLead(string $id): void
    {
        $lead = Lead::where('tenant_id', Auth::user()->tenant_id)->find($id);

        if (! $lead || Gate::denies('view', $lead)) {
            Flux::toast(variant: 'danger', text: __("You don't have permission to view this lead."));

            return;
        }

        $lead->markUpdateSeen();

        $this->selectedLeadId = $lead->id;
        $this->openLeadId = $lead->id;
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
            $this->openLeadId = null;
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

    /**
     * Opens the password-confirmation modal (see ConfirmsLeadDeletionPassword)
     * rather than deleting immediately — checked here too, not just hidden
     * client-side, since this is reachable directly via wire:click.
     */
    public function confirmDeleteLead(string $id): void
    {
        $lead = Lead::where('tenant_id', Auth::user()->tenant_id)->find($id);

        if (! $lead || Gate::denies('delete', $lead)) {
            Flux::toast(variant: 'danger', text: __("You don't have permission to delete this lead."));

            return;
        }

        $this->reset('deletePassword');
        $this->resetErrorBag('deletePassword');
        $this->confirmingDeleteLeadId = $id;
        $this->showDeleteLeadModal = true;
    }

    public function deleteLeadConfirmed(): void
    {
        if (! $this->verifyDeletePassword()) {
            return;
        }

        if ($this->confirmingDeleteLeadId) {
            $this->delete($this->confirmingDeleteLeadId);
        }

        $this->closeDeleteLeadModal();
    }

    public function closeDeleteLeadModal(): void
    {
        $this->showDeleteLeadModal = false;
        $this->confirmingDeleteLeadId = null;
        $this->reset('deletePassword');
        $this->resetErrorBag('deletePassword');
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
            $this->openLeadId = null;
        }

        $this->refreshBoard();

        Flux::toast(variant: 'success', text: __('Lead deleted.'));
    }

    public function confirmDeleteSource(string $id): void
    {
        $this->confirmingDeleteSourceId = $id;
        $this->showDeleteSourceModal = true;
    }

    public function deleteSourceConfirmed(): void
    {
        if ($this->confirmingDeleteSourceId) {
            $this->deleteSource($this->confirmingDeleteSourceId);
        }

        $this->showDeleteSourceModal = false;
        $this->confirmingDeleteSourceId = null;
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

            fputcsv($handle, ['Lead ID', 'Name', 'Email', 'Phone', 'Source', 'Status', 'Priority', 'Assigned To', 'Created At', 'Closed At']);

            foreach ($leads as $lead) {
                fputcsv($handle, [
                    $lead->identifier,
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
     * Facebook Lead Ads leads are excluded here only for the Client Admin's
     * table view — they'd flood a flat table with dynamic-per-form fields
     * that don't fit its fixed columns, so admins get an aggregate row per
     * source instead (see facebookLeadAdsSources()) linking to a dedicated
     * spreadsheet view. A Client User's Kanban board has no such row-shape
     * constraint, so their assigned Facebook Lead Ads leads appear as
     * normal cards in their pipeline column right alongside every other
     * source, same as this method already treats every other type.
     *
     * @return Builder<Lead>
     */
    private function filteredQuery(): Builder
    {
        $user = Auth::user();

        $query = Lead::query()->where('tenant_id', $user->tenant_id);

        if ($this->isClientAdmin) {
            $query->whereHas('source', fn (Builder $q) => $q->where('type', '!=', LeadSourceType::FacebookLeadAds));
        }

        if ($this->sourceType !== '') {
            $sourceType = $this->sourceType;
            $query->whereHas('source', fn (Builder $q) => $q->where('type', $sourceType));
        }

        $query = $this->applySearchFilter($query);

        return $query;
    }

    /**
     * Only source types this tenant actually has a LeadSource configured
     * for — a static full-enum list left every tenant filtering by channels
     * (LinkedIn, Instagram, ...) they never connected, which always
     * returned zero rows. Manual ("Custom") is force-included regardless: it's
     * lazily provisioned on first use (see App\Actions\Tenant\CreateManualLead),
     * so a tenant that hasn't created a manual lead yet would otherwise have
     * no LeadSource row for it to be "configured" from, even though any
     * Client User can create one at any time.
     *
     * Facebook Lead Ads is additionally excluded on the Client Admin's
     * table view specifically: filteredQuery() excludes that type outright
     * for admins (see its docblock), so it would always return zero rows
     * there regardless of configuration. A Client User's Kanban board has
     * no such exclusion, so it's offered there whenever the tenant has an
     * active Facebook Lead Ads source, same as every other type.
     *
     * @return array<int, string>
     */
    public function sourceTypeOptions(): array
    {
        $configuredTypes = LeadSource::query()
            ->where('tenant_id', Auth::user()->tenant_id)
            ->distinct()
            ->pluck('type')
            ->map(fn (LeadSourceType $type): string => $type->value)
            ->all();

        return array_values(array_filter(
            LeadSourceType::values(),
            function (string $value) use ($configuredTypes): bool {
                if ($this->isClientAdmin && $value === LeadSourceType::FacebookLeadAds->value) {
                    return false;
                }

                return $value === LeadSourceType::Manual->value || in_array($value, $configuredTypes, true);
            },
        ));
    }
}
