<?php

namespace App\Livewire\Tenant\Leads;

use App\Enums\LeadPriority;
use App\Enums\LeadStatus;
use App\Events\LeadStatusChanged;
use App\Models\Lead;
use App\Models\LeadAttachment;
use App\Models\LeadNote;
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
    public Lead $lead;

    public string $status;

    public string $priority;

    public ?string $assigneeId = null;

    public string $newNote = '';

    public string $bodyView = 'text';

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
        $this->status = $lead->status->value;
        $this->priority = $lead->priority->value;
        $this->assigneeId = $lead->assigned_to;
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
        unset($this->activity, $this->timeline);
    }

    #[Computed]
    public function isClientAdmin(): bool
    {
        return Auth::user()->isClientAdmin();
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

        foreach ($this->lead->statusLogs()->with('changedBy')->get() as $log) {
            $events[] = [
                'type' => 'status_change',
                'created_at' => $log->created_at,
                'user' => $log->changedBy?->name,
                'old_status' => $log->old_status?->value,
                'new_status' => $log->new_status->value,
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

        usort($events, fn (array $a, array $b): int => $b['created_at'] <=> $a['created_at']);

        return $events;
    }

    public function updateStatus(): void
    {
        Gate::authorize('update', $this->lead);

        $newStatus = LeadStatus::from($this->status);
        $oldStatus = $this->lead->status;

        if ($oldStatus === $newStatus) {
            return;
        }

        LeadStatusLog::create([
            'lead_id' => $this->lead->id,
            'changed_by' => Auth::id(),
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
        ]);

        $updates = ['status' => $newStatus, 'priority' => LeadPriority::from($this->priority)];

        if ($oldStatus === LeadStatus::New) {
            $updates['first_contacted_at'] = $this->lead->first_contacted_at ?? now();
        }

        if (in_array($newStatus, [LeadStatus::Won, LeadStatus::Lost], true)) {
            $updates['closed_at'] = now();
        }

        $this->lead->update($updates);

        event(new LeadStatusChanged($this->lead, $oldStatus, $newStatus, Auth::user()));

        unset($this->activity);

        if ($this->panel) {
            $this->dispatch('lead-panel-updated');
        }

        Flux::toast(variant: 'success', text: __('Lead updated.'));
    }

    public function assign(LeadAssignmentService $assignmentService): void
    {
        Gate::authorize('assign', $this->lead);

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
        Gate::authorize('update', $this->lead);

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
        Gate::authorize('delete', $this->lead);

        $leadId = $this->lead->id;

        $this->lead->delete();

        if ($this->panel) {
            $this->dispatch('lead-panel-deleted', leadId: $leadId);

            return;
        }

        $this->redirect(route('tenant-leads.index'), navigate: true);
    }

    public function statusOptions(): array
    {
        return LeadStatus::values();
    }

    public function priorityOptions(): array
    {
        return LeadPriority::values();
    }
}
