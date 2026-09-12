<?php

namespace App\Livewire\Tenant\Leads;

use App\Actions\Tenant\RecordLeadConsent;
use App\Actions\Tenant\SaveLeadCustomFieldValues;
use App\Enums\ConsentSource;
use App\Enums\ConsentStatus;
use App\Enums\ConsentType;
use App\Enums\DealStatus;
use App\Enums\LeadDuplicateLinkStatus;
use App\Enums\LeadPriority;
use App\Enums\LeadSourceType;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Enums\TaskStatus;
use App\Events\LeadStatusChanged;
use App\Events\LeadUpdated;
use App\Exceptions\LeadMergeException;
use App\Jobs\SendOutboundMessage;
use App\Livewire\Tenant\Leads\Concerns\ConfirmsLeadDeletionPassword;
use App\Models\Conversation;
use App\Models\Currency;
use App\Models\Deal;
use App\Models\DealStatusLog;
use App\Models\Lead;
use App\Models\LeadAttachment;
use App\Models\LeadConsent;
use App\Models\LeadConsentEvent;
use App\Models\LeadCustomFieldDefinition;
use App\Models\LeadDetailUpdateLog;
use App\Models\LeadDuplicateLink;
use App\Models\LeadNote;
use App\Models\LeadScoreLog;
use App\Models\LeadStatus;
use App\Models\LeadStatusLog;
use App\Models\LeadTag;
use App\Models\Message;
use App\Models\Task;
use App\Models\TaskStatusLog;
use App\Models\TenantUser;
use App\Notifications\FollowUpCancelledNotification;
use App\Notifications\FollowUpUpdatedNotification;
use App\Notifications\MeetingCancelledNotification;
use App\Notifications\MeetingUpdatedNotification;
use App\Notifications\TaskAssignedNotification;
use App\Services\LeadAdminNotifier;
use App\Services\LeadAssignmentService;
use App\Services\LeadDuplicateDetectionService;
use App\Services\LeadMergeService;
use App\Services\LeadScoringService;
use App\Services\OutboundEligibilityService;
use App\Support\EligibilityResult;
use Carbon\CarbonInterface;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Lead Details')]
class Show extends Component
{
    use ConfirmsLeadDeletionPassword;

    /**
     * Human labels for the CRM fields Contact/Company/Deal-form edits can
     * touch, keyed by the Lead column they write to — used to translate
     * LeadDetailUpdateLog's stored column keys into readable Activity feed
     * text. Translation happens at render time (see activity() below) so
     * historical entries keep displaying correctly if wording changes
     * later, rather than baking a translated string into the log itself.
     *
     * @var array<string, string>
     */
    private const array FIELD_LABELS = [
        'name' => 'Contact Person',
        'phone' => 'Phone',
        'email' => 'Email',
        'company' => 'Company',
        'website' => 'Website',
        'industry' => 'Industry',
        'location' => 'Location',
        'service_interest' => 'Service Interested',
        'lead_source' => 'Lead Source',
        'estimated_budget' => 'Est. Budget',
        'estimated_budget_currency_id' => 'Est. Budget Currency',
        'deal_value' => 'Deal Value',
        'deal_value_currency_id' => 'Deal Value Currency',
        'win_probability' => 'Win Probability',
        'next_follow_up_at' => 'Next Follow-up',
        'meeting_date' => 'Meeting Date',
        'meeting_time' => 'Meeting Time',
        'priority' => 'Priority',
    ];

    public Lead $lead;

    public string $status;

    public string $priority;

    public ?string $assigneeId = null;

    public string $newNote = '';

    /**
     * Feature #15 — the composer's draft text, reset after each successful
     * sendMessage() call, same pattern as $newNote after addNote().
     */
    public string $newMessageBody = '';

    /**
     * The "Add tag" picker's currently-selected tag, bound to a dropdown
     * populated from availableTagsToAttach() — reset after each successful
     * attachTag() call, same pattern as $newNote after addNote().
     */
    public string $selectedTagId = '';

    public bool $showDealModal = false;

    public ?string $editingDealId = null;

    public string $dealName = '';

    public string $dealValue = '';

    public string $dealCurrencyId = '';

    public string $dealProbability = '';

    public string $dealExpectedCloseDate = '';

    public string $dealOwnerId = '';

    public string $dealNotes = '';

    public bool $showDeleteDealModal = false;

    public ?string $confirmingDeleteDealId = null;

    public bool $showTaskModal = false;

    public ?string $editingTaskId = null;

    public string $taskTitle = '';

    public string $taskDescription = '';

    public string $taskDealId = '';

    public string $taskDueDate = '';

    public string $taskDueTime = '';

    public string $taskPriority = '';

    public string $taskAssignedTo = '';

    public bool $showDeleteTaskModal = false;

    public ?string $confirmingDeleteTaskId = null;

    public string $bodyView = 'text';

    /**
     * Which panel the full-page view's Overview/Activity Timeline toggle is
     * showing — display-only, doesn't affect the flyout (show-panel.blade.php
     * has no tabs) or any of the underlying data/actions.
     */
    public string $activeTab = 'overview';

    /**
     * Whether the CURRENTLY active tab's cards are ready to show real
     * content — resets to false every time switchTab() runs, flips back
     * to true via markTabContentReady() (wire:init on that tab's own root
     * element in show.blade.php). That root ALSO needs an explicit
     * wire:key (e.g. wire:key="activity-tab-content") distinct per tab —
     * without one, Livewire's morph reuses the SAME DOM node for both the
     * Overview and Activity branches' roots (confirmed empirically: tagged
     * the node with a JS marker, switched tabs, the marker survived), since
     * a Blade if/else alone doesn't guarantee removal — the two branches
     * just happen to occupy the same tree position, so morphdom patches in
     * place rather than treating them as distinct elements. wire:init only
     * fires on a genuine connect-to-DOM event, so without the key it
     * silently never refires past the very first tab shown. Drives the
     * SAME skeleton-toggle pattern used everywhere else in this app
     * (contact-info-card.blade.php,
     * pipeline-attributes-card.blade.php, status-assignment-form.blade.php
     * unconditionally; detail-card.blade.php/activity-card.blade.php/
     * note-form.blade.php conditionally on `! $panel`, since those three
     * are ALSO used by show-panel.blade.php's flyout, which has no tabs at
     * all and keeps using $initialLoadComplete/completeInitialLoad()
     * instead — see those partials for the exact branching).
     *
     * Purely a visual reveal-together toggle, like the rest of this page's
     * cards — does NOT gate activity()'s actual query (that stays behind
     * $initialLoadComplete, a real one-time perf-motivated defer); by the
     * time this flips true on a tab switch, $this->activity has already
     * recomputed fresh anyway, since Computed properties aren't cached
     * across separate Livewire requests.
     */
    public bool $tabContentReady = false;

    public string $leadName = '';

    public string $leadPhone = '';

    public string $leadEmail = '';

    public string $leadCompany = '';

    public string $leadWebsite = '';

    public string $leadIndustry = '';

    public string $leadLocation = '';

    public string $leadServiceInterest = '';

    public string $leadSource = '';

    public string $leadEstimatedBudget = '';

    public string $leadEstimatedBudgetCurrencyId = '';

    public string $leadDealValue = '';

    public string $leadDealValueCurrencyId = '';

    public string $leadWinProbability = '';

    public string $leadNextFollowUpAt = '';

    /**
     * Bound to a single <input type="datetime-local">, e.g.
     * "2026-09-05T14:30" — split into the separate meeting_date/meeting_time
     * columns in updateDeal(), since the time is record-only and stored
     * apart from the date meetingAlertActive() actually compares against.
     */
    public string $leadMeetingDate = '';

    /**
     * True when this component is embedded in the Kanban board's lead
     * detail flyout (App\Livewire\Tenant\Leads\Index) rather than rendered
     * as the full `/leads/{lead}` page — swaps to a single-column,
     * drawer-width view and reports mutations back to the board via
     * dispatched events instead of a page redirect.
     */
    public bool $panel = false;

    /**
     * Per-card "is this card currently showing its edit form" state for the
     * full-page view's Contact/Company/Deal cards on a manual lead — each
     * card starts read-only and only switches to its form when its own
     * "Edit" link is clicked, independently of the other two. Meaningless
     * (never read) on the Kanban flyout (show-panel.blade.php), which has
     * no per-card toggle of its own — its forms are simply always shown
     * whenever canUpdate is true.
     */
    public bool $editingContact = false;

    public bool $editingCompany = false;

    public bool $editingDeal = false;

    /**
     * Feature #11 — same toggle pattern as editingContact/editingCompany/
     * editingDeal above. Meaningless on the Kanban flyout for the same
     * reason those are: show-panel.blade.php has no per-card toggle, its
     * forms are simply always shown whenever canUpdate is true.
     */
    public bool $editingCustomFields = false;

    /**
     * Feature #11 — keyed by lead_custom_field_definition_id, bound via
     * wire:model="customFieldValues.{id}" in the Custom Fields card's edit
     * form. Only ever validated/saved against this tenant's currently
     * ACTIVE definitions (see activeCustomFieldDefinitions() and
     * App\Actions\Tenant\SaveLeadCustomFieldValues) — a stale key left over
     * from a definition deactivated between opening and submitting the form
     * is simply never looked at, never trusted.
     *
     * @var array<string, mixed>
     */
    public array $customFieldValues = [];

    public bool $showDeleteModal = false;

    public bool $showCancelMeetingModal = false;

    public bool $showCancelFollowUpModal = false;

    public bool $showMergeModal = false;

    /**
     * Which pending LeadDuplicateLink the merge modal is currently open for
     * — set by openMergeModal(), read by mergeConflicts()/confirmMerge(),
     * cleared by closeMergeModal(). A plain nullable string rather than the
     * model itself, same reasoning as $status/$assigneeId: keeps public
     * Livewire state serializable between requests.
     */
    public ?string $mergeLinkId = null;

    /**
     * Per-conflicting-field choice for the merge modal's radio groups —
     * keyed by column name, each value either 'survivor' or 'loser'.
     * openMergeModal() seeds every conflicting field to 'survivor' (the
     * safe, non-destructive default); a field is only ever pulled from the
     * loser by an explicit choice here. See LeadMergeService::merge().
     *
     * @var array<string, string>
     */
    public array $mergeFieldResolutions = [];

    /**
     * Starts false on every fresh mount so the very first paint can skip
     * activity()'s four unbounded queries (status logs, assignments,
     * notes, detail-update logs — this lead's full history, which only
     * grows over its lifetime) and show a skeleton in the Activity feed
     * instead — same technique as
     * App\Livewire\Tenant\Leads\Index::$initialLoadComplete. The lead's
     * own core fields (name, company, deal value, ...) are NOT gated by
     * this — $lead is loaded eagerly in mount() regardless, this only
     * defers the secondary activity feed. Deliberately does NOT also gate
     * timeline() (the message/attachment conversation feed in
     * detail-card.blade.php) — that partial's outer @if branches on
     * $lead->messageTimeline() directly, not on $this->timeline, so
     * deferring the latter alone would leave that branch showing an empty
     * box instead of a skeleton; fixing that needs its own pass, not a
     * copy-paste of this pattern.
     */
    public bool $initialLoadComplete = false;

    public function completeInitialLoad(): void
    {
        $this->initialLoadComplete = true;
    }

    public function switchTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->tabContentReady = false;
    }

    public function markTabContentReady(): void
    {
        $this->tabContentReady = true;
    }

    public function mount(Lead $lead): void
    {
        Gate::authorize('view', $lead);

        // A lead merged into another one (see LeadMergeService) is never
        // deleted, so an old bookmark/link to it must not 404 — it lands the
        // viewer on the surviving lead instead. No toast: matches delete()'s
        // existing silent-redirect precedent below, since a Livewire
        // wire:navigate redirect doesn't carry this component's state to the
        // next page anyway.
        if ($lead->merged_into_lead_id !== null) {
            $this->redirect(route('tenant-leads.show', $lead->merged_into_lead_id), navigate: true);

            return;
        }

        $lead->markUpdateSeen();

        $this->lead = $lead;
        $this->status = $lead->status_id;
        $this->priority = $lead->priority->value;
        $this->assigneeId = $lead->assigned_to;
        $this->fillDetailFieldsFromLead();
    }

    /**
     * Populates every Contact/Company/Deal form field from the lead's
     * current column values — shared by mount() and refreshLead() so a live
     * update from another viewer doesn't leave a stale draft sitting in
     * these fields. Cancelling a single card's edit must NOT call this: it
     * would also wipe any still-unsaved draft sitting in either of the
     * *other* two cards, which may be independently open for editing at the
     * same time. Use the three scoped fillXxxFieldsFromLead() methods below
     * for that instead.
     */
    private function fillDetailFieldsFromLead(): void
    {
        $this->fillContactFieldsFromLead();
        $this->fillCompanyFieldsFromLead();
        $this->fillDealFieldsFromLead();
        $this->fillCustomFieldValuesFromLead();
    }

    private function fillContactFieldsFromLead(): void
    {
        $this->leadName = $this->lead->name;
        $this->leadPhone = (string) $this->lead->phone;
        $this->leadEmail = (string) $this->lead->email;
    }

    private function fillCompanyFieldsFromLead(): void
    {
        $this->leadCompany = (string) $this->lead->company;
        $this->leadWebsite = (string) $this->lead->website;
        $this->leadIndustry = (string) $this->lead->industry;
        $this->leadLocation = (string) $this->lead->location;
    }

    private function fillDealFieldsFromLead(): void
    {
        $this->leadServiceInterest = (string) $this->lead->service_interest;
        $this->leadSource = (string) $this->lead->lead_source;
        $this->leadEstimatedBudget = $this->lead->estimated_budget !== null ? (string) $this->lead->estimated_budget : '';
        $this->leadEstimatedBudgetCurrencyId = $this->lead->estimated_budget_currency_id ?? (string) $this->tenantCurrencies->first()?->id;
        $this->leadDealValue = $this->lead->deal_value !== null ? (string) $this->lead->deal_value : '';
        $this->leadDealValueCurrencyId = $this->lead->deal_value_currency_id ?? (string) $this->tenantCurrencies->first()?->id;
        $this->leadWinProbability = $this->lead->win_probability !== null ? (string) $this->lead->win_probability : '';
        $this->leadNextFollowUpAt = $this->lead->next_follow_up_at?->toDateString() ?? '';
        $this->leadMeetingDate = $this->lead->meeting_date
            ? $this->lead->meeting_date->format('Y-m-d').'T'.($this->lead->meeting_time !== null ? substr($this->lead->meeting_time, 0, 5) : '00:00')
            : '';
    }

    /**
     * Feature #11 — only pre-fills the tenant's currently ACTIVE
     * definitions (matching the edit form, which only renders those); an
     * inactive field's historical value is display-only (see
     * customFieldDisplayRows()) and is never bound into this editable
     * array.
     */
    private function fillCustomFieldValuesFromLead(): void
    {
        $existingValues = $this->lead->customFieldValues()->get()->keyBy('lead_custom_field_definition_id');

        $this->customFieldValues = $this->activeCustomFieldDefinitions
            ->mapWithKeys(function (LeadCustomFieldDefinition $definition) use ($existingValues): array {
                $existingValue = $existingValues->get($definition->id);

                return [$definition->id => $existingValue !== null ? (string) $existingValue->value : ''];
            })
            ->all();
    }

    public function render(): View
    {
        return view($this->panel ? 'livewire.tenant.leads.show-panel' : 'livewire.tenant.leads.show');
    }

    /**
     * Est. Budget and Deal Value are almost always quoted in the same
     * currency, so the Kanban flyout's two pickers stay mirrored — see the
     * matching hook in App\Livewire\Tenant\Leads\Index (the "New Lead"
     * form uses that component instead of this one).
     */
    public function updated(string $property): void
    {
        if ($property === 'leadEstimatedBudgetCurrencyId') {
            $this->leadDealValueCurrencyId = $this->leadEstimatedBudgetCurrencyId;
        }

        if ($property === 'leadDealValueCurrencyId') {
            $this->leadEstimatedBudgetCurrencyId = $this->leadDealValueCurrencyId;
        }
    }

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        return [
            'echo-private:lead.'.$this->lead->id.',.lead.updated' => 'refreshLead',
        ];
    }

    /**
     * `$lead` is a plain public property (not a Computed one), so a bare
     * `$refresh` re-renders with the stale snapshot already on the client —
     * it doesn't re-query. Explicitly reload it so a follow-up webhook
     * message actually shows up.
     */
    public function refreshLead(): void
    {
        $this->lead->refresh();
        $this->status = $this->lead->status_id;
        $this->priority = $this->lead->priority->value;
        $this->assigneeId = $this->lead->assigned_to;
        $this->fillDetailFieldsFromLead();
        unset($this->activity, $this->timeline, $this->possibleDuplicates, $this->customFieldDisplayRows);
    }

    #[Computed]
    public function isClientAdmin(): bool
    {
        return Auth::user()->isClientAdmin();
    }

    /**
     * Gates the "Lead Source" field: it's plain descriptive metadata (e.g.
     * "Referral", "Trade Show"), not the LeadSource relation every lead
     * already has via source_id — asking a webhook-captured lead's viewer
     * to fill in how it was "sourced" doesn't make sense when the system
     * already knows exactly which channel captured it, so the field only
     * exists on leads created through the manual-entry form.
     */
    #[Computed]
    public function isManualLead(): bool
    {
        return $this->lead->source->type === LeadSourceType::Manual;
    }

    /**
     * This tenant's enabled currencies, for the Est. Budget / Deal Value
     * dropdowns — same catalog subset as App\Livewire\Tenant\Leads\Index.
     */
    #[Computed]
    public function tenantCurrencies()
    {
        return $this->lead->tenant->currencies()->orderBy('code')->get();
    }

    /**
     * Gates the status/priority form and the "Add Note" form — used to hide
     * both from a viewer who can see this lead but isn't allowed to change
     * it (e.g. a Client User without the module's "update" permission),
     * rather than letting them submit and hit a denial.
     */
    #[Computed]
    public function canUpdate(): bool
    {
        return Gate::allows('update', $this->lead);
    }

    #[Computed]
    public function canAssign(): bool
    {
        return Gate::allows('assign', $this->lead);
    }

    #[Computed]
    public function canDelete(): bool
    {
        return Gate::allows('delete', $this->lead);
    }

    #[Computed]
    public function canMerge(): bool
    {
        return Gate::allows('merge', $this->lead);
    }

    /**
     * This tenant's currently active custom field definitions (Feature
     * #11), in display order — the single source of truth for both which
     * fields the edit form renders/validates against and
     * fillCustomFieldValuesFromLead()'s pre-fill. Explicitly filtered by
     * tenant_id rather than relying on a global scope, same discipline as
     * every other query in this module.
     *
     * @return Collection<int, LeadCustomFieldDefinition>
     */
    #[Computed]
    public function activeCustomFieldDefinitions(): Collection
    {
        return LeadCustomFieldDefinition::query()
            ->where('tenant_id', $this->lead->tenant_id)
            ->active()
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Every row the read-only "Custom Fields" display should show: this
     * tenant's active definitions, PLUS any definition that's since been
     * deactivated but still has a value on THIS lead — a deactivated
     * field's already-captured value must stay visible, never disappear,
     * even though it's no longer editable (see
     * LeadCustomFieldDefinition::hasValues()'s docblock). One bounded query
     * for the lead's own values (eager-loading `definition`), never one
     * query per field.
     *
     * @return Collection<int, array{definition: LeadCustomFieldDefinition, value: ?string}>
     */
    #[Computed]
    public function customFieldDisplayRows(): Collection
    {
        $values = $this->lead->customFieldValues()->with('definition')->get()->keyBy('lead_custom_field_definition_id');

        $definitionsById = $this->activeCustomFieldDefinitions->keyBy('id');

        foreach ($values as $definitionId => $value) {
            // $value->definition can't actually be null here: the FK is
            // restrictOnDelete and LeadCustomFieldDefinition deletion is
            // itself blocked while any value exists (see
            // LeadCustomFieldDefinition::hasValues()) — an orphaned value
            // row pointing at a deleted definition can't exist.
            if (! $definitionsById->has($definitionId)) {
                $definitionsById->put($definitionId, $value->definition);
            }
        }

        return $definitionsById->values()
            ->sortBy([['sort_order', 'asc'], ['created_at', 'asc']])
            ->map(fn (LeadCustomFieldDefinition $definition): array => [
                'definition' => $definition,
                'value' => $values->get($definition->id)?->value,
            ])
            ->values();
    }

    public function startEditingCustomFields(): void
    {
        $this->editingCustomFields = true;
    }

    public function cancelEditingCustomFields(): void
    {
        $this->editingCustomFields = false;
        $this->fillCustomFieldValuesFromLead();
    }

    /**
     * Validates the submitted values against this tenant's currently active
     * definitions (built at runtime, same "assembled from the current
     * catalog" approach as Index::saveLead()'s own custom-field validation),
     * persists via the same SaveLeadCustomFieldValues action that path
     * uses, and writes exactly one LeadDetailUpdateLog entry naming the
     * fields that actually changed — or none at all on a true no-op, same
     * diff-based discipline as applyDetailChanges().
     */
    public function updateCustomFields(SaveLeadCustomFieldValues $saveCustomFields): void
    {
        if (Gate::denies('update', $this->lead)) {
            Flux::toast(variant: 'danger', text: __("You don't have permission to update this lead."));

            return;
        }

        $rules = [];

        foreach ($this->activeCustomFieldDefinitions as $definition) {
            $rules['customFieldValues.'.$definition->id] = $definition->validationRules();
        }

        // An empty array passed to validate() doesn't mean "validate
        // nothing" — Livewire falls back to a $rules property/rules()
        // method, which this component has neither of, and throws. Only
        // validate when this tenant actually has an active custom field.
        if ($rules !== []) {
            $this->validate($rules);
        }

        $changedFieldNames = $saveCustomFields->handle($this->lead, $this->lead->tenant_id, $this->customFieldValues);

        $this->editingCustomFields = false;

        if ($changedFieldNames === []) {
            return;
        }

        LeadDetailUpdateLog::create([
            'lead_id' => $this->lead->id,
            'changed_by' => Auth::id(),
            'changed_fields' => $changedFieldNames,
        ]);

        unset($this->activity, $this->customFieldDisplayRows);

        event(new LeadUpdated($this->lead));

        if ($this->panel) {
            $this->dispatch('lead-panel-updated');
        }

        Flux::toast(variant: 'success', text: __('Lead updated.'));
    }

    /**
     * This tenant's own tag catalog (see App\Models\LeadTag's docblock for
     * why it's tenant self-service, not a Platform-Admin-managed shared
     * catalog) — explicitly filtered by tenant_id, same discipline as every
     * other query in this module. Used both for the "Add tag" picker's
     * available options and the empty-catalog "Manage Tags" prompt.
     *
     * @return Collection<int, LeadTag>
     */
    /**
     * Feature #14 — this lead's CURRENT consent state for every
     * App\Enums\ConsentType, keyed by that type's own value so the Consent
     * card can look up "privacy"/"marketing" directly rather than
     * searching a plain list. A type with no entry here means Not
     * Provided — never a placeholder row (see App\Models\LeadConsent's
     * docblock).
     *
     * @return Collection<string, LeadConsent>
     */
    #[Computed]
    public function currentConsents(): Collection
    {
        return $this->lead->consents()->get()->keyBy(fn (LeadConsent $consent): string => $consent->consent_type->value);
    }

    /**
     * Feature #14 — this lead's full, permanent consent history, newest
     * first — surfaced both inline on the Consent card and via the general
     * Activity feed (see activity()'s own consent_change branch).
     *
     * @return Collection<int, LeadConsentEvent>
     */
    #[Computed]
    public function consentHistory(): Collection
    {
        return $this->lead->consentEvents()->with('changedBy')->orderByDesc('occurred_at')->get();
    }

    /**
     * Feature #15 — whether this lead's own source is even a conversational
     * channel at all (WhatsApp/Messenger/Instagram) — a Lead from any other
     * channel (Website Form, Email, Manual, CSV Import, LinkedIn, Facebook
     * Lead Ads) has no composer at all, by design (see the Architecture
     * Brief's UI/UX section — no unified inbox, no cross-channel composer).
     */
    #[Computed]
    public function messagingChannel(): ?MessageChannel
    {
        return MessageChannel::fromLeadSourceType($this->lead->source->type);
    }

    /**
     * The conversation this lead's composer sends through — the most
     * recently active one on this lead's own channel, if any exists yet.
     * No Conversation row exists for a lead that has never received a
     * dual-written inbound message (e.g. one created before this feature
     * shipped, with no subsequent inbound activity) — the composer treats
     * that as ineligible with its own explicit reason, never as an error.
     */
    #[Computed]
    public function activeConversation(): ?Conversation
    {
        if ($this->messagingChannel === null) {
            return null;
        }

        return $this->lead->conversations()->where('channel', $this->messagingChannel)->orderByDesc('last_inbound_at')->first();
    }

    /**
     * Re-evaluated on every render (never cached beyond the request) so a
     * status/consent/window change elsewhere is always reflected — cheap,
     * since OutboundEligibilityService issues at most a couple of indexed
     * queries.
     */
    #[Computed]
    public function messagingEligibility(): ?EligibilityResult
    {
        if ($this->activeConversation === null) {
            return $this->messagingChannel === null
                ? null
                : EligibilityResult::ineligible(__('No conversation history yet for this channel.'));
        }

        return app(OutboundEligibilityService::class)->evaluate($this->activeConversation);
    }

    /**
     * This lead's outbound messages only, newest first — surfaced on the
     * composer card so a rep can see prior replies and their delivery
     * status without needing the full Activity tab.
     *
     * @return Collection<int, Message>
     */
    #[Computed]
    public function outboundMessages(): Collection
    {
        return $this->lead->messages()->where('direction', MessageDirection::Outbound)->orderByDesc('created_at')->get();
    }

    #[Computed]
    public function tenantTags(): Collection
    {
        return LeadTag::query()->where('tenant_id', $this->lead->tenant_id)->orderBy('name')->get();
    }

    /**
     * This tenant's tags not already attached to this lead — the "Add tag"
     * dropdown's option list, so a tampered submission can't re-attach an
     * already-attached tag (attachTag() also re-verifies server-side).
     *
     * @return Collection<int, LeadTag>
     */
    #[Computed]
    public function availableTagsToAttach(): Collection
    {
        $attachedIds = $this->lead->tags->pluck('id');

        return $this->tenantTags->reject(fn (LeadTag $tag): bool => $attachedIds->contains($tag->id))->values();
    }

    /**
     * This lead's Deals — open ones first (grouped), each group newest
     * first. Not paginated: a realistic per-lead Deal count is small enough
     * that a plain list is fine for Phase 1 (see the approved architecture's
     * explicit "no Deal list/table route" scope boundary).
     *
     * @return Collection<int, Deal>
     */
    #[Computed]
    public function deals(): Collection
    {
        return $this->lead->deals()
            ->with(['currency', 'owner'])
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Drives the Deal list's "Current" badge only — see Lead::currentDeal()'s
     * own docblock for why this is a pure display convenience, never a
     * persisted flag.
     */
    #[Computed]
    public function currentDealId(): ?string
    {
        return $this->lead->currentDeal()?->id;
    }

    /**
     * The "Deal Owner" dropdown's option list — independent of Lead
     * assignment (see App\Models\Deal's docblock); reuses the same
     * tenant-scoped assignableUsers() this page already exposes for Lead
     * assignment, since both draw from the same pool of tenant users, not a
     * second lookup. A leading "Unassigned" option since owner_id is
     * nullable.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function dealOwnerOptions(): array
    {
        $options = [['value' => '', 'label' => __('Unassigned')]];

        // Plain foreach rather than collect($this->assignableUsers)->map():
        // assignableUsers() has no declared return type (pre-existing, see
        // its own docblock), so collect() can't resolve its generic from it
        // — a foreach has no such requirement.
        foreach ($this->assignableUsers as $user) {
            $options[] = ['value' => (string) $user->id, 'label' => $user->name];
        }

        return $options;
    }

    /**
     * This lead's Tasks — pending first (grouped), each group newest first,
     * same ordering shape as deals() above. Eager-loads assignee/deal so the
     * card never repeats the N+1 mistake deals() itself was caught making
     * during Feature #4's verification pass.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function tasks(): Collection
    {
        return $this->lead->tasks()
            ->with(['assignee', 'deal'])
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * This lead's Notes — newest first, eager-loading the author so the
     * notes-card partial can display their name without an N+1 query. Gate
     * is implicit: only users who can already view this lead's detail page
     * reach this computed property (see LeadPolicy::view()).
     *
     * @return Collection<int, LeadNote>
     */
    #[Computed]
    public function leadNotes(): Collection
    {
        return $this->lead->leadNotes()->with('tenantUser')->orderByDesc('created_at')->get();
    }

    /**
     * This lead's own Deals, for the "Link to Deal" dropdown — a Task's
     * deal_id must always belong to the same lead_id/tenant_id (re-verified
     * server-side in saveTask(), never trusted from this list alone). A
     * leading "No Deal" option since deal_id is nullable.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function taskDealOptions(): array
    {
        $options = [['value' => '', 'label' => __('No Deal')]];

        foreach ($this->deals as $deal) {
            $options[] = ['value' => (string) $deal->id, 'label' => $deal->name];
        }

        return $options;
    }

    /**
     * The "Assignee" dropdown's option list — same tenant-scoped
     * assignableUsers() pool dealOwnerOptions() above already draws from,
     * since a Task's assignee is independent of, but drawn from the same
     * pool as, a Deal's owner.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function taskAssigneeOptions(): array
    {
        $options = [['value' => '', 'label' => __('Unassigned')]];

        foreach ($this->assignableUsers as $user) {
            $options[] = ['value' => (string) $user->id, 'label' => $user->name];
        }

        return $options;
    }

    /**
     * Whether the current viewer may delete THIS specific task. Two
     * different bars, deliberately not just isClientAdmin() layered on top
     * of the lightweight update() gate every other Task action uses:
     *
     * - A Client Admin needs the module's "delete" permission specifically
     *   (Gate::allows('delete', $lead) — LeadPolicy::delete(), the same
     *   check Deal's own admin-only deletion uses) — NOT just "update".
     *   PermissionSlug's View/Create/Update/Delete are independently
     *   assignable per role, so a Client Admin role deliberately scoped to
     *   never delete anything in this module (e.g. a "Manager" role kept
     *   off Delete on purpose) must not be able to delete a Task either,
     *   even though they can create/edit/close/reopen one.
     * - A non-admin may delete only a task actually assigned to them
     *   (never another user's, including an unassigned one) and still
     *   needs the ordinary update() bar (module "update" permission +
     *   being assigned to the lead) — deliberately the lighter bar, not
     *   "delete", since the approved architecture is explicit that a rep
     *   deleting their own task is a day-to-day action, not a Deal-style
     *   consequential one.
     */
    public function canDeleteTask(Task $task): bool
    {
        if ($this->isClientAdmin()) {
            return Gate::allows('delete', $this->lead);
        }

        return Gate::allows('update', $this->lead) && $task->assigned_to === Auth::id();
    }

    /**
     * Gates the scoring rule-management link and the "Recalculate" button —
     * no new permission slug exists to gate scoring with (see
     * App\Policies\LeadScoringRulePolicy's docblock), so this composes the
     * same two existing checks canUpdate()/isClientAdmin() already expose,
     * the identical bar LeadPolicy::merge()/assign() already use.
     */
    #[Computed]
    public function canManageScoring(): bool
    {
        return $this->canUpdate() && $this->isClientAdmin();
    }

    /**
     * The most recent LeadScoreLog for this lead, if any — its breakdown is
     * what the detail page's "why is this lead scored X?" panel renders.
     * Read-only: unlike possibleDuplicates(), viewing this page never
     * triggers a recompute — score freshness comes entirely from the
     * explicit triggers in LeadScoringService's callers (LeadReceived,
     * status/priority changes, scoring-relevant detail edits, merges, the
     * daily sweep) plus the manual "Recalculate" action below.
     */
    #[Computed]
    public function latestScoreLog(): ?LeadScoreLog
    {
        return LeadScoreLog::query()
            ->where('lead_id', $this->lead->id)
            ->latest('computed_at')
            ->first();
    }

    /**
     * Every still-open possible-duplicate pairing involving this lead, each
     * paired with the *other* lead in it — the banner shows this regardless
     * of canMerge (informational for any viewer who can see this lead at
     * all), while the Merge/Dismiss buttons themselves stay gated by
     * canMerge in the view. Runs (and upserts) detection itself rather than
     * only reading — see LeadDuplicateDetectionService::detectFor() — kept
     * behind $initialLoadComplete like activity(), since it's the same kind
     * of secondary, deferrable query on this page.
     *
     * @return Collection<int, array{link: LeadDuplicateLink, other: Lead}>
     */
    #[Computed]
    public function possibleDuplicates(): Collection
    {
        if (! $this->initialLoadComplete) {
            return collect();
        }

        return app(LeadDuplicateDetectionService::class)
            ->detectFor($this->lead)
            ->map(fn (LeadDuplicateLink $link): array => ['link' => $link, 'other' => $link->otherLead($this->lead)]);
    }

    /**
     * The Contact/Company/Deal fields where the lead currently open in the
     * merge modal ($mergeLinkId) and its possible-duplicate disagree — drives
     * that modal's per-field radio choices. Empty once no modal is open.
     *
     * Feature #11's custom-field conflicts (namespaced "custom_field:{id}"
     * keys, see LeadMergeService::conflictingCustomFieldValues()) are merged
     * in alongside the scalar ones here rather than kept separate — the
     * modal's Blade loop (duplicate-warning.blade.php) already renders
     * `$field => $values` generically, so no template change is needed;
     * only mergeFieldLabel()/mergeFieldValueLabel() below need a
     * custom-field-aware branch.
     *
     * @return array<string, array{survivor: mixed, loser: mixed}>
     */
    #[Computed]
    public function mergeConflicts(): array
    {
        $link = $this->mergeLinkId !== null
            ? LeadDuplicateLink::query()->where('tenant_id', $this->lead->tenant_id)->find($this->mergeLinkId)
            : null;

        if ($link === null) {
            return [];
        }

        $otherLead = $link->otherLead($this->lead);
        $mergeService = app(LeadMergeService::class);

        return [
            ...$mergeService->conflictingFields($this->lead, $otherLead),
            ...$mergeService->conflictingCustomFieldValues($this->lead, $otherLead),
        ];
    }

    /**
     * The lead's text messages and downloaded attachments merged into a
     * single chronological feed, matching the order they actually arrived
     * in the conversation — a thread can carry several of each over time,
     * and neither alone reflects that order on its own.
     *
     * @return array<int, array{type: 'text', text: string, at: CarbonInterface}|array{type: 'attachment', attachment: LeadAttachment, at: CarbonInterface}>
     */
    #[Computed]
    public function timeline(): array
    {
        $items = [];

        foreach ($this->lead->messageTimeline() as $entry) {
            $items[] = ['type' => 'text', 'text' => $entry['text'], 'at' => $entry['at']];
        }

        foreach ($this->lead->attachments as $attachment) {
            $items[] = ['type' => 'attachment', 'attachment' => $attachment, 'at' => $attachment->sent_at];
        }

        usort($items, fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        return $items;
    }

    #[Computed]
    public function assignableUsers()
    {
        return TenantUser::query()
            ->where('tenant_id', $this->lead->tenant_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    #[Computed]
    public function activity(): array
    {
        if (! $this->initialLoadComplete) {
            return [];
        }

        $events = [];

        foreach ($this->lead->statusLogs()->with(['changedBy', 'oldStatus', 'newStatus'])->get() as $log) {
            $events[] = [
                'type' => 'status_change',
                'created_at' => $log->created_at,
                'user' => $log->changedBy?->name,
                'old_status' => $log->oldStatus?->name,
                'new_status' => $log->newStatus->name,
                'note' => $log->note,
            ];
        }

        foreach ($this->lead->assignments()->with(['assignedTo', 'assignedBy'])->get() as $assignment) {
            $events[] = [
                'type' => 'assignment',
                'created_at' => $assignment->created_at,
                'user' => $assignment->assignedBy?->name,
                'assigned_to' => $assignment->assignedTo?->name,
                'note' => $assignment->assignment_note,
            ];
        }

        foreach ($this->lead->leadNotes()->with('tenantUser')->get() as $note) {
            $events[] = [
                'type' => 'note',
                'created_at' => $note->created_at,
                'user' => $note->tenantUser?->name,
                'note' => $note->note,
            ];
        }

        foreach ($this->lead->detailUpdateLogs()->with('changedBy')->get() as $log) {
            if ($log->action !== null) {
                $events[] = [
                    'type' => $log->action,
                    'created_at' => $log->created_at,
                    'user' => $log->changedBy?->name,
                    'action_date' => $log->action_date,
                    // Only tag_added/tag_removed read this today (the tag's
                    // name — see attachTag()/detachTag()) — harmless no-op
                    // addition for every other action type, which don't
                    // reference 'fields' in activity-card.blade.php.
                    'fields' => $log->changed_fields,
                ];

                continue;
            }

            $events[] = [
                'type' => 'details_updated',
                'created_at' => $log->created_at,
                'user' => $log->changedBy?->name,
                'fields' => array_map(fn (string $key): string => __(self::FIELD_LABELS[$key] ?? $key), $log->changed_fields),
            ];
        }

        // Every other lead merged INTO this one — never the reverse: a lead
        // that's itself been merged away redirects out of Show::mount()
        // before activity() is ever reached, so $this->lead is always the
        // survivor whenever this runs. Read from LeadDuplicateLink (the
        // merge's own audit trail, see LeadMergeService::merge()) rather
        // than LeadDetailUpdateLog, since "merged with LEAD-123" needs the
        // other lead's identifier, which changed_fields' plain column-name
        // list has no room for.
        foreach ($this->lead->duplicateLinksAsLead()->merged()->with(['duplicateLead', 'resolvedBy'])->get() as $link) {
            $events[] = [
                'type' => 'lead_merged',
                'created_at' => $link->resolved_at ?? $link->updated_at,
                'user' => $link->resolvedBy?->name,
                'other_lead_identifier' => $link->duplicateLead->identifier,
            ];
        }

        foreach ($this->lead->duplicateLinksAsDuplicate()->merged()->with(['lead', 'resolvedBy'])->get() as $link) {
            $events[] = [
                'type' => 'lead_merged',
                'created_at' => $link->resolved_at ?? $link->updated_at,
                'user' => $link->resolvedBy?->name,
                'other_lead_identifier' => $link->lead->identifier,
            ];
        }

        // Queried directly against DealStatusLog (via whereHas on the deal's
        // lead_id) rather than looping $this->lead->deals and calling
        // ->statusLogs() per deal — a single query regardless of how many
        // Deals this lead has, avoiding an N+1 across a lead with several.
        foreach (DealStatusLog::query()->whereHas('deal', fn (Builder $q) => $q->where('lead_id', $this->lead->id))->with(['deal', 'changedBy'])->get() as $log) {
            $events[] = [
                'type' => 'deal_status_change',
                'created_at' => $log->created_at,
                'user' => $log->changedBy?->name,
                'deal_name' => $log->deal->name,
                'old_status' => $log->old_status?->label(),
                'new_status' => $log->new_status->label(),
            ];
        }

        // Same single-query, eager-loaded shape as the Deal block above —
        // task_status_change is the only Task event Phase 1 surfaces here
        // (no task_created/task_reassigned, see Show::saveTask()'s docblock
        // for why reassignment is a notification concern, not an activity-
        // feed one).
        foreach (TaskStatusLog::query()->whereHas('task', fn (Builder $q) => $q->where('lead_id', $this->lead->id))->with(['task', 'changedBy'])->get() as $log) {
            $events[] = [
                'type' => 'task_status_change',
                'created_at' => $log->created_at,
                'user' => $log->changedBy?->name,
                'task_title' => $log->task->title,
                'old_status' => $log->old_status?->label(),
                'new_status' => $log->new_status->label(),
            ];
        }

        // Feature #14 — every consent grant/withdrawal ever recorded for
        // this lead, system-originated (Website Form) or staff-originated
        // (Manual), plus any synthetic merge-audit event. Reuses the
        // already-loaded $this->consentHistory computed rather than a
        // second query.
        foreach ($this->consentHistory as $event) {
            $events[] = [
                'type' => 'consent_change',
                'created_at' => $event->created_at,
                'user' => $event->changedBy?->name,
                'consent_type' => $event->consent_type,
                'consent_status' => $event->status,
                'consent_source' => $event->source,
                'website_form_name' => $event->website_form_name,
            ];
        }

        usort($events, fn (array $a, array $b): int => $b['created_at'] <=> $a['created_at']);

        return $events;
    }

    public function updateStatus(): void
    {
        if (Gate::denies('update', $this->lead)) {
            Flux::toast(variant: 'danger', text: __("You don't have permission to update this lead."));

            return;
        }

        $validated = $this->validate([
            'priority' => ['required', Rule::enum(LeadPriority::class)],
        ]);

        // Scoped to this tenant's own configured pipeline, not the raw
        // global lead_statuses catalog — $this->status is a plain public
        // property (wire:model, no #[Locked]), so a tampered submission
        // could otherwise name a status only ever added to a *different*
        // tenant's pipeline. Same lookup/no-op-if-missing pattern as
        // Index::moveLead().
        $newStatus = $this->lead->tenant->leadStatuses->firstWhere('id', $this->status);

        if ($newStatus === null) {
            return;
        }

        $oldStatus = $this->lead->status;
        $statusChanged = $oldStatus->id !== $newStatus->id;

        // Bundled with Status in one form (status-form.blade.php /
        // status-assignment-form.blade.php), but previously only ever
        // saved — and only ever logged — as a side effect of *also*
        // changing Status: a priority-only submission hit the old
        // "$oldStatus->id === $newStatus->id, return" guard before ever
        // reaching $this->lead->update() below, silently discarding it.
        // Building $updates unconditionally and deciding what happened from
        // Eloquent's own dirty-tracking (same pattern applyDetailChanges()
        // already uses) fixes both: the save and the activity log.
        $updates = ['priority' => LeadPriority::from($validated['priority'])];

        if ($statusChanged) {
            $updates['status_id'] = $newStatus->id;

            if ($oldStatus->id === $this->lead->tenant->initialLeadStatus()?->id) {
                $updates['first_contacted_at'] = $this->lead->first_contacted_at ?? now();
            }

            if ($newStatus->is_closed) {
                $updates['closed_at'] = now();
            }
        }

        $this->lead->fill($updates);
        $changed = array_keys($this->lead->getDirty());

        if ($changed === []) {
            return;
        }

        $priorityChanged = in_array('priority', $changed, true);

        $this->lead->save();

        if ($statusChanged) {
            LeadStatusLog::create([
                'lead_id' => $this->lead->id,
                'changed_by' => Auth::id(),
                'old_status_id' => $oldStatus->id,
                'new_status_id' => $newStatus->id,
            ]);

            event(new LeadStatusChanged($this->lead, $oldStatus, $newStatus, Auth::user()));
        }

        if ($priorityChanged) {
            LeadDetailUpdateLog::create([
                'lead_id' => $this->lead->id,
                'changed_by' => Auth::id(),
                'changed_fields' => ['priority'],
            ]);
        }

        // Status and Priority are both scoring fields (LeadScoringField) —
        // a targeted recompute here, not a generic LeadUpdated listener.
        // Resolved via app(), not method injection: this is also called
        // internally by updateStatusAndAssignment() as a plain PHP method
        // call ($this->updateStatus()), which Laravel's request-level
        // action dispatch (the only thing that supports method injection)
        // never sees.
        if ($statusChanged || $priorityChanged) {
            app(LeadScoringService::class)->computeFor($this->lead, 'status_priority_changed');
            $this->lead->refresh();
            unset($this->latestScoreLog);
        }

        event(new LeadUpdated($this->lead));

        unset($this->activity);

        if ($this->panel) {
            $this->dispatch('lead-panel-updated');
        }

        Flux::toast(variant: 'success', text: __('Lead updated.'));
    }

    public function startEditingContact(): void
    {
        $this->editingContact = true;
    }

    /**
     * Reverts any unsaved draft in the Contact fields back to the lead's
     * actual stored values — without this, abandoning an edit and reopening
     * the card later would show the abandoned draft rather than what's
     * really saved, since Livewire keeps these public properties around for
     * the life of the component.
     */
    public function cancelEditingContact(): void
    {
        $this->editingContact = false;
        $this->fillContactFieldsFromLead();
    }

    public function startEditingCompany(): void
    {
        $this->editingCompany = true;
    }

    public function cancelEditingCompany(): void
    {
        $this->editingCompany = false;
        $this->fillCompanyFieldsFromLead();
    }

    public function startEditingDeal(): void
    {
        $this->editingDeal = true;
    }

    public function cancelEditingDeal(): void
    {
        $this->editingDeal = false;
        $this->fillDealFieldsFromLead();
    }

    public function updateContact(): void
    {
        if (Gate::denies('update', $this->lead)) {
            Flux::toast(variant: 'danger', text: __("You don't have permission to update this lead."));

            return;
        }

        $validated = $this->validate([
            'leadName' => ['required', 'string', 'min:2', 'max:255', 'regex:/^[\p{L}\p{M}][\p{L}\p{M}\s\'.-]*$/u'],
            // Nullable here (unlike the New Lead modal, where it's required):
            // this form edits an existing lead of unknown origin, and an
            // email-only webhook lead that never had a phone shouldn't be
            // blocked from having its company field edited elsewhere.
            'leadPhone' => ['nullable', 'regex:/^(?=(?:\D*\d){7,})[0-9+\-\s()]{7,20}$/'],
            'leadEmail' => ['nullable', 'email', 'max:255'],
        ], [
            'leadName.regex' => __('Contact person may only contain letters, spaces, apostrophes, periods and hyphens.'),
            'leadPhone.regex' => __('Enter a valid phone number (at least 7 digits).'),
        ]);

        $this->applyDetailChanges([
            'name' => $validated['leadName'],
            'phone' => $validated['leadPhone'] ?: null,
            'email' => $validated['leadEmail'] ?: null,
        ]);

        // Collapses the card back to its read-only display now that the
        // save succeeded — meaningless (never read) on the flyout, which
        // has no read-only state to collapse back to.
        $this->editingContact = false;
    }

    public function updateCompany(): void
    {
        if (Gate::denies('update', $this->lead)) {
            Flux::toast(variant: 'danger', text: __("You don't have permission to update this lead."));

            return;
        }

        $validated = $this->validate([
            'leadCompany' => ['nullable', 'string', 'max:255', 'regex:/^[\p{L}\p{N}][\p{L}\p{N}\s.,&\'()-]*$/u'],
            'leadWebsite' => ['nullable', 'url', 'max:255', 'regex:#^https?://#i'],
            'leadIndustry' => ['nullable', 'string', 'max:255', 'regex:#^[\p{L}][\p{L}\s&,/-]*$#u'],
            'leadLocation' => ['nullable', 'string', 'max:255', 'regex:/^[\p{L}][\p{L}\s,.-]*$/u'],
        ], [
            'leadCompany.regex' => __('Company name contains characters that are not allowed.'),
            'leadWebsite.regex' => __('Website URL must start with http:// or https://.'),
            'leadIndustry.regex' => __('Industry sector contains characters that are not allowed.'),
            'leadLocation.regex' => __('Location contains characters that are not allowed.'),
        ]);

        $this->applyDetailChanges([
            'company' => $validated['leadCompany'] ?: null,
            'website' => $validated['leadWebsite'] ?: null,
            'industry' => $validated['leadIndustry'] ?: null,
            'location' => $validated['leadLocation'] ?: null,
        ]);

        $this->editingCompany = false;
    }

    public function updateDeal(LeadAdminNotifier $notifier): void
    {
        if (Gate::denies('update', $this->lead)) {
            Flux::toast(variant: 'danger', text: __("You don't have permission to update this lead."));

            return;
        }

        $tenant = $this->lead->tenant;

        $validated = $this->validate([
            'leadServiceInterest' => ['nullable', 'string', 'max:255', 'regex:#^[\p{L}\p{N}][\p{L}\p{N}\s&,./()-]*$#u'],
            'leadSource' => ['nullable', 'string', 'max:255', 'regex:#^[\p{L}\p{N}][\p{L}\p{N}\s&,./()-]*$#u'],
            'leadEstimatedBudget' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'leadEstimatedBudgetCurrencyId' => ['nullable', 'string', Rule::exists('tenant_currencies', 'currency_id')->where('tenant_id', $tenant->id)],
            'leadDealValue' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'leadDealValueCurrencyId' => ['nullable', 'string', Rule::exists('tenant_currencies', 'currency_id')->where('tenant_id', $tenant->id)],
            'leadWinProbability' => ['nullable', 'integer', 'min:0', 'max:100'],
            'leadNextFollowUpAt' => ['nullable', 'date', 'after_or_equal:today'],
            'leadMeetingDate' => ['nullable', 'date_format:Y-m-d\TH:i', 'after_or_equal:today'],
        ], [
            'leadServiceInterest.regex' => __('Service interested contains characters that are not allowed.'),
            'leadSource.regex' => __('Lead source contains characters that are not allowed.'),
            'leadEstimatedBudget.regex' => __('Enter a plain amount with up to 2 decimal places.'),
            'leadDealValue.regex' => __('Enter a plain amount with up to 2 decimal places.'),
            'leadNextFollowUpAt.after_or_equal' => __('Next follow-up date cannot be in the past.'),
            'leadMeetingDate.after_or_equal' => __('Meeting date cannot be in the past.'),
        ]);

        // The single datetime-local field splits back into the separate
        // meeting_date/meeting_time columns here — meeting_time is
        // record-only and never read by meetingAlertActive(), which stays
        // purely date-based.
        [$meetingDate, $meetingTime] = $validated['leadMeetingDate']
            ? explode('T', $validated['leadMeetingDate'])
            : [null, null];

        $columns = [
            'service_interest' => $validated['leadServiceInterest'] ?: null,
            'estimated_budget' => $validated['leadEstimatedBudget'] ?: null,
            // A currency without an amount means nothing — only persisted
            // alongside its own amount, so a lead with no budget entered
            // doesn't pick up a "changed" currency just because the
            // dropdown defaults to the tenant's first currency regardless.
            'estimated_budget_currency_id' => $validated['leadEstimatedBudget'] ? ($validated['leadEstimatedBudgetCurrencyId'] ?: null) : null,
            'deal_value' => $validated['leadDealValue'] ?: null,
            'deal_value_currency_id' => $validated['leadDealValue'] ? ($validated['leadDealValueCurrencyId'] ?: null) : null,
            'win_probability' => $validated['leadWinProbability'] ?: null,
            'next_follow_up_at' => $validated['leadNextFollowUpAt'] ?: null,
            'meeting_date' => $meetingDate,
            'meeting_time' => $meetingTime,
        ];

        // Lead Source is only ever shown to (and editable by) manual-entry
        // leads' viewers — enforced here too, not just by hiding the field,
        // so it can't be changed via a stale/tampered submission on a lead
        // that was never manual to begin with.
        if ($this->isManualLead()) {
            $columns['lead_source'] = $validated['leadSource'] ?: null;
        }

        // Captured before applyDetailChanges() saves the new values below —
        // comparing against the post-save value in notifyIfDateScheduled()
        // is what tells a fresh schedule (was null) apart from a reschedule
        // (was already set to something else).
        $oldMeetingDate = $this->lead->meeting_date?->toDateString();
        $oldFollowUpAt = $this->lead->next_follow_up_at?->toDateString();

        $this->applyDetailChanges($columns);

        $this->editingDeal = false;

        $this->notifyIfDateScheduled('meeting_date', $oldMeetingDate, MeetingUpdatedNotification::class, $notifier);
        $this->notifyIfDateScheduled('next_follow_up_at', $oldFollowUpAt, FollowUpUpdatedNotification::class, $notifier);
    }

    /**
     * Notifies every other Client Admin when updateDeal() actually set
     * meeting_date/next_follow_up_at to something new — read from
     * $this->lead *after* applyDetailChanges() above, so this only fires
     * once the value is confirmed saved (a no-op submission that leaves the
     * column unchanged never sends anything, since $oldValue then still
     * equals the current value). Clearing the field to null isn't handled
     * here at all — that's a cancellation, sent by cancelMeeting()/
     * cancelFollowUp() instead.
     *
     * @param  'meeting_date'|'next_follow_up_at'  $column
     * @param  class-string<MeetingUpdatedNotification>|class-string<FollowUpUpdatedNotification>  $notificationClass
     */
    private function notifyIfDateScheduled(string $column, ?string $oldValue, string $notificationClass, LeadAdminNotifier $notifier): void
    {
        $newValue = $this->lead->{$column}?->toDateString();

        if ($newValue === null || $newValue === $oldValue) {
            return;
        }

        $notifier->notifyExcept($this->lead, Auth::user(), new $notificationClass($this->lead, Auth::user(), rescheduled: $oldValue !== null));
    }

    /**
     * Shared by updateContact()/updateCompany()/updateDeal(): fills the
     * given columns onto the lead and, only if Eloquent's own dirty
     * tracking says something actually changed, saves it, logs which
     * columns changed (translated to labels in activity(), not here — see
     * FIELD_LABELS), and notifies other viewers. Letting fill()+getDirty()
     * decide "did anything change" avoids hand-rolling type-aware
     * comparisons for decimal/int/date columns — Eloquent's casts already
     * normalize those correctly.
     *
     * @param  array<string, mixed>  $columns
     */
    private function applyDetailChanges(array $columns): void
    {
        $this->lead->fill($columns);
        $changed = array_keys($this->lead->getDirty());

        if ($changed === []) {
            return;
        }

        $this->lead->save();

        LeadDetailUpdateLog::create([
            'lead_id' => $this->lead->id,
            'changed_by' => Auth::id(),
            'changed_fields' => $changed,
        ]);

        // Only recompute when $changed actually intersects the scoring-
        // relevant subset (budget/deal/contact fields) — a Contact-card-only
        // save (e.g. just the name) never touches this, so this deliberately
        // does NOT fire on every applyDetailChanges() call. See
        // LeadScoringService::SCORING_RELEVANT_DETAIL_FIELDS.
        if (array_intersect($changed, LeadScoringService::SCORING_RELEVANT_DETAIL_FIELDS) !== []) {
            app(LeadScoringService::class)->computeFor($this->lead, 'detail_updated');
            $this->lead->refresh();
            unset($this->latestScoreLog);
        }

        event(new LeadUpdated($this->lead));

        unset($this->activity);

        if ($this->panel) {
            $this->dispatch('lead-panel-updated');
        }

        Flux::toast(variant: 'success', text: __('Lead updated.'));
    }

    public function assign(LeadAssignmentService $assignmentService): void
    {
        if (Gate::denies('assign', $this->lead)) {
            Flux::toast(variant: 'danger', text: __("You don't have permission to assign this lead."));

            return;
        }

        $newAssigneeId = $this->assigneeId ?: null;

        // Nothing actually changed — most commonly updateStatusAndAssignment()
        // running this half of the merged form on a status/priority-only
        // save. Silently no-op'ing here (same pattern applyDetailChanges()
        // already uses for "did anything change") avoids three side effects
        // an unrelated save shouldn't trigger: a duplicate LeadAssignment
        // history row, LeadAssignedNotification re-firing "you've been
        // assigned this lead" for an assignment that already happened, and
        // assignee.last_assigned_at resetting — which would unfairly
        // deprioritize this user in LeadAssignmentService::pickRoundRobinUser()'s
        // future auto-distribution, for a lead that was never reassigned.
        if ($newAssigneeId === $this->lead->assigned_to) {
            return;
        }

        if (! $newAssigneeId) {
            $assignmentService->unassign($this->lead);

            // unassign() deliberately writes no LeadAssignment row of its
            // own (see its docblock) — without this, the activity feed
            // would show every assignment ever made but nothing marking
            // the point a lead went back to unassigned, silently losing
            // that half of the history.
            LeadDetailUpdateLog::create([
                'lead_id' => $this->lead->id,
                'changed_by' => Auth::id(),
                'changed_fields' => ['assigned_to'],
                'action' => 'unassigned',
            ]);

            $this->lead->refresh();
            unset($this->activity);

            event(new LeadUpdated($this->lead));

            if ($this->panel) {
                $this->dispatch('lead-panel-updated');
            }

            Flux::toast(variant: 'success', text: __('Lead unassigned.'));

            return;
        }

        $assignee = TenantUser::query()->where('tenant_id', $this->lead->tenant_id)->findOrFail($newAssigneeId);

        $assignmentService->assign($this->lead, $assignee, Auth::user());

        $this->lead->refresh();
        unset($this->activity);

        event(new LeadUpdated($this->lead));

        if ($this->panel) {
            $this->dispatch('lead-panel-updated');
        }

        Flux::toast(variant: 'success', text: __('Lead assigned to :name.', ['name' => $assignee->name]));
    }

    /**
     * Submit handler for the full page's merged "Status & Assignment" card
     * (partials/status-assignment-form.blade.php) — runs updateStatus()/
     * assign() exactly as their own separate forms already did (same
     * validation, activity logging, and LeadUpdated/LeadStatusChanged
     * broadcasting to Reverb, so other viewers — the Kanban board, another
     * open tab — still update live), just from one button instead of two.
     * Each keeps its own permission check and toast, so this only runs the
     * half a given viewer can actually see the fields for; the flyout
     * (show-panel.blade.php) still shows Status and Assignment as two
     * separate cards/buttons, untouched.
     */
    public function updateStatusAndAssignment(LeadAssignmentService $assignmentService): void
    {
        if ($this->canUpdate()) {
            $this->updateStatus();
        }

        if ($this->canAssign()) {
            $this->assign($assignmentService);
        }
    }

    public function confirmCancelMeeting(): void
    {
        $this->showCancelMeetingModal = true;
    }

    public function cancelMeetingConfirmed(LeadAdminNotifier $notifier): void
    {
        $this->cancelMeeting($notifier);
        $this->showCancelMeetingModal = false;
    }

    public function cancelMeeting(LeadAdminNotifier $notifier): void
    {
        if (Gate::denies('update', $this->lead)) {
            Flux::toast(variant: 'danger', text: __("You don't have permission to update this lead."));

            return;
        }

        if ($this->lead->meeting_date === null) {
            return;
        }

        // Sent before recordDateAlertAction() nulls meeting_date below, so
        // the notification still carries the real date being cancelled.
        $notifier->notifyExcept($this->lead, Auth::user(), new MeetingCancelledNotification($this->lead, Auth::user()));

        $this->recordDateAlertAction('meeting_date', 'meeting_cancelled', alsoNullify: 'meeting_time');
        $this->leadMeetingDate = '';

        Flux::toast(variant: 'success', text: __('Meeting cancelled.'));
    }

    /**
     * Guarded server-side against today() < meeting_date regardless of what
     * the UI shows — the "Done" button is only rendered from the scheduled
     * day onward, but that's a display rule, not something to trust from
     * the client.
     */
    public function markMeetingDone(): void
    {
        if (Gate::denies('update', $this->lead)) {
            Flux::toast(variant: 'danger', text: __("You don't have permission to update this lead."));

            return;
        }

        if ($this->lead->meeting_date === null || today()->lt($this->lead->meeting_date)) {
            return;
        }

        $this->recordDateAlertAction('meeting_date', 'meeting_done', alsoNullify: 'meeting_time');
        $this->leadMeetingDate = '';

        Flux::toast(variant: 'success', text: __('Meeting marked as done.'));
    }

    public function confirmCancelFollowUp(): void
    {
        $this->showCancelFollowUpModal = true;
    }

    public function cancelFollowUpConfirmed(LeadAdminNotifier $notifier): void
    {
        $this->cancelFollowUp($notifier);
        $this->showCancelFollowUpModal = false;
    }

    public function cancelFollowUp(LeadAdminNotifier $notifier): void
    {
        if (Gate::denies('update', $this->lead)) {
            Flux::toast(variant: 'danger', text: __("You don't have permission to update this lead."));

            return;
        }

        if ($this->lead->next_follow_up_at === null) {
            return;
        }

        // Sent before recordDateAlertAction() nulls next_follow_up_at
        // below, so the notification still carries the real date being
        // cancelled.
        $notifier->notifyExcept($this->lead, Auth::user(), new FollowUpCancelledNotification($this->lead, Auth::user()));

        $this->recordDateAlertAction('next_follow_up_at', 'followup_cancelled');
        $this->leadNextFollowUpAt = '';

        Flux::toast(variant: 'success', text: __('Follow-up cancelled.'));
    }

    public function markFollowUpDone(): void
    {
        if (Gate::denies('update', $this->lead)) {
            Flux::toast(variant: 'danger', text: __("You don't have permission to update this lead."));

            return;
        }

        if ($this->lead->next_follow_up_at === null || today()->lt($this->lead->next_follow_up_at)) {
            return;
        }

        $this->recordDateAlertAction('next_follow_up_at', 'followup_done');
        $this->leadNextFollowUpAt = '';

        Flux::toast(variant: 'success', text: __('Follow-up marked as done.'));
    }

    /**
     * Shared by cancelMeeting()/markMeetingDone()/cancelFollowUp()/
     * markFollowUpDone(): clears the given date column, logs a
     * LeadDetailUpdateLog carrying the cleared date under $action so
     * activity() can render a friendly "X was cancelled/marked done"
     * line instead of the generic "Updated: :fields" one, and broadcasts
     * LeadUpdated so the Kanban card's shimmer stops live for every viewer,
     * not just this session. $alsoNullify clears a companion column
     * (meeting_time) in the same update — record-only, so it isn't itself
     * logged as a changed field.
     */
    private function recordDateAlertAction(string $column, string $action, ?string $alsoNullify = null): void
    {
        $oldDate = $this->lead->{$column};

        $updates = [$column => null];

        if ($alsoNullify) {
            $updates[$alsoNullify] = null;
        }

        $this->lead->update($updates);

        LeadDetailUpdateLog::create([
            'lead_id' => $this->lead->id,
            'changed_by' => Auth::id(),
            'changed_fields' => [$column],
            'action' => $action,
            'action_date' => $oldDate,
        ]);

        unset($this->activity);

        event(new LeadUpdated($this->lead));

        if ($this->panel) {
            $this->dispatch('lead-panel-updated');
        }
    }

    public function addNote(): void
    {
        if (Gate::denies('update', $this->lead)) {
            Flux::toast(variant: 'danger', text: __("You don't have permission to update this lead."));

            return;
        }

        $validated = $this->validate([
            'newNote' => ['required', 'string', 'max:2000'],
        ]);

        LeadNote::create([
            'lead_id' => $this->lead->id,
            'tenant_user_id' => Auth::id(),
            'note' => $validated['newNote'],
        ]);

        $this->reset('newNote');
        unset($this->activity);
        Flux::toast(variant: 'success', text: __('Note added.'));
    }

    /**
     * Attaches an existing tenant tag to this lead — reuses LeadPolicy::
     * update() as-is (no new permission slug, no isClientAdmin() bar), the
     * same lightweight authorization bar as addNote() above, since tagging
     * a lead you already have update access to is a day-to-day action, not
     * a catalog-management one (see App\Policies\LeadTagPolicy's docblock).
     * Never creates a new tag from here — the catalog is managed
     * exclusively at /leads/tags.
     */
    public function attachTag(): void
    {
        if (Gate::denies('update', $this->lead)) {
            Flux::toast(variant: 'danger', text: __("You don't have permission to update this lead."));

            return;
        }

        $validated = $this->validate([
            'selectedTagId' => ['required', 'string'],
        ]);

        // availableTagsToAttach() only ever offers this lead's own tenant's
        // catalog, but a tampered submission must not be able to attach a
        // foreign tenant's tag id — re-verified here, not just trusted from
        // the dropdown's rendered options.
        $tag = LeadTag::where('tenant_id', $this->lead->tenant_id)->find((string) $validated['selectedTagId']);

        if (! $tag) {
            $this->addError('selectedTagId', __('Please select a valid tag.'));

            return;
        }

        // syncWithoutDetaching is idempotent — attaching an already-attached
        // tag (e.g. a resubmitted request) merges pivot data onto the
        // existing row instead of racing the unique(lead_id, lead_tag_id)
        // constraint into a real error.
        $this->lead->tags()->syncWithoutDetaching([$tag->id => ['tagged_by' => Auth::id()]]);

        LeadDetailUpdateLog::create([
            'lead_id' => $this->lead->id,
            'changed_by' => Auth::id(),
            'changed_fields' => ['tag' => $tag->name],
            'action' => 'tag_added',
        ]);

        $this->reset('selectedTagId');
        $this->lead->load('tags');
        unset($this->activity, $this->availableTagsToAttach);
        Flux::toast(variant: 'success', text: __('Tag added.'));
    }

    /**
     * Detaches a tag from this lead — same authorization bar as
     * attachTag()/addNote() above.
     */
    public function detachTag(string $tagId): void
    {
        if (Gate::denies('update', $this->lead)) {
            Flux::toast(variant: 'danger', text: __("You don't have permission to update this lead."));

            return;
        }

        // Looked up from the lead's own already-loaded tags relation
        // (never a bare LeadTag::find()) so a tampered tag id that isn't
        // actually attached to THIS lead is a silent no-op rather than an
        // detach() call that would harmlessly affect nothing anyway — this
        // also gives us the tag's name for the activity log without an
        // extra query.
        $tag = $this->lead->tags->firstWhere('id', $tagId);

        if (! $tag) {
            return;
        }

        $this->lead->tags()->detach($tag->id);

        LeadDetailUpdateLog::create([
            'lead_id' => $this->lead->id,
            'changed_by' => Auth::id(),
            'changed_fields' => ['tag' => $tag->name],
            'action' => 'tag_removed',
        ]);

        $this->lead->load('tags');
        unset($this->activity, $this->availableTagsToAttach);
        Flux::toast(variant: 'success', text: __('Tag removed.'));
    }

    /**
     * Feature #14 — records a manual consent grant. Same lightweight
     * LeadPolicy::update() authorization bar as addNote()/attachTag() above
     * — no new permission slug, no dedicated LeadConsentPolicy, per the
     * approved architecture (recording consent on a lead you already have
     * update access to is a day-to-day data-entry action, not a
     * catalog-management one).
     */
    public function grantConsent(string $type, RecordLeadConsent $recordConsent): void
    {
        $this->mutateConsent($type, ConsentStatus::Granted, $recordConsent);
    }

    /**
     * Feature #14 — records a manual consent withdrawal. Deliberately the
     * SAME authorization bar as grantConsent() above, per the approved
     * architecture — withdrawal is not gated behind the stricter `delete`
     * permission.
     */
    public function withdrawConsent(string $type, RecordLeadConsent $recordConsent): void
    {
        $this->mutateConsent($type, ConsentStatus::Withdrawn, $recordConsent);
    }

    private function mutateConsent(string $type, ConsentStatus $status, RecordLeadConsent $recordConsent): void
    {
        if (Gate::denies('update', $this->lead)) {
            Flux::toast(variant: 'danger', text: __("You don't have permission to update this lead."));

            return;
        }

        $consentType = ConsentType::tryFrom($type);

        if ($consentType === null) {
            return;
        }

        $recordConsent->handle(
            lead: $this->lead,
            type: $consentType,
            status: $status,
            source: ConsentSource::Manual,
            changedBy: Auth::id(),
        );

        unset($this->currentConsents, $this->consentHistory, $this->activity);

        Flux::toast(
            variant: 'success',
            text: $status === ConsentStatus::Granted
                ? __(':type consent granted.', ['type' => $consentType->label()])
                : __(':type consent withdrawn.', ['type' => $consentType->label()]),
        );
    }

    /**
     * Feature #15 — sends a free-form within-window reply. Same
     * LeadPolicy::update() authorization bar as every other lightweight
     * lead-child action — no new PermissionSlug. Eligibility is
     * re-verified here (never trusted from whatever the disabled/enabled
     * button state already showed) and again, redundantly, inside
     * SendOutboundMessage itself once the queued job actually runs.
     *
     * The Message row is created SYNCHRONOUSLY, status=pending, before the
     * job is dispatched — this is what makes a job retry safe (it only
     * ever updates this one row, never creates a second) and what lets the
     * UI show immediate "Pending" feedback. A short Cache::lock (the same
     * dedupe-lock idiom LeadWebhookController::withDedupeLock() already
     * establishes elsewhere in this codebase), keyed by conversation +
     * a hash of the exact body, guards against a double-click/duplicate
     * submission racing this same action twice.
     */
    /**
     * Approved Phase 1 defaults (see the Architecture Brief's Rate Limiting
     * section) — a safety rail against an accidental loop or a staff
     * member repeatedly hammering Send, NOT a claim about Meta's own,
     * separately and independently enforced platform-side limits, which
     * this app cannot see or control. Checked manually here (via the same
     * underlying Illuminate\Cache\RateLimiter Feature #13's `throttle:`
     * middleware pair already uses) rather than through route-level
     * `throttle:` middleware, since this is a Livewire component action,
     * not a dedicated HTTP route Feature #13's mechanism was built for.
     */
    private const int OUTBOUND_MESSAGES_PER_USER_PER_MINUTE = 30;

    private const int OUTBOUND_MESSAGES_PER_TENANT_PER_MINUTE = 100;

    public function sendMessage(): void
    {
        if (Gate::denies('update', $this->lead)) {
            Flux::toast(variant: 'danger', text: __("You don't have permission to update this lead."));

            return;
        }

        if (! $this->passesOutboundRateLimits()) {
            return;
        }

        $conversation = $this->activeConversation;

        if ($conversation === null) {
            return;
        }

        $eligibility = app(OutboundEligibilityService::class)->evaluate($conversation);

        if (! $eligibility->eligible) {
            Flux::toast(variant: 'danger', text: $eligibility->reason);

            return;
        }

        $validated = $this->validate([
            'newMessageBody' => ['required', 'string', 'max:4096'],
        ]);

        $lockKey = 'outbound-message:'.$conversation->id.':'.md5($validated['newMessageBody']);
        $created = Cache::lock($lockKey, 10)->get(fn (): Message => Message::create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'lead_id' => $conversation->lead_id,
            'channel' => $conversation->channel,
            'direction' => MessageDirection::Outbound,
            'message_type' => MessageType::Text,
            'body' => $validated['newMessageBody'],
            'status' => MessageStatus::Pending,
            'status_updated_at' => now(),
            'sent_by' => Auth::id(),
        ]));

        if (! $created instanceof Message) {
            Flux::toast(variant: 'warning', text: __('This message is already being sent.'));

            return;
        }

        SendOutboundMessage::dispatch($created);

        $this->reset('newMessageBody');
        unset($this->activeConversation, $this->messagingEligibility, $this->outboundMessages);
        Flux::toast(variant: 'success', text: __('Message queued for sending.'));
    }

    /**
     * Feature #15 — manual resend after a CONFIRMED platform send failure
     * only (never automatic — see SendOutboundMessage's own retry-policy
     * docblock). Always creates a brand-new Message row; the original
     * failed row is left exactly as it is, a permanent record of that
     * attempt, never mutated or reused for the new attempt.
     */
    public function resendMessage(string $messageId): void
    {
        if (Gate::denies('update', $this->lead)) {
            Flux::toast(variant: 'danger', text: __("You don't have permission to update this lead."));

            return;
        }

        if (! $this->passesOutboundRateLimits()) {
            return;
        }

        $failedMessage = $this->lead->messages()
            ->where('id', $messageId)
            ->where('direction', MessageDirection::Outbound)
            ->where('status', MessageStatus::Failed)
            ->first();

        if ($failedMessage === null) {
            return;
        }

        $conversation = $failedMessage->conversation;
        $eligibility = app(OutboundEligibilityService::class)->evaluate($conversation);

        if (! $eligibility->eligible) {
            Flux::toast(variant: 'danger', text: $eligibility->reason);

            return;
        }

        $resend = Message::create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'lead_id' => $conversation->lead_id,
            'channel' => $conversation->channel,
            'direction' => MessageDirection::Outbound,
            'message_type' => MessageType::Text,
            'body' => $failedMessage->body,
            'status' => MessageStatus::Pending,
            'status_updated_at' => now(),
            'sent_by' => Auth::id(),
        ]);

        SendOutboundMessage::dispatch($resend);

        unset($this->activeConversation, $this->messagingEligibility, $this->outboundMessages);
        Flux::toast(variant: 'success', text: __('Message re-queued for sending.'));
    }

    /**
     * Checked before either sendMessage() or resendMessage() creates a
     * Message row — a rejected attempt here never touches the database at
     * all. Both limiters are checked independently so one abusive user
     * can't exhaust the whole tenant's ceiling for every other rep, and
     * one very active tenant can't silently starve a single user's own
     * per-minute allowance meant for someone else.
     */
    private function passesOutboundRateLimits(): bool
    {
        $userKey = 'outbound-message-per-user:'.Auth::id();

        if (RateLimiter::tooManyAttempts($userKey, self::OUTBOUND_MESSAGES_PER_USER_PER_MINUTE)) {
            Flux::toast(variant: 'danger', text: __('Too many messages sent recently — please wait a moment and try again.'));

            return false;
        }

        $tenantKey = 'outbound-message-per-tenant:'.$this->lead->tenant_id;

        if (RateLimiter::tooManyAttempts($tenantKey, self::OUTBOUND_MESSAGES_PER_TENANT_PER_MINUTE)) {
            Flux::toast(variant: 'danger', text: __('Too many messages sent recently for this account — please wait a moment and try again.'));

            return false;
        }

        RateLimiter::hit($userKey, 60);
        RateLimiter::hit($tenantKey, 60);

        return true;
    }

    public function createDeal(): void
    {
        if (Gate::denies('update', $this->lead)) {
            Flux::toast(variant: 'danger', text: __("You don't have permission to update this lead."));

            return;
        }

        $this->reset('editingDealId', 'dealName', 'dealValue', 'dealProbability', 'dealExpectedCloseDate', 'dealNotes');
        $this->dealCurrencyId = (string) $this->tenantCurrencies->first()?->id;
        // Defaults to the Lead's current assignee — a one-time copy, not a
        // live sync (see App\Models\Deal's docblock) — independently
        // editable here and never overwritten by a later Lead reassignment.
        $this->dealOwnerId = (string) $this->lead->assigned_to;
        $this->resetErrorBag();
        $this->showDealModal = true;
    }

    public function editDeal(string $dealId): void
    {
        if (Gate::denies('update', $this->lead)) {
            Flux::toast(variant: 'danger', text: __("You don't have permission to update this lead."));

            return;
        }

        // Scoped through the lead's own deals() relation, never a bare
        // Deal::find() — a tampered id belonging to another lead (even in
        // the same tenant) 404s instead of silently opening someone else's
        // deal for editing.
        $deal = $this->lead->deals()->findOrFail($dealId);

        $this->editingDealId = $deal->id;
        $this->dealName = $deal->name;
        $this->dealValue = $deal->value !== null ? (string) $deal->value : '';
        $this->dealCurrencyId = (string) $deal->currency_id;
        $this->dealProbability = $deal->probability !== null ? (string) $deal->probability : '';
        $this->dealExpectedCloseDate = $deal->expected_close_date?->toDateString() ?? '';
        $this->dealOwnerId = (string) $deal->owner_id;
        $this->dealNotes = (string) $deal->notes;
        $this->resetErrorBag();
        $this->showDealModal = true;
    }

    public function saveDeal(): void
    {
        if (Gate::denies('update', $this->lead)) {
            Flux::toast(variant: 'danger', text: __("You don't have permission to update this lead."));

            return;
        }

        $tenant = $this->lead->tenant;

        $validated = $this->validate([
            'dealName' => ['required', 'string', 'max:255'],
            'dealValue' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'dealCurrencyId' => ['nullable', 'string', Rule::exists('tenant_currencies', 'currency_id')->where('tenant_id', $tenant->id)],
            'dealProbability' => ['nullable', 'integer', 'min:0', 'max:100'],
            'dealExpectedCloseDate' => ['nullable', 'date'],
            'dealOwnerId' => ['nullable', 'string', Rule::exists('tenant_users', 'id')->where('tenant_id', $tenant->id)],
            'dealNotes' => ['nullable', 'string', 'max:2000'],
        ], [
            'dealValue.regex' => __('Enter a plain amount with up to 2 decimal places.'),
        ]);

        $attributes = [
            'name' => $validated['dealName'],
            'value' => $validated['dealValue'] ?: null,
            // A currency without an amount means nothing — same rule
            // Show::updateDeal() already applies to the Lead's own
            // deal_value/estimated_budget pairing.
            'currency_id' => $validated['dealValue'] ? ($validated['dealCurrencyId'] ?: null) : null,
            'probability' => $validated['dealProbability'] ?: null,
            'expected_close_date' => $validated['dealExpectedCloseDate'] ?: null,
            'owner_id' => $validated['dealOwnerId'] ?: null,
            'notes' => $validated['dealNotes'] ?: null,
        ];

        if ($this->editingDealId) {
            $deal = $this->lead->deals()->findOrFail($this->editingDealId);
            $deal->update($attributes);
        } else {
            Deal::create([
                'tenant_id' => $this->lead->tenant_id,
                'lead_id' => $this->lead->id,
                'status' => DealStatus::Open,
                'created_by' => Auth::id(),
                ...$attributes,
            ]);
        }

        $this->showDealModal = false;
        unset($this->deals, $this->currentDealId);
        Flux::toast(variant: 'success', text: __('Deal saved.'));
    }

    /**
     * Moves a Deal to $newStatus (open/won/lost) — the single entry point
     * for every status change this page offers (Mark Won, Mark Lost,
     * Reopen all call this with a different target), so the allowed-
     * transition check and the DealStatusLog write only ever live in one
     * place. DealStatus::canTransitionTo() is what actually blocks a direct
     * Won<->Lost flip — see that enum's own docblock.
     */
    public function changeDealStatus(string $dealId, string $newStatus): void
    {
        if (Gate::denies('update', $this->lead)) {
            Flux::toast(variant: 'danger', text: __("You don't have permission to update this lead."));

            return;
        }

        $deal = $this->lead->deals()->findOrFail($dealId);
        $target = DealStatus::from($newStatus);

        if (! $deal->status->canTransitionTo($target)) {
            Flux::toast(variant: 'danger', text: __('That status change is not allowed.'));

            return;
        }

        $oldStatus = $deal->status;

        $deal->update([
            'status' => $target,
            // Cleared back to null on reopen, written the moment the Deal
            // closes either way — never directly user-editable (see the
            // deals table migration's docblock).
            'actual_close_date' => $target === DealStatus::Open ? null : now()->toDateString(),
        ]);

        DealStatusLog::create([
            'deal_id' => $deal->id,
            'changed_by' => Auth::id(),
            'old_status' => $oldStatus,
            'new_status' => $target,
        ]);

        unset($this->deals, $this->currentDealId, $this->activity);
        Flux::toast(variant: 'success', text: __('Deal status updated.'));
    }

    public function confirmDeleteDeal(string $dealId): void
    {
        $this->confirmingDeleteDealId = $dealId;
        $this->showDeleteDealModal = true;
    }

    public function deleteDealConfirmed(): void
    {
        if ($this->confirmingDeleteDealId) {
            $this->deleteDeal($this->confirmingDeleteDealId);
        }

        $this->showDeleteDealModal = false;
        $this->confirmingDeleteDealId = null;
    }

    /**
     * Deleting a Deal is the one Deal action gated Client-Admin-only
     * (LeadPolicy::delete(), same bar as deleting the whole Lead) rather
     * than the lighter update() bar every other Deal action uses — removing
     * a revenue record outright is a consequential, hard-to-undo action.
     * Cascades its DealStatusLog rows via the FK's cascadeOnDelete.
     */
    public function deleteDeal(string $dealId): void
    {
        if (Gate::denies('delete', $this->lead)) {
            Flux::toast(variant: 'danger', text: __("You don't have permission to delete deals on this lead."));

            return;
        }

        $deal = $this->lead->deals()->findOrFail($dealId);
        $deal->delete();

        unset($this->deals, $this->currentDealId, $this->activity);
        Flux::toast(variant: 'success', text: __('Deal deleted.'));
    }

    public function createTask(): void
    {
        if (Gate::denies('update', $this->lead)) {
            Flux::toast(variant: 'danger', text: __("You don't have permission to update this lead."));

            return;
        }

        $this->reset('editingTaskId', 'taskTitle', 'taskDescription', 'taskDealId', 'taskDueDate', 'taskDueTime');
        $this->taskPriority = LeadPriority::Medium->value;
        // Defaults to the Lead's current assignee — a one-time copy, not a
        // live sync (see App\Models\Task's docblock) — independently
        // editable here and never overwritten by a later Lead reassignment.
        $this->taskAssignedTo = (string) $this->lead->assigned_to;
        $this->resetErrorBag();
        $this->showTaskModal = true;
    }

    public function editTask(string $taskId): void
    {
        if (Gate::denies('update', $this->lead)) {
            Flux::toast(variant: 'danger', text: __("You don't have permission to update this lead."));

            return;
        }

        // Scoped through the lead's own tasks() relation, never a bare
        // Task::find() — a tampered id belonging to another lead 404s
        // instead of silently opening someone else's task for editing.
        $task = $this->lead->tasks()->findOrFail($taskId);

        $this->editingTaskId = $task->id;
        $this->taskTitle = $task->title;
        $this->taskDescription = (string) $task->description;
        $this->taskDealId = (string) $task->deal_id;
        $this->taskDueDate = $task->due_date?->toDateString() ?? '';
        $this->taskDueTime = $task->due_time !== null ? substr($task->due_time, 0, 5) : '';
        $this->taskPriority = $task->priority->value;
        $this->taskAssignedTo = (string) $task->assigned_to;
        $this->resetErrorBag();
        $this->showTaskModal = true;
    }

    /**
     * Sends TaskAssignedNotification only when the assignee actually
     * changed — on creation with an assignee already set, or a genuine
     * reassignment — never when re-saving the same assignee (the approved
     * architecture is explicit: do not notify unnecessarily). Task
     * reassignment is deliberately NOT logged to the Activity timeline —
     * only task_status_change is (see activity()) — the notification is
     * the only surface for "you were (re)assigned this task".
     */
    public function saveTask(): void
    {
        if (Gate::denies('update', $this->lead)) {
            Flux::toast(variant: 'danger', text: __("You don't have permission to update this lead."));

            return;
        }

        $tenant = $this->lead->tenant;

        $validated = $this->validate([
            'taskTitle' => ['required', 'string', 'max:255'],
            'taskDescription' => ['nullable', 'string', 'max:2000'],
            // Re-verified server-side against THIS lead's own deals, never
            // trusted from the dropdown alone — a tampered submission can't
            // link a task to an unrelated tenant's or lead's Deal.
            'taskDealId' => ['nullable', 'string', Rule::exists('deals', 'id')->where('tenant_id', $tenant->id)->where('lead_id', $this->lead->id)],
            'taskDueDate' => ['nullable', 'date'],
            'taskDueTime' => ['nullable', 'date_format:H:i'],
            'taskPriority' => ['required', Rule::enum(LeadPriority::class)],
            'taskAssignedTo' => ['nullable', 'string', Rule::exists('tenant_users', 'id')->where('tenant_id', $tenant->id)],
        ]);

        $attributes = [
            'title' => $validated['taskTitle'],
            'description' => $validated['taskDescription'] ?: null,
            'deal_id' => $validated['taskDealId'] ?: null,
            'due_date' => $validated['taskDueDate'] ?: null,
            'due_time' => $validated['taskDueTime'] ?: null,
            'priority' => $validated['taskPriority'],
            'assigned_to' => $validated['taskAssignedTo'] ?: null,
        ];

        $previousAssignee = null;

        if ($this->editingTaskId) {
            $task = $this->lead->tasks()->findOrFail($this->editingTaskId);
            $previousAssignee = $task->assigned_to;
            $task->update($attributes);
        } else {
            $task = Task::create([
                'tenant_id' => $this->lead->tenant_id,
                'lead_id' => $this->lead->id,
                'status' => TaskStatus::Pending,
                'created_by' => Auth::id(),
                ...$attributes,
            ]);
        }

        if ($task->assigned_to !== null && $task->assigned_to !== $previousAssignee) {
            $assignee = TenantUser::where('tenant_id', $tenant->id)->find($task->assigned_to);
            $assignee?->notify(new TaskAssignedNotification($task, Auth::user()));
        }

        $this->showTaskModal = false;
        unset($this->tasks);
        Flux::toast(variant: 'success', text: __('Task saved.'));
    }

    /**
     * Moves a Task to $newStatus (pending/completed/cancelled) — the single
     * entry point for every status change this page offers (Complete,
     * Cancel, Reopen all call this with a different target), mirroring
     * Show::changeDealStatus() exactly. TaskStatus::canTransitionTo() is
     * what blocks a direct Completed<->Cancelled flip.
     */
    public function changeTaskStatus(string $taskId, string $newStatus): void
    {
        if (Gate::denies('update', $this->lead)) {
            Flux::toast(variant: 'danger', text: __("You don't have permission to update this lead."));

            return;
        }

        $task = $this->lead->tasks()->findOrFail($taskId);
        $target = TaskStatus::from($newStatus);

        if (! $task->status->canTransitionTo($target)) {
            Flux::toast(variant: 'danger', text: __('That status change is not allowed.'));

            return;
        }

        $oldStatus = $task->status;

        $task->update([
            'status' => $target,
            // Cleared back to null on reopen, written the moment the task
            // closes either way — never directly user-editable (see the
            // tasks table migration's docblock).
            'closed_at' => $target === TaskStatus::Pending ? null : now(),
        ]);

        TaskStatusLog::create([
            'task_id' => $task->id,
            'changed_by' => Auth::id(),
            'old_status' => $oldStatus,
            'new_status' => $target,
        ]);

        unset($this->tasks, $this->activity);
        Flux::toast(variant: 'success', text: __('Task status updated.'));
    }

    public function confirmDeleteTask(string $taskId): void
    {
        $this->confirmingDeleteTaskId = $taskId;
        $this->showDeleteTaskModal = true;
    }

    public function deleteTaskConfirmed(): void
    {
        if ($this->confirmingDeleteTaskId) {
            $this->deleteTask($this->confirmingDeleteTaskId);
        }

        $this->showDeleteTaskModal = false;
        $this->confirmingDeleteTaskId = null;
    }

    /**
     * Deliberately NOT the same Client-Admin-only bar Deal's deletion uses
     * — see canDeleteTask()'s own docblock for the approved authorization
     * model (Client Admin deletes any task within this lead; a non-admin
     * only their own assigned task). Cascades the task's own TaskStatusLog
     * history (cascadeOnDelete), so the UI gates this behind a confirmation
     * modal the same way Deal's deletion does.
     */
    public function deleteTask(string $taskId): void
    {
        $task = $this->lead->tasks()->findOrFail($taskId);

        if (! $this->canDeleteTask($task)) {
            Flux::toast(variant: 'danger', text: __("You don't have permission to delete this task."));

            return;
        }

        $task->delete();

        unset($this->tasks, $this->activity);
        Flux::toast(variant: 'success', text: __('Task deleted.'));
    }

    /**
     * Manual, on-demand recompute — gated the same as scoring-rule
     * management (canManageScoring, Client-Admin-only), since it's the same
     * class of consequential action as merge()/assign(). Runs the exact
     * same LeadScoringService::computeFor() every automatic trigger uses,
     * just invoked by a click instead of an event — the result is
     * identical to what the next automatic trigger would have produced.
     */
    public function recalculateScore(LeadScoringService $scoringService): void
    {
        if (! $this->canManageScoring()) {
            Flux::toast(variant: 'danger', text: __("You don't have permission to recalculate this lead's score."));

            return;
        }

        $scoringService->computeFor($this->lead, 'manual', Auth::user());

        $this->lead->refresh();
        unset($this->latestScoreLog);

        Flux::toast(variant: 'success', text: __('Lead score recalculated.'));
    }

    public function confirmDelete(): void
    {
        if (Gate::denies('delete', $this->lead)) {
            Flux::toast(variant: 'danger', text: __("You don't have permission to delete this lead."));

            return;
        }

        $this->reset('deletePassword');
        $this->resetErrorBag('deletePassword');
        $this->showDeleteModal = true;
    }

    public function closeDeleteModal(): void
    {
        $this->showDeleteModal = false;
        $this->reset('deletePassword');
        $this->resetErrorBag('deletePassword');
    }

    public function deleteConfirmed(): void
    {
        if (! $this->verifyDeletePassword()) {
            return;
        }

        $this->showDeleteModal = false;
        $this->delete();
    }

    public function delete(): void
    {
        if (Gate::denies('delete', $this->lead)) {
            Flux::toast(variant: 'danger', text: __("You don't have permission to delete this lead."));

            return;
        }

        $leadId = $this->lead->id;

        $this->lead->delete();

        if ($this->panel) {
            $this->dispatch('lead-panel-deleted', leadId: $leadId);

            return;
        }

        $this->redirect(route('tenant-leads.index'), navigate: true);
    }

    /**
     * Opens the merge modal for one specific possible-duplicate pairing,
     * seeding every conflicting field's choice to 'survivor' — the safe
     * default a rep must deliberately override per field to pull in the
     * loser's value instead. See LeadMergeService::conflictingFields().
     */
    public function openMergeModal(string $linkId): void
    {
        if (Gate::denies('merge', $this->lead)) {
            Flux::toast(variant: 'danger', text: __("You don't have permission to merge leads."));

            return;
        }

        $link = LeadDuplicateLink::query()
            ->where('tenant_id', $this->lead->tenant_id)
            ->where('status', LeadDuplicateLinkStatus::Pending)
            ->find($linkId);

        if ($link === null) {
            return;
        }

        $otherLead = $link->otherLead($this->lead);
        $mergeService = app(LeadMergeService::class);
        $conflicts = [
            ...$mergeService->conflictingFields($this->lead, $otherLead),
            ...$mergeService->conflictingCustomFieldValues($this->lead, $otherLead),
        ];

        $this->mergeLinkId = $link->id;
        $this->mergeFieldResolutions = array_fill_keys(array_keys($conflicts), 'survivor');
        $this->showMergeModal = true;
    }

    public function closeMergeModal(): void
    {
        $this->showMergeModal = false;
        $this->mergeLinkId = null;
        $this->mergeFieldResolutions = [];
        unset($this->mergeConflicts);
    }

    /**
     * Marks a possible-duplicate pairing as "not a duplicate" — permanently:
     * LeadDuplicateDetectionService never re-creates a link for a pair that
     * already has one, so this pair won't be re-flagged by a future
     * detection pass (see LeadDuplicateLink::pairKeys()).
     */
    public function dismissDuplicate(string $linkId): void
    {
        if (Gate::denies('merge', $this->lead)) {
            Flux::toast(variant: 'danger', text: __("You don't have permission to manage possible duplicates."));

            return;
        }

        $link = LeadDuplicateLink::query()
            ->where('tenant_id', $this->lead->tenant_id)
            ->where('status', LeadDuplicateLinkStatus::Pending)
            ->find($linkId);

        if ($link === null) {
            return;
        }

        $link->update([
            'status' => LeadDuplicateLinkStatus::Dismissed,
            'resolved_by' => Auth::id(),
            'resolved_at' => now(),
        ]);

        unset($this->possibleDuplicates);

        Flux::toast(variant: 'success', text: __('Marked as not a duplicate.'));
    }

    /**
     * Merges the possible-duplicate lead ($mergeLinkId) into this lead —
     * this lead is always the survivor, the other side of the pair is
     * always the one retired. See LeadMergeService::merge() for the full
     * transaction (reparenting notes/attachments/status/assignment/detail
     * history, applying only explicitly-chosen field overrides, and marking
     * the other lead merged_into_lead_id).
     */
    public function confirmMerge(LeadMergeService $mergeService): void
    {
        if (Gate::denies('merge', $this->lead)) {
            Flux::toast(variant: 'danger', text: __("You don't have permission to merge leads."));

            return;
        }

        $link = $this->mergeLinkId !== null
            ? LeadDuplicateLink::query()
                ->where('tenant_id', $this->lead->tenant_id)
                ->where('status', LeadDuplicateLinkStatus::Pending)
                ->find($this->mergeLinkId)
            : null;

        if ($link === null) {
            $this->closeMergeModal();

            return;
        }

        try {
            $mergeService->merge($this->lead, $link->otherLead($this->lead), Auth::user(), $this->mergeFieldResolutions, $link);
        } catch (LeadMergeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
            $this->closeMergeModal();

            return;
        }

        $this->lead->refresh();
        $this->fillDetailFieldsFromLead();
        $this->closeMergeModal();
        unset($this->activity, $this->possibleDuplicates);

        event(new LeadUpdated($this->lead));

        if ($this->panel) {
            $this->dispatch('lead-panel-updated');
        }

        Flux::toast(variant: 'success', text: __('Lead merged.'));
    }

    /**
     * @return array<int, LeadStatus>
     */
    public function statusOptions(): array
    {
        return $this->lead->tenant->leadStatuses->all();
    }

    public function priorityOptions(): array
    {
        return LeadPriority::values();
    }

    /**
     * Human label for a merge-conflict field key, reusing the same
     * translations the Activity feed's "Updated: :fields" line already uses
     * (see FIELD_LABELS) rather than a second, drifting copy.
     */
    public function mergeFieldLabel(string $field): string
    {
        // Feature #11 — a custom field's name is tenant-defined and
        // unbounded, so it can't live in the static FIELD_LABELS array the
        // way every fixed Lead column does; resolved live from the catalog
        // instead. Falls back to the raw key in the extremely unlikely case
        // the definition was deleted between opening the merge modal and
        // this render (deletion is blocked while any value exists — see
        // LeadCustomFieldDefinition::hasValues() — so this is only reachable
        // if a definition with a value was somehow removed by another
        // process mid-merge).
        if (str_starts_with($field, 'custom_field:')) {
            $definitionId = substr($field, strlen('custom_field:'));

            return LeadCustomFieldDefinition::find($definitionId)?->name ?? $field;
        }

        return __(self::FIELD_LABELS[$field] ?? $field);
    }

    /**
     * A merge-conflict field's raw value, formatted for the modal's radio
     * labels — the two currency columns store a Currency ULID, meaningless
     * to a human without resolving it to its code first. A Feature #11
     * custom field's value is already a plain display string regardless of
     * its type (see LeadCustomFieldValue's docblock), so it needs no
     * special-casing here.
     */
    public function mergeFieldValueLabel(string $field, mixed $value): string
    {
        if (in_array($field, ['estimated_budget_currency_id', 'deal_value_currency_id'], true)) {
            return Currency::find($value)?->code ?? (string) $value;
        }

        return (string) $value;
    }
}
