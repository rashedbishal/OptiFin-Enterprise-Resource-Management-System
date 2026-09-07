<?php

namespace App\Livewire\Tenant\Leads;

use App\Enums\LeadPriority;
use App\Enums\LeadSourceType;
use App\Events\LeadStatusChanged;
use App\Events\LeadUpdated;
use App\Livewire\Tenant\Leads\Concerns\ConfirmsLeadDeletionPassword;
use App\Models\Lead;
use App\Models\LeadAttachment;
use App\Models\LeadDetailUpdateLog;
use App\Models\LeadNote;
use App\Models\LeadStatus;
use App\Models\LeadStatusLog;
use App\Models\TenantUser;
use App\Notifications\FollowUpCancelledNotification;
use App\Notifications\FollowUpUpdatedNotification;
use App\Notifications\MeetingCancelledNotification;
use App\Notifications\MeetingUpdatedNotification;
use App\Services\LeadAdminNotifier;
use App\Services\LeadAssignmentService;
use Carbon\CarbonInterface;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
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

    public bool $showDeleteModal = false;

    public bool $showCancelMeetingModal = false;

    public bool $showCancelFollowUpModal = false;

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
        unset($this->activity, $this->timeline);
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
}
