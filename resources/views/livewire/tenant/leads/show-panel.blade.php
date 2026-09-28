<div class="flex flex-col gap-5" wire:init="completeInitialLoad">
    @include('livewire.tenant.leads.partials.header', ['lead' => $lead, 'canDelete' => $this->canDelete, 'compact' => true])

    @include('livewire.tenant.leads.partials.duplicate-warning')

    {{-- Compact cards flow into as many ≥20rem columns as the panel's
    own width allows (1 on phones, 2–3 on desktop) instead of each
    stretching full-width. auto-fit sizes off the container, not the
    viewport. Rows follow useful content; unbounded card lists cap and
    scroll without adding an empty fixed-height track. --}}
    <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,20rem),1fr))] items-stretch gap-5">
        @include('livewire.tenant.leads.partials.score-breakdown', ['lead' => $lead, 'compact' => true])

        @include('livewire.tenant.leads.partials.deals-card', ['lead' => $lead])

        @include('livewire.tenant.leads.partials.tasks-card', ['lead' => $lead])

        @include('livewire.tenant.leads.partials.custom-fields-card', ['lead' => $lead])

        @include('livewire.tenant.leads.partials.consent-card', ['lead' => $lead])

        @include('livewire.tenant.leads.partials.notes-card')
    </div>

    {{-- Calls, channel reply, and lead details share the available desktop
    width. auto-fit collapses naturally when a lead has no calls or isn't a
    conversational channel, so no empty grid tracks are left behind. --}}
    <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,22rem),1fr))] items-stretch gap-5">
        @include('livewire.tenant.leads.partials.calls-card', ['scrollable' => true])

        @include('livewire.tenant.leads.partials.message-composer-card')

        @include('livewire.tenant.leads.partials.detail-card', ['lead' => $lead, 'timeline' => $this->timeline, 'bodyView' => $bodyView, 'compact' => true])
    </div>

    {{-- Left column is fixed-width and stacks Details/Activity on their
    own — they're read-only reference material, not part of the edit
    forms' row-pairing below. The right side is an explicit 2-column grid
    (not auto-fit/masonry): Contact+Company share a row and stretch to
    match each other's height, Deal spans both columns on its own row
    since it doesn't have a natural same-size partner, then Status+Note
    share the final row the same way Contact+Company did. --}}
    <div class="flex flex-col gap-5 lg:flex-row lg:items-start">
        <div class="flex flex-col gap-5 lg:w-88 lg:shrink-0">
            @include('livewire.tenant.leads.partials.activity-card', ['activity' => $this->activity, 'compact' => true])
        </div>

        <div class="grid flex-1 grid-cols-1 gap-5 sm:grid-cols-2">
            @if ($this->canUpdate)
                @include('livewire.tenant.leads.partials.contact-form')
            @endif
            @include('livewire.tenant.leads.partials.company-form', ['lead' => $lead, 'editable' => $this->canUpdate])

            <div class="sm:col-span-2">
                @include('livewire.tenant.leads.partials.deal-form', ['lead' => $lead, 'editable' => $this->canUpdate, 'isManual' => $this->isManualLead])
            </div>

            @if ($this->canUpdate)
                @include('livewire.tenant.leads.partials.status-form', ['statusOptions' => $this->statusOptions(), 'priorityOptions' => $this->priorityOptions()])
            @endif
            @if ($this->canUpdate)
                @include('livewire.tenant.leads.partials.note-form')
            @endif

            @if ($this->canAssign)
                <div class="sm:col-span-2">
                    @include('livewire.tenant.leads.partials.assignment-form', ['assignableUsers' => $this->assignableUsers])
                </div>
            @endif
        </div>
    </div>

    <x-confirm-password-delete-modal wire:model.self="showDeleteModal" />

    <x-confirm-modal
        wire:model.self="showCancelMeetingModal"
        :title="__('Cancel this meeting?')"
        confirm-action="cancelMeetingConfirmed"
        :confirm-label="__('Cancel Meeting')"
        variant="filled"
    />

    <x-confirm-modal
        wire:model.self="showCancelFollowUpModal"
        :title="__('Cancel this follow-up?')"
        confirm-action="cancelFollowUpConfirmed"
        :confirm-label="__('Cancel Follow-up')"
        variant="filled"
    />
</div>
