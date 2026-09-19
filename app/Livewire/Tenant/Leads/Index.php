<?php

namespace App\Livewire\Tenant\Leads;

use App\Actions\Tenant\CreateManualLead;
use App\Actions\Tenant\RecordLeadDeletionAudit;
use App\Actions\Tenant\SaveLeadCustomFieldValues;
use App\Enums\LeadPriority;
use App\Enums\LeadSourceType;
use App\Events\LeadStatusChanged;
use App\Events\LeadUpdated;
use App\Livewire\Tenant\Leads\Concerns\ConfirmsLeadDeletionPassword;
use App\Livewire\Tenant\Leads\Concerns\FiltersLeadsQuery;
use App\Models\Lead;
use App\Models\LeadCustomFieldDefinition;
use App\Models\LeadDetailUpdateLog;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\LeadStatusLog;
use App\Models\LeadTag;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Notifications\FollowUpUpdatedNotification;
use App\Notifications\MeetingUpdatedNotification;
use App\Services\LeadAdminNotifier;
use App\Services\LeadAssignmentService;
use App\Services\LeadDeletionEligibilityService;
use App\Services\LeadScoringService;
use App\Support\LeadValidationRules;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

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
     * Feature #11 — Custom Fields. Keyed by lead_custom_field_definition_id,
     * bound via wire:model="customFieldValues.{id}" in the New Lead modal's
     * dynamically-rendered custom-fields section. Only ever validated/saved
     * against this tenant's currently ACTIVE definitions (see
     * activeCustomFieldDefinitions() and App\Actions\Tenant\
     * SaveLeadCustomFieldValues) — a stale key left over from a definition
     * that was deactivated between opening and submitting the form is
     * simply never looked at, never trusted.
     *
     * @var array<string, mixed>
     */
    public array $customFieldValues = [];

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

    /**
     * Starts false on every fresh mount (table AND Kanban board share this
     * component — see render()) so the very first paint can skip the actual
     * leads/boardLeads/boardLeadCounts query entirely (see the `1 = 0` guard
     * in filteredQuery()) and show the skeleton immediately, matching what a
     * user does with search/filter changes. The view's root element carries
     * `wire:init="completeInitialLoad"`, which Livewire fires as a real
     * network request right after the component mounts client-side — that
     * request is what flips this true and is also in $listLoadingTargets,
     * so it drives the exact same skeleton toggle already used everywhere
     * else on this page rather than a second, separate mechanism.
     */
    public bool $initialLoadComplete = false;

    public ?string $selectedLeadId = null;

    public bool $showLeadPanel = false;

    public ?string $recentlyCreatedLeadId = null;

    public bool $showMeetingAlertModal = false;

    public string $meetingAlertDaysInput = '0';

    public bool $showFollowUpAlertModal = false;

    public string $followUpAlertDaysInput = '0';

    public bool $showTaskAlertModal = false;

    public string $taskAlertDaysInput = '0';

    public bool $showNurturingDaysModal = false;

    public string $nurturingDaysInput = '0';

    /**
     * Which action a click on toggleNurturingNotify()/toggleNurturingTask()
     * is trying to turn on when the shared threshold modal opens — null
     * when the modal was opened via openNurturingDaysEditor() to just
     * adjust the existing threshold, in which case saveNurturingDays()
     * updates the number only, without also flipping either enabled flag.
     */
    public ?string $pendingNurturingToggle = null;

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

    /**
     * Bulk Actions (Feature #6, Phase 1) — the checked set of Lead ids from
     * the table's checkbox column / Kanban board's card overlay. Never
     * trusted directly by a bulk action: eligibleSelectedLeads() always
     * re-derives the real, authorized set from this tenant's own
     * tenant/assignment-scoped query before anything runs.
     *
     * @var array<int, string>
     */
    public array $selectedLeadIds = [];

    /**
     * Hard cap on how many Leads a single bulk action can touch —
     * eligibleSelectedLeads() silently truncates to this; selectAllVisible-
     * style helpers below toast a warning when they would have exceeded it.
     */
    private const int MAX_BULK_SELECTION = 100;

    public bool $showBulkStatusModal = false;

    public string $bulkStatusId = '';

    public bool $showBulkPriorityModal = false;

    public string $bulkPriority = '';

    public bool $showBulkAssignModal = false;

    public string $bulkAssigneeId = '';

    public bool $showBulkTagModal = false;

    public string $bulkTagId = '';

    /**
     * 'attach' or 'remove' — one shared modal handles both directions of
     * the bulk tag action, switched by which button opened it.
     */
    public string $bulkTagAction = 'attach';

    /**
     * Whether the shared password-confirmation delete modal (see
     * ConfirmsLeadDeletionPassword) was opened for a bulk delete rather
     * than a single lead — deleteLeadConfirmed() branches on this instead
     * of a second modal/property pair.
     */
    public bool $bulkDeleteMode = false;

    public function mount(): void
    {
        Gate::authorize('viewAny', Lead::class);

        if ($this->openLeadId !== null) {
            $this->viewLead($this->openLeadId);
        }
    }

    public function completeInitialLoad(): void
    {
        $this->initialLoadComplete = true;
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

    /**
     * This tenant's currently active custom field definitions (Feature #11),
     * in display order — the single source of truth for both which fields
     * the New Lead modal renders and which fields saveLead() validates
     * against. Explicitly filtered by tenant_id rather than relying on a
     * global scope, same discipline as every other query in this module.
     *
     * @return Collection<int, LeadCustomFieldDefinition>
     */
    #[Computed]
    public function activeCustomFieldDefinitions(): Collection
    {
        return LeadCustomFieldDefinition::query()
            ->where('tenant_id', Auth::user()->tenant_id)
            ->active()
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get();
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
            'customFieldValues',
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

        // Field-level rule VALUES live in App\Support\LeadValidationRules —
        // the single source of truth also used by Feature #7's CSV import
        // (App\Imports\LeadCsvImport) — so the two paths can never silently
        // drift apart. Only the rule values are shared; custom messages
        // below stay inline, since Livewire's field-level message bag and a
        // CSV row's own error reporting are different UI concerns.
        $validated = $this->validate([
            'leadName' => LeadValidationRules::name(),
            'leadCompany' => LeadValidationRules::company(),
            'leadWebsite' => LeadValidationRules::website(),
            'leadIndustry' => LeadValidationRules::industry(),
            'leadLocation' => LeadValidationRules::location(),
            'leadServiceInterest' => LeadValidationRules::serviceInterest(),
            'leadSource' => LeadValidationRules::leadSourceText(),
            'leadEmail' => LeadValidationRules::email(),
            'leadPhone' => LeadValidationRules::phone(),
            'leadMessage' => LeadValidationRules::message(),
            'leadStatus' => LeadValidationRules::statusId($tenant),
            'leadPriority' => LeadValidationRules::priority(),
            'leadAssigneeId' => ['nullable', 'string'],
            'leadEstimatedBudget' => LeadValidationRules::amount(),
            'leadEstimatedBudgetCurrencyId' => LeadValidationRules::currencyId($tenant),
            'leadDealValue' => LeadValidationRules::amount(),
            'leadDealValueCurrencyId' => LeadValidationRules::currencyId($tenant),
            'leadWinProbability' => LeadValidationRules::winProbability(),
            'leadNextFollowUpAt' => LeadValidationRules::nextFollowUpAt(),
            'leadMeetingDate' => LeadValidationRules::meetingDate(),
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

        // Feature #11 — a separate validate() pass, deliberately: the rule
        // set here is assembled at runtime from this tenant's own active
        // custom field definitions, unlike every rule above (all fixed,
        // known ahead of time via LeadValidationRules). Keeping it apart
        // means a tenant with zero custom fields adds a no-op empty
        // validate([]) call rather than complicating the fixed rule array.
        $customFieldRules = [];

        foreach ($this->activeCustomFieldDefinitions as $definition) {
            $customFieldRules['customFieldValues.'.$definition->id] = $definition->validationRules();
        }

        // An empty array passed to validate() doesn't mean "validate
        // nothing" — Livewire treats it as "no rules were passed, fall back
        // to a $rules property/rules() method", which this component has
        // neither of, and throws. Only call it when this tenant actually
        // has at least one active custom field.
        if ($customFieldRules !== []) {
            $this->validate($customFieldRules);
        }

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

        app(SaveLeadCustomFieldValues::class)->handle($createdLead, $tenant->id, $this->customFieldValues);

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
        if (in_array($property, ['status', 'priority', 'sourceType', 'assignedTo', 'tag', 'fromDate', 'toDate', 'search'], true)) {
            $this->resetPage();
            unset($this->boardLeads, $this->boardLeadCounts, $this->leads);
            $this->clearSelection();
        }
    }

    /**
     * Livewire's WithPagination magic hook, fired whenever its own `page`
     * property changes (gotoPage/previousPage/nextPage/setPage) — a bulk
     * selection is scoped to whatever's currently on screen, so paging away
     * from it must clear it rather than silently carry stale ids forward.
     */
    public function updatingPage(): void
    {
        $this->clearSelection();
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
            ->with(['source', 'assignedUser', 'status', 'estimatedBudgetCurrency', 'dealValueCurrency', 'tags'])
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
        $this->clearSelection();
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
            ->with(['source', 'assignedUser', 'status', 'estimatedBudgetCurrency', 'dealValueCurrency', 'tags'])
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
        if (in_array($property, ['status', 'priority', 'sourceType', 'assignedTo', 'tag', 'fromDate', 'toDate', 'search'], true)) {
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
     *
     * M2 fix: the LeadStatusLog write, the Lead status write, and both
     * events are wrapped in one DB::transaction() so a failure partway
     * through can't leave the two disagreeing — mirroring the identical
     * DB::transaction() boundary already shipped for this exact same
     * status-change side-effect chain in Show::updateStatus() and, in this
     * same file, applyBulkStatusChange(). LeadStatusChanged/LeadUpdated are
     * fired from inside the transaction (not deferred): both queue their
     * consumers via the database queue driver, and a job row inserted on
     * the same connection while a transaction is open is invisible to any
     * other connection until that transaction actually commits, so a
     * queued worker can never observe pre-commit data here — the same
     * reasoning already relied on by Show::updateStatus()'s own fix.
     *
     * TOCTOU fix: $newStatus is now resolved *inside* this transaction,
     * immediately after taking a shared (FOR SHARE) coordination lock on
     * the tenant row — see Platform\Clients\LeadSources::toggleLeadStatus()'s
     * own docblock for the full readers/writer protocol. This closes the
     * race where a Platform Admin removes the very pipeline status this
     * move targets in the gap between resolving it and writing it; the
     * loser of that race safely falls through to the pre-existing "status
     * not found" no-op below, exactly as an already-invalid $status always
     * has.
     */
    public function moveLead(string $leadId, string $status): void
    {
        $lead = Lead::where('tenant_id', Auth::user()->tenant_id)->find($leadId);

        if (! $lead || Gate::denies('update', $lead)) {
            Flux::toast(variant: 'danger', text: __("You don't have permission to move this lead."));
            $this->refreshBoard();

            return;
        }

        $newStatus = DB::transaction(function () use ($lead, $status): ?LeadStatus {
            $tenant = Tenant::whereKey($lead->tenant_id)->sharedLock()->firstOrFail();

            $newStatus = $tenant->leadStatuses->firstWhere('id', $status);

            if ($newStatus === null) {
                return null;
            }

            $oldStatus = $lead->status;

            if ($oldStatus->id === $newStatus->id) {
                return null;
            }

            LeadStatusLog::create([
                'lead_id' => $lead->id,
                'changed_by' => Auth::id(),
                'old_status_id' => $oldStatus->id,
                'new_status_id' => $newStatus->id,
            ]);

            $updates = ['status_id' => $newStatus->id];

            if ($oldStatus->id === $tenant->initialLeadStatus()?->id) {
                $updates['first_contacted_at'] = $lead->first_contacted_at ?? now();
            }

            if ($newStatus->is_closed) {
                $updates['closed_at'] = now();
            }

            $lead->update($updates);

            event(new LeadStatusChanged($lead, $oldStatus, $newStatus, Auth::user()));
            event(new LeadUpdated($lead));

            return $newStatus;
        });

        if ($newStatus === null) {
            return;
        }

        $this->refreshBoard();

        Flux::toast(variant: 'success', text: __(':name moved to :status.', ['name' => $lead->company ?: $lead->name, 'status' => $newStatus->label()]));
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

        // PROD-1 — advisory only: refuses early, before a password is typed,
        // for a merged loser or survivor. delete() re-evaluates inside its own
        // transaction and stays the authoritative check.
        $eligibility = app(LeadDeletionEligibilityService::class)->evaluate($lead, lock: false);

        if (! $eligibility->eligible) {
            Flux::toast(variant: 'danger', text: $eligibility->reason);

            return;
        }

        $this->reset('deletePassword');
        $this->resetErrorBag('deletePassword');
        $this->confirmingDeleteLeadId = $id;
        $this->bulkDeleteMode = false;
        $this->showDeleteLeadModal = true;
    }

    /**
     * Bulk Actions (Feature #6) reuses this exact same password-confirmation
     * modal/flow instead of a second one — deleteLeadConfirmed() below
     * branches on $bulkDeleteMode rather than duplicating the modal, which
     * would otherwise put two flux:input wire:model="deletePassword" fields
     * (same DOM id, since Flux derives it from the model name) on the page
     * at once.
     */
    public function confirmBulkDelete(): void
    {
        if (! $this->canDeleteLeads || $this->selectedLeadIds === []) {
            return;
        }

        $this->reset('deletePassword');
        $this->resetErrorBag('deletePassword');
        $this->confirmingDeleteLeadId = null;
        $this->bulkDeleteMode = true;
        $this->showDeleteLeadModal = true;
    }

    public function deleteLeadConfirmed(): void
    {
        if (! $this->verifyDeletePassword()) {
            return;
        }

        if ($this->bulkDeleteMode) {
            $this->performBulkDelete();
        } elseif ($this->confirmingDeleteLeadId) {
            $this->delete($this->confirmingDeleteLeadId);
        }

        $this->closeDeleteLeadModal();
    }

    public function closeDeleteLeadModal(): void
    {
        $this->showDeleteLeadModal = false;
        $this->confirmingDeleteLeadId = null;
        $this->bulkDeleteMode = false;
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

        if (! $lead->source->type->isIndividuallyDeletable()) {
            Flux::toast(variant: 'danger', text: __('This lead\'s source type cannot be deleted individually.'));

            return;
        }

        $refusal = null;

        DB::transaction(function () use ($lead, &$refusal): void {
            // PROD-1 — the authoritative merged-state check: locks this lead
            // row first, and refuses before the audit, the delete, the panel
            // close or the success toast can happen.
            $eligibility = app(LeadDeletionEligibilityService::class)->evaluate($lead);

            if (! $eligibility->eligible) {
                $refusal = $eligibility->reason;

                return;
            }

            if (app(RecordLeadDeletionAudit::class)->handle($lead, Auth::user(), isBulk: false) === null) {
                return;
            }

            $lead->delete();
        });

        if ($refusal !== null) {
            Flux::toast(variant: 'danger', text: $refusal);

            return;
        }

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
     *
     * Locks the LeadSource row before checking dependents so a concurrent
     * Facebook Lead Ads webhook (the only way a Lead or Cost can attach to
     * this source type) cannot create a new dependency between the check
     * and the delete — same "lock the stable parent row" idiom already
     * used by Platform\Clients\LeadSources::delete() (C1) and
     * LeadSourceCosts\Index::save().
     */
    public function deleteSource(string $id): void
    {
        DB::transaction(function () use ($id): void {
            $source = LeadSource::where('tenant_id', Auth::user()->tenant_id)
                ->where('type', LeadSourceType::FacebookLeadAds)
                ->lockForUpdate()
                ->findOrFail($id);

            if (Gate::denies('delete', $source)) {
                Flux::toast(variant: 'danger', text: __("You don't have permission to delete this lead source."));

                return;
            }

            if ($source->leads()->exists()) {
                Flux::toast(variant: 'danger', text: __('This source has leads and can no longer be deleted.'));

                return;
            }

            // Feature #12: a source with recorded cost history must not be
            // deletable out from under it, even when it has zero leads (e.g. a
            // month of ad spend that produced no leads) — same "don't silently
            // lose recorded financial data" principle as Currency's in-use
            // block below.
            if ($source->costs()->exists()) {
                Flux::toast(variant: 'danger', text: __('This source has recorded cost history and can no longer be deleted.'));

                return;
            }

            $source->delete();

            Flux::toast(variant: 'success', text: __('Lead source deleted.'));
        });
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

    /**
     * A third, independent alert pair alongside meeting/follow-up above —
     * same toggle-then-confirm shape, deliberately not reusing either of
     * those settings (see the add_task_alert_settings_to_tenants_table
     * migration's docblock).
     */
    public function toggleTaskAlert(): void
    {
        if (! $this->isClientAdmin) {
            return;
        }

        $tenant = Auth::user()->tenant;

        if ($tenant->task_alert_enabled) {
            $tenant->update(['task_alert_enabled' => false]);

            return;
        }

        $this->taskAlertDaysInput = (string) $tenant->task_alert_days_before;
        $this->showTaskAlertModal = true;
    }

    public function openTaskAlertDaysEditor(): void
    {
        if (! $this->isClientAdmin) {
            return;
        }

        $this->taskAlertDaysInput = (string) Auth::user()->tenant->task_alert_days_before;
        $this->showTaskAlertModal = true;
    }

    public function saveTaskAlertDays(): void
    {
        if (! $this->isClientAdmin) {
            return;
        }

        $validated = $this->validate([
            'taskAlertDaysInput' => ['required', 'integer', 'min:0'],
        ]);

        Auth::user()->tenant->update([
            'task_alert_enabled' => true,
            'task_alert_days_before' => $validated['taskAlertDaysInput'],
        ]);

        $this->showTaskAlertModal = false;
        Flux::toast(variant: 'success', text: __('Task due-date alerts updated.'));
    }

    /**
     * Feature #9 — unlike the three alert pairs above (one enabled flag
     * each), notification and follow-up-task are independently toggleable
     * but share one threshold, so turning either on for the first time (both
     * currently off) opens the shared days editor; turning one on while the
     * other is already on just flips the flag directly, since a threshold
     * is already configured. Turning either off never needs the modal.
     */
    public function toggleNurturingNotify(): void
    {
        if (! $this->isClientAdmin) {
            return;
        }

        $tenant = Auth::user()->tenant;

        if ($tenant->lead_nurturing_notify_enabled) {
            $tenant->update(['lead_nurturing_notify_enabled' => false]);

            return;
        }

        if ($tenant->lead_nurturing_task_enabled) {
            $tenant->update(['lead_nurturing_notify_enabled' => true]);

            return;
        }

        $this->pendingNurturingToggle = 'notify';
        $this->nurturingDaysInput = (string) $tenant->lead_nurturing_inactive_days;
        $this->showNurturingDaysModal = true;
    }

    public function toggleNurturingTask(): void
    {
        if (! $this->isClientAdmin) {
            return;
        }

        $tenant = Auth::user()->tenant;

        if ($tenant->lead_nurturing_task_enabled) {
            $tenant->update(['lead_nurturing_task_enabled' => false]);

            return;
        }

        if ($tenant->lead_nurturing_notify_enabled) {
            $tenant->update(['lead_nurturing_task_enabled' => true]);

            return;
        }

        $this->pendingNurturingToggle = 'task';
        $this->nurturingDaysInput = (string) $tenant->lead_nurturing_inactive_days;
        $this->showNurturingDaysModal = true;
    }

    public function openNurturingDaysEditor(): void
    {
        if (! $this->isClientAdmin) {
            return;
        }

        $this->pendingNurturingToggle = null;
        $this->nurturingDaysInput = (string) Auth::user()->tenant->lead_nurturing_inactive_days;
        $this->showNurturingDaysModal = true;
    }

    public function saveNurturingDays(): void
    {
        if (! $this->isClientAdmin) {
            return;
        }

        $validated = $this->validate([
            'nurturingDaysInput' => ['required', 'integer', 'min:0', 'max:365'],
        ]);

        $updates = ['lead_nurturing_inactive_days' => $validated['nurturingDaysInput']];

        if ($this->pendingNurturingToggle === 'notify') {
            $updates['lead_nurturing_notify_enabled'] = true;
        } elseif ($this->pendingNurturingToggle === 'task') {
            $updates['lead_nurturing_task_enabled'] = true;
        }

        Auth::user()->tenant->update($updates);

        $this->pendingNurturingToggle = null;
        $this->showNurturingDaysModal = false;
        Flux::toast(variant: 'success', text: __('Lead inactivity nurturing updated.'));
    }

    public function export(): StreamedResponse
    {
        Gate::authorize('viewAny', Lead::class);

        $query = $this->applyDropdownFilters($this->applyAssignmentScope($this->filteredQuery()))
            ->with(['source', 'assignedUser', 'status', 'estimatedBudgetCurrency', 'dealValueCurrency']);

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                throw new RuntimeException('Unable to open output stream.');
            }

            fputcsv($handle, ['Lead ID', 'Name', 'Email', 'Phone', 'Source', 'Status', 'Priority', 'Assigned To', 'Created At', 'Closed At']);

            // chunkById() keeps memory bounded to one 200-row page at a time
            // regardless of how many leads match the current filters — the
            // same pattern already used for tenant-wide Lead sweeps in
            // RecalculateLeadScores/DetectDuplicateLeads. It re-applies this
            // query's own with()/where() constraints per page, so every
            // filter above still narrows the export exactly as before.
            $query->chunkById(200, function ($leads) use ($handle): void {
                foreach ($leads as $lead) {
                    fputcsv($handle, [
                        $lead->identifier,
                        $this->sanitizeCsvCell($lead->name),
                        $this->sanitizeCsvCell($lead->email),
                        $lead->phone,
                        $lead->source->type->label(),
                        $lead->status->name,
                        $lead->priority->value,
                        $lead->assignedUser->name ?? 'Unassigned',
                        $lead->created_at?->toDateTimeString(),
                        $lead->closed_at?->toDateTimeString(),
                    ]);
                }
            });

            fclose($handle);
        }, 'leads.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * CSV/formula-injection guard for export(): name/email ultimately
     * originate from attacker-controllable input on at least one Lead
     * ingestion path each (ProcessEmailLeadSource's unvalidated
     * From-header personal name; Laravel's own `email` validation rule
     * accepts a leading =/+/- in the local part, e.g. "=1+1@evil.com") —
     * a value starting with =, +, -, @, a tab, or a CR is interpreted as a
     * formula by Excel/LibreOffice/Google Sheets when this CSV is opened.
     * Unlike FacebookLeadAdsExport's XLSX export (C6), which forces a real
     * cell-type flag via PhpSpreadsheet's StringValueBinder, plain CSV has
     * no such metadata channel — the only way to signal "this is text, not
     * a formula" is the leading-apostrophe convention every major
     * spreadsheet application already respects. phone is deliberately NOT
     * sanitized here: its own validation regex only ever permits digits,
     * spaces, parens, +, and - (no letters/@/quotes), so a genuine
     * executable formula can never reach it — sanitizing it too would only
     * change how an ordinary "+"-prefixed international phone number
     * displays, with no security benefit.
     */
    private function sanitizeCsvCell(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        foreach (["\t", "\r", '=', '+', '-', '@'] as $trigger) {
            if (str_starts_with($value, $trigger)) {
                return "'".$value;
            }
        }

        return $value;
    }

    /**
     * =========================================================================
     * Bulk Actions (Feature #6, Phase 1)
     * =========================================================================
     */
    #[Computed]
    public function selectedCount(): int
    {
        return count($this->selectedLeadIds);
    }

    public function clearSelection(): void
    {
        $this->selectedLeadIds = [];
    }

    public function toggleLeadSelection(string $id): void
    {
        if (in_array($id, $this->selectedLeadIds, true)) {
            $this->selectedLeadIds = array_values(array_diff($this->selectedLeadIds, [$id]));

            return;
        }

        if (count($this->selectedLeadIds) >= self::MAX_BULK_SELECTION) {
            Flux::toast(variant: 'warning', text: __('You can select up to :max leads at a time.', ['max' => self::MAX_BULK_SELECTION]));

            return;
        }

        $this->selectedLeadIds[] = $id;
    }

    /**
     * Toggles every Lead currently on this page of the Client Admin table —
     * unchecks the whole page if every one of its rows is already selected,
     * otherwise adds every not-yet-selected row (capped at
     * MAX_BULK_SELECTION, same as toggleLeadSelection()). Additive rather
     * than replacing $selectedLeadIds outright, so a selection made on an
     * earlier page survives paging back to it — though in practice paging
     * away already clears it via updatingPage().
     */
    public function toggleSelectAllOnPage(): void
    {
        $this->toggleSelectAllFor($this->leads->pluck('id')->all());
    }

    /**
     * Same as toggleSelectAllOnPage() but for the Client User's Kanban
     * board, which has no pagination — "visible" means every Lead currently
     * rendered across every column (boardLeads(), capped at
     * BOARD_LEAD_LIMIT), not just one page's worth.
     */
    public function toggleSelectAllOnBoard(): void
    {
        $this->toggleSelectAllFor($this->boardLeads->flatten(1)->pluck('id')->all());
    }

    /**
     * @param  array<int, string>  $ids
     */
    private function toggleSelectAllFor(array $ids): void
    {
        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return;
        }

        $allAlreadySelected = array_diff($ids, $this->selectedLeadIds) === [];

        if ($allAlreadySelected) {
            $this->selectedLeadIds = array_values(array_diff($this->selectedLeadIds, $ids));

            return;
        }

        $merged = array_values(array_unique(array_merge($this->selectedLeadIds, $ids)));

        if (count($merged) > self::MAX_BULK_SELECTION) {
            Flux::toast(variant: 'warning', text: __('You can select up to :max leads at a time.', ['max' => self::MAX_BULK_SELECTION]));
            $merged = array_slice($merged, 0, self::MAX_BULK_SELECTION);
        }

        $this->selectedLeadIds = $merged;
    }

    /**
     * The real, authorized set of Leads a bulk action can act on — always
     * re-derived from this tenant's own tenant-scoped + assignment-scoped
     * query (never trusting $selectedLeadIds' ids directly, and never
     * re-applying the dropdown filters, per the approved spec: "current
     * tenant-scoped and assignment-scoped Lead query"). A stale id (another
     * tab merged/deleted it, or a non-admin's assignment scope no longer
     * covers it) simply isn't returned here — the caller's own per-Lead
     * authorization check on top of this is what actually decides
     * eligibility for a given action (e.g. delete requires isClientAdmin,
     * assign requires isClientAdmin, update allows an assignee too).
     *
     * @return Collection<int, Lead>
     */
    private function eligibleSelectedLeads(): Collection
    {
        if ($this->selectedLeadIds === []) {
            return collect();
        }

        $ids = array_slice($this->selectedLeadIds, 0, self::MAX_BULK_SELECTION);

        return $this->applyAssignmentScope($this->filteredQuery())
            ->whereIn('id', $ids)
            ->with(['source', 'status', 'tags'])
            ->get();
    }

    /**
     * Shared partial-success toast for every bulk action below — "X of Y
     * Leads {verb}." plus a trailing "Z skipped." clause when anything was
     * skipped. $attempted is the size of the original selection (capped),
     * not just how many Leads eligibleSelectedLeads() actually returned —
     * a stale/no-longer-eligible id counts as skipped too, exactly like a
     * per-Lead authorization failure does.
     */
    private function reportBulkResult(int $succeeded, int $attempted, string $verb): void
    {
        $skipped = $attempted - $succeeded;

        $text = __(':succeeded of :attempted Leads :verb.', ['succeeded' => $succeeded, 'attempted' => $attempted, 'verb' => $verb]);

        if ($skipped > 0) {
            $text .= ' '.__(':skipped skipped.', ['skipped' => $skipped]);
        }

        $this->refreshBoard();

        Flux::toast(variant: $skipped > 0 ? 'warning' : 'success', text: $text);
    }

    public function openBulkStatusModal(): void
    {
        if (! $this->canUpdateLeads || $this->selectedLeadIds === []) {
            return;
        }

        $this->bulkStatusId = '';
        $this->resetErrorBag('bulkStatusId');
        $this->showBulkStatusModal = true;
    }

    /**
     * Replicates Show::updateStatus()'s status-only side effects (status
     * log row, LeadStatusChanged event, scoring recompute, LeadUpdated
     * event, first_contacted_at/closed_at bookkeeping) per Lead.
     * Deliberately NOT calling into Show — that component is untouched,
     * this is a parallel implementation of the same rules.
     *
     * TOCTOU fix: the whole bounded batch (MAX_BULK_SELECTION = 100 leads)
     * now runs inside ONE outer DB::transaction(), starting with a single
     * shared (FOR SHARE) coordination lock on the tenant row, with
     * $newStatus resolved immediately after — see
     * Platform\Clients\LeadSources::toggleLeadStatus()'s own docblock for
     * the full readers/writer protocol. Re-acquiring the lock per lead
     * would be pointless (the same tenant row, same lock, for the same
     * logical operation) and would still leave a window between releasing
     * it for one lead and re-acquiring it for the next — so the lock is
     * acquired once and held for the entire outer transaction.
     *
     * Per-lead failure isolation preserved via a nested DB::transaction()
     * per lead: Laravel issues a real PostgreSQL SAVEPOINT for a
     * transaction() call made while already inside one (confirmed against
     * this app's own Postgres connection: transaction level goes 1→2 on
     * entry, back to 1 on exit, whether committed or rolled back), so a
     * failure inside one lead's nested transaction rolls back only that
     * lead's own writes, never the outer transaction or any
     * already-committed-to-savepoint lead before it — restoring this
     * method's original "one failure can't roll back everything else
     * already applied" guarantee even though every lead now shares one
     * outer transaction/lock. The original per-lead DB::transaction() call
     * had no surrounding try/catch, so an uncaught exception aborted the
     * whole loop (only earlier, already-separately-committed leads stayed
     * applied); wrapping the nested call in try/catch here additionally
     * lets the loop continue to the remaining leads after an isolated
     * failure — a strict improvement over the original, not merely a
     * restoration of it. A caught failure is logged (mirroring
     * NurtureInactiveLeads::processLead()'s identical per-item isolation
     * pattern) and treated as not-succeeded for the existing
     * reportBulkResult() partial-success toast, the same way an
     * authorization skip already is.
     */
    public function applyBulkStatusChange(LeadScoringService $scoringService): void
    {
        $validated = $this->validate([
            'bulkStatusId' => ['required', Rule::exists('tenant_lead_statuses', 'lead_status_id')->where('tenant_id', Auth::user()->tenant_id)],
        ]);

        $tenantId = Auth::user()->tenant_id;
        $attempted = min(count($this->selectedLeadIds), self::MAX_BULK_SELECTION);

        $succeeded = DB::transaction(function () use ($validated, $tenantId, $scoringService): ?int {
            $tenant = Tenant::whereKey($tenantId)->sharedLock()->firstOrFail();

            $newStatus = $tenant->leadStatuses->firstWhere('id', $validated['bulkStatusId']);

            if ($newStatus === null) {
                return null;
            }

            $succeeded = 0;

            foreach ($this->eligibleSelectedLeads() as $lead) {
                if (Gate::denies('update', $lead)) {
                    continue;
                }

                try {
                    DB::transaction(function () use ($lead, $newStatus, $scoringService): void {
                        $oldStatus = $lead->status;

                        if ($oldStatus->id !== $newStatus->id) {
                            $updates = ['status_id' => $newStatus->id];

                            if ($oldStatus->id === $lead->tenant->initialLeadStatus()?->id) {
                                $updates['first_contacted_at'] = $lead->first_contacted_at ?? now();
                            }

                            if ($newStatus->is_closed) {
                                $updates['closed_at'] = now();
                            }

                            $lead->update($updates);

                            LeadStatusLog::create([
                                'lead_id' => $lead->id,
                                'changed_by' => Auth::id(),
                                'old_status_id' => $oldStatus->id,
                                'new_status_id' => $newStatus->id,
                            ]);

                            event(new LeadStatusChanged($lead, $oldStatus, $newStatus, Auth::user()));
                            $scoringService->computeFor($lead, 'status_priority_changed');
                            event(new LeadUpdated($lead));
                        }
                    });
                } catch (Throwable $e) {
                    Log::error("Bulk status change failed for lead [{$lead->id}]: {$e->getMessage()}");

                    continue;
                }

                $succeeded++;
            }

            return $succeeded;
        });

        if ($succeeded === null) {
            return;
        }

        $this->showBulkStatusModal = false;
        $this->reportBulkResult($succeeded, $attempted, __('updated'));
    }

    public function openBulkPriorityModal(): void
    {
        if (! $this->canUpdateLeads || $this->selectedLeadIds === []) {
            return;
        }

        $this->bulkPriority = '';
        $this->resetErrorBag('bulkPriority');
        $this->showBulkPriorityModal = true;
    }

    /**
     * Replicates Show::updateStatus()'s priority-only side effects (the
     * LeadDetailUpdateLog 'priority' entry + scoring recompute +
     * LeadUpdated), same transaction-per-Lead shape as
     * applyBulkStatusChange().
     */
    public function applyBulkPriorityChange(LeadScoringService $scoringService): void
    {
        $validated = $this->validate(['bulkPriority' => ['required', Rule::enum(LeadPriority::class)]]);
        $newPriority = LeadPriority::from($validated['bulkPriority']);

        $attempted = min(count($this->selectedLeadIds), self::MAX_BULK_SELECTION);
        $succeeded = 0;

        foreach ($this->eligibleSelectedLeads() as $lead) {
            if (Gate::denies('update', $lead)) {
                continue;
            }

            DB::transaction(function () use ($lead, $newPriority, $scoringService): void {
                if ($lead->priority === $newPriority) {
                    return;
                }

                $lead->update(['priority' => $newPriority]);

                LeadDetailUpdateLog::create([
                    'lead_id' => $lead->id,
                    'changed_by' => Auth::id(),
                    'changed_fields' => ['priority'],
                ]);

                $scoringService->computeFor($lead, 'status_priority_changed');
                event(new LeadUpdated($lead));
            });

            $succeeded++;
        }

        $this->showBulkPriorityModal = false;
        $this->reportBulkResult($succeeded, $attempted, __('updated'));
    }

    public function openBulkAssignModal(): void
    {
        if (! $this->isClientAdmin || ! $this->canUpdateLeads() || $this->selectedLeadIds === []) {
            return;
        }

        $this->bulkAssigneeId = '';
        $this->resetErrorBag('bulkAssigneeId');
        $this->showBulkAssignModal = true;
    }

    /**
     * Reuses LeadAssignmentService::assign() directly (not autoAssign() —
     * this must never invoke round-robin/least-loaded distribution) per
     * eligible Lead, replicating Show::assign()'s no-op guard so a Lead
     * already assigned to the chosen assignee isn't re-assigned (which
     * would otherwise duplicate its LeadAssignment history row, re-fire
     * LeadAssignedNotification, and reset the assignee's
     * last_assigned_at — the exact bug .ai/rules/leads.md documents as
     * already fixed once for the single-Lead path).
     */
    public function applyBulkAssign(LeadAssignmentService $assignmentService): void
    {
        $validated = $this->validate(['bulkAssigneeId' => ['required', 'string']]);

        $assignee = TenantUser::where('tenant_id', Auth::user()->tenant_id)->find((string) $validated['bulkAssigneeId']);

        if (! $assignee) {
            $this->addError('bulkAssigneeId', __('Please select a valid user.'));

            return;
        }

        $attempted = min(count($this->selectedLeadIds), self::MAX_BULK_SELECTION);
        $succeeded = 0;

        foreach ($this->eligibleSelectedLeads() as $lead) {
            if (Gate::denies('assign', $lead)) {
                continue;
            }

            DB::transaction(function () use ($lead, $assignee, $assignmentService): void {
                if ($lead->assigned_to === $assignee->id) {
                    return;
                }

                $assignmentService->assign($lead, $assignee, Auth::user());
                $lead->refresh();
                event(new LeadUpdated($lead));
            });

            $succeeded++;
        }

        $this->showBulkAssignModal = false;
        $this->reportBulkResult($succeeded, $attempted, __('assigned'));
    }

    /**
     * Reuses LeadAssignmentService::unassign() per eligible Lead, and —
     * since unassign() itself deliberately writes no history row and fires
     * no event (its docblock: "the caller is responsible for broadcasting
     * LeadUpdated") — replicates the exact same manual LeadDetailUpdateLog
     * 'unassigned' entry + LeadUpdated event Show::assign()'s own unassign
     * branch writes for the single-Lead path.
     */
    public function bulkUnassign(): void
    {
        if (! $this->isClientAdmin || $this->selectedLeadIds === []) {
            return;
        }

        $assignmentService = app(LeadAssignmentService::class);
        $attempted = min(count($this->selectedLeadIds), self::MAX_BULK_SELECTION);
        $succeeded = 0;

        foreach ($this->eligibleSelectedLeads() as $lead) {
            if (Gate::denies('assign', $lead)) {
                continue;
            }

            DB::transaction(function () use ($lead, $assignmentService): void {
                if ($lead->assigned_to === null) {
                    return;
                }

                $assignmentService->unassign($lead);

                LeadDetailUpdateLog::create([
                    'lead_id' => $lead->id,
                    'changed_by' => Auth::id(),
                    'changed_fields' => ['assigned_to'],
                    'action' => 'unassigned',
                ]);

                $lead->refresh();
                event(new LeadUpdated($lead));
            });

            $succeeded++;
        }

        $this->reportBulkResult($succeeded, $attempted, __('unassigned'));
    }

    public function openBulkTagModal(string $action): void
    {
        if (! $this->canUpdateLeads || $this->selectedLeadIds === [] || ! in_array($action, ['attach', 'remove'], true)) {
            return;
        }

        $this->bulkTagAction = $action;
        $this->bulkTagId = '';
        $this->resetErrorBag('bulkTagId');
        $this->showBulkTagModal = true;
    }

    /**
     * Reuses Show::attachTag()/detachTag()'s idempotent
     * syncWithoutDetaching()/detach() calls per eligible Lead, only writing
     * a LeadDetailUpdateLog tag_added/tag_removed entry for a Lead where
     * the tag's presence actually changed — matching the single-Lead path,
     * which never logs a no-op attach/detach either.
     */
    public function applyBulkTagAction(): void
    {
        $validated = $this->validate(['bulkTagId' => ['required', 'string']]);

        $tag = LeadTag::where('tenant_id', Auth::user()->tenant_id)->find((string) $validated['bulkTagId']);

        if (! $tag) {
            $this->addError('bulkTagId', __('Please select a valid tag.'));

            return;
        }

        $isAttach = $this->bulkTagAction === 'attach';
        $attempted = min(count($this->selectedLeadIds), self::MAX_BULK_SELECTION);
        $succeeded = 0;

        foreach ($this->eligibleSelectedLeads() as $lead) {
            if (Gate::denies('update', $lead)) {
                continue;
            }

            DB::transaction(function () use ($lead, $tag, $isAttach): void {
                $currentlyAttached = $lead->tags->contains('id', $tag->id);

                if ($isAttach === $currentlyAttached) {
                    return;
                }

                if ($isAttach) {
                    $lead->tags()->syncWithoutDetaching([$tag->id => ['tagged_by' => Auth::id()]]);
                    $action = 'tag_added';
                } else {
                    $lead->tags()->detach($tag->id);
                    $action = 'tag_removed';
                }

                LeadDetailUpdateLog::create([
                    'lead_id' => $lead->id,
                    'changed_by' => Auth::id(),
                    'changed_fields' => ['tag' => $tag->name],
                    'action' => $action,
                ]);

                event(new LeadUpdated($lead));
            });

            $succeeded++;
        }

        $this->showBulkTagModal = false;
        $this->reportBulkResult($succeeded, $attempted, $isAttach ? __('tagged') : __('untagged'));
    }

    /**
     * Per-Lead LeadPolicy::delete() check (Client-Admin-only, same as
     * single-Lead delete) PLUS a new source-type restriction scoped to
     * bulk delete specifically — email/website_form/manual only, matching
     * the per-row Delete button's existing UI-only restriction in
     * leads.blade.php, but enforced server-side here rather than left as a
     * UI-only gap the way the single-Lead path still is (out of scope to
     * change here — see the approved spec). No activity/aggregate log
     * entry beyond each Lead's own cascade-deleted history. Called from
     * deleteLeadConfirmed() once the shared password check already passed.
     */
    private function performBulkDelete(): void
    {
        $attempted = min(count($this->selectedLeadIds), self::MAX_BULK_SELECTION);
        $succeeded = 0;
        $wasShowingSelectedLead = false;
        $survivorsRefused = 0;

        foreach ($this->eligibleSelectedLeads() as $lead) {
            $deletableSourceType = $lead->source->type->isIndividuallyDeletable();

            if (! $deletableSourceType || Gate::denies('delete', $lead)) {
                continue;
            }

            $refusal = null;

            DB::transaction(function () use ($lead, &$refusal): void {
                // PROD-1 — same authoritative, row-locking check as the single
                // delete. Merged losers never get here (eligibleSelectedLeads()
                // excludes them); a survivor — or a lead merged since that
                // query ran — is refused and counted as skipped.
                $eligibility = app(LeadDeletionEligibilityService::class)->evaluate($lead);

                if (! $eligibility->eligible) {
                    $refusal = $eligibility->reason;

                    return;
                }

                if (app(RecordLeadDeletionAudit::class)->handle($lead, Auth::user(), isBulk: true) === null) {
                    return;
                }

                $lead->delete();
            });

            if ($refusal !== null) {
                if ($refusal === LeadDeletionEligibilityService::survivorReason()) {
                    $survivorsRefused++;
                }

                continue;
            }

            if ($this->selectedLeadId === $lead->id) {
                $wasShowingSelectedLead = true;
            }

            $succeeded++;
        }

        if ($wasShowingSelectedLead) {
            $this->showLeadPanel = false;
            $this->selectedLeadId = null;
            $this->openLeadId = null;
        }

        $this->clearSelection();
        $this->reportBulkResult($succeeded, $attempted, __('deleted'));

        if ($survivorsRefused > 0) {
            Flux::toast(variant: 'danger', text: trans_choice(
                ':count selected lead has merged leads and cannot be deleted.|:count selected leads have merged leads and cannot be deleted.',
                $survivorsRefused,
                ['count' => $survivorsRefused],
            ));
        }
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

        // whereNull('merged_into_lead_id'): a lead merged into another one
        // (see App\Services\LeadMergeService) is never deleted, just
        // retired from every normal view — this is the one shared query
        // every list/board on this page is built from, so this alone keeps
        // a merged-away lead out of the table, the Kanban board, and the
        // status-count aggregates without touching anything else here.
        $query = Lead::query()->where('tenant_id', $user->tenant_id)->whereNull('merged_into_lead_id');

        // Deliberately guaranteed-empty (not skipped outright — leads()/
        // boardLeads()/boardLeadCounts() all still need a real Builder/
        // Paginator of the correct shape) until the wire:init-triggered
        // completeInitialLoad() request lands — see $initialLoadComplete's
        // docblock. Keeps the very first page response cheap: no joins, no
        // status/priority/date filtering, nothing but a trivial false
        // predicate, regardless of how large this tenant's lead table gets.
        if (! $this->initialLoadComplete) {
            $query->whereRaw('1 = 0');

            return $query;
        }

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

                return in_array($value, [LeadSourceType::Manual->value, LeadSourceType::CsvImport->value], true)
                    || in_array($value, $configuredTypes, true);
            },
        ));
    }
}
