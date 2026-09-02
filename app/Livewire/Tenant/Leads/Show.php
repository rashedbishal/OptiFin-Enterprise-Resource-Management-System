<?php

namespace App\Livewire\Tenant\Leads;

use App\Enums\LeadPriority;
use App\Events\LeadStatusChanged;
use App\Events\LeadUpdated;
use App\Models\Lead;
use App\Models\LeadAttachment;
use App\Models\LeadDetailUpdateLog;
use App\Models\LeadNote;
use App\Models\LeadStatus;
use App\Models\LeadStatusLog;
use App\Models\TenantUser;
use App\Services\LeadAssignmentService;
use Carbon\CarbonInterface;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Lead Details')]
class Show extends Component
{
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
        'estimated_budget' => 'Est. Budget',
        'deal_value' => 'Deal Value',
        'win_probability' => 'Win Probability',
        'next_follow_up_at' => 'Next Follow-up',
        'meeting_date' => 'Meeting Date',
    ];

    public Lead $lead;

    public string $status;

    public string $priority;

    public ?string $assigneeId = null;

    public string $newNote = '';

    public string $bodyView = 'text';

    public string $leadName = '';

    public string $leadPhone = '';

    public string $leadEmail = '';

    public string $leadCompany = '';

    public string $leadWebsite = '';

    public string $leadIndustry = '';

    public string $leadLocation = '';

    public string $leadServiceInterest = '';

    public string $leadEstimatedBudget = '';

    public string $leadDealValue = '';

    public string $leadWinProbability = '';

    public string $leadNextFollowUpAt = '';

    public string $leadMeetingDate = '';

    /**
     * True when this component is embedded in the Kanban board's lead
     * detail flyout (App\Livewire\Tenant\Leads\Index) rather than rendered
     * as the full `/leads/{lead}` page — swaps to a single-column,
     * drawer-width view and reports mutations back to the board via
     * dispatched events instead of a page redirect.
     */
    public bool $panel = false;

    public function mount(Lead $lead): void
    {
        Gate::authorize('view', $lead);

        $this->lead = $lead;
        $this->status = $lead->status_id;
        $this->priority = $lead->priority->value;
        $this->assigneeId = $lead->assigned_to;
        $this->fillDetailFieldsFromLead();
    }

    /**
     * Populates the Contact/Company/Deal form fields from the lead's
     * current column values — shared by mount() and refreshLead() so a
     * live update from another viewer doesn't leave a stale draft sitting
     * in these fields.
     */
    private function fillDetailFieldsFromLead(): void
    {
        $this->leadName = $this->lead->name;
        $this->leadPhone = (string) $this->lead->phone;
        $this->leadEmail = (string) $this->lead->email;
        $this->leadCompany = (string) $this->lead->company;
        $this->leadWebsite = (string) $this->lead->website;
        $this->leadIndustry = (string) $this->lead->industry;
        $this->leadLocation = (string) $this->lead->location;
        $this->leadServiceInterest = (string) $this->lead->service_interest;
        $this->leadEstimatedBudget = $this->lead->estimated_budget !== null ? (string) $this->lead->estimated_budget : '';
        $this->leadDealValue = $this->lead->deal_value !== null ? (string) $this->lead->deal_value : '';
        $this->leadWinProbability = $this->lead->win_probability !== null ? (string) $this->lead->win_probability : '';
        $this->leadNextFollowUpAt = $this->lead->next_follow_up_at?->toDateString() ?? '';
        $this->leadMeetingDate = $this->lead->meeting_date?->toDateString() ?? '';
    }

    public function render(): View
    {
        return view($this->panel ? 'livewire.tenant.leads.show-panel' : 'livewire.tenant.leads.show');
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

        $newStatus = LeadStatus::findOrFail($this->status);
        $oldStatus = $this->lead->status;

        if ($oldStatus->id === $newStatus->id) {
            return;
        }

        LeadStatusLog::create([
            'lead_id' => $this->lead->id,
            'changed_by' => Auth::id(),
            'old_status_id' => $oldStatus->id,
            'new_status_id' => $newStatus->id,
        ]);

        $updates = ['status_id' => $newStatus->id, 'priority' => LeadPriority::from($this->priority)];

        if ($oldStatus->id === $this->lead->tenant->initialLeadStatus()?->id) {
            $updates['first_contacted_at'] = $this->lead->first_contacted_at ?? now();
        }

        if ($newStatus->is_closed) {
            $updates['closed_at'] = now();
        }

        $this->lead->update($updates);

        event(new LeadStatusChanged($this->lead, $oldStatus, $newStatus, Auth::user()));
        event(new LeadUpdated($this->lead));

        unset($this->activity);

        if ($this->panel) {
            $this->dispatch('lead-panel-updated');
        }

        Flux::toast(variant: 'success', text: __('Lead updated.'));
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
    }

    public function updateDeal(): void
    {
        if (Gate::denies('update', $this->lead)) {
            Flux::toast(variant: 'danger', text: __("You don't have permission to update this lead."));

            return;
        }

        $validated = $this->validate([
            'leadServiceInterest' => ['nullable', 'string', 'max:255', 'regex:#^[\p{L}\p{N}][\p{L}\p{N}\s&,./()-]*$#u'],
            'leadEstimatedBudget' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'leadDealValue' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'leadWinProbability' => ['nullable', 'integer', 'min:0', 'max:100'],
            'leadNextFollowUpAt' => ['nullable', 'date', 'after_or_equal:today'],
            'leadMeetingDate' => ['nullable', 'date'],
        ], [
            'leadServiceInterest.regex' => __('Service interested contains characters that are not allowed.'),
            'leadEstimatedBudget.regex' => __('Enter a plain amount with up to 2 decimal places.'),
            'leadDealValue.regex' => __('Enter a plain amount with up to 2 decimal places.'),
            'leadNextFollowUpAt.after_or_equal' => __('Next follow-up date cannot be in the past.'),
        ]);

        $this->applyDetailChanges([
            'service_interest' => $validated['leadServiceInterest'] ?: null,
            'estimated_budget' => $validated['leadEstimatedBudget'] ?: null,
            'deal_value' => $validated['leadDealValue'] ?: null,
            'win_probability' => $validated['leadWinProbability'] ?: null,
            'next_follow_up_at' => $validated['leadNextFollowUpAt'] ?: null,
            'meeting_date' => $validated['leadMeetingDate'] ?: null,
        ]);
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

        if (! $this->assigneeId) {
            return;
        }

        $assignee = TenantUser::query()->where('tenant_id', $this->lead->tenant_id)->findOrFail($this->assigneeId);

        $assignmentService->assign($this->lead, $assignee, Auth::user());

        $this->lead->refresh();
        unset($this->activity);

        if ($this->panel) {
            $this->dispatch('lead-panel-updated');
        }

        Flux::toast(variant: 'success', text: __('Lead assigned to :name.', ['name' => $assignee->name]));
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
