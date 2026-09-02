<div class="flex flex-col gap-5">
    @include('livewire.tenant.leads.partials.header', ['lead' => $lead, 'canDelete' => $this->canDelete, 'compact' => true])
    @include('livewire.tenant.leads.partials.status-badges', ['lead' => $lead])

    {{-- Left column is fixed-width and stacks Details/Activity on their
    own — they're read-only reference material, not part of the edit
    forms' row-pairing below. The right side is an explicit 2-column grid
    (not auto-fit/masonry): Contact+Company share a row and stretch to
    match each other's height, Deal spans both columns on its own row
    since it doesn't have a natural same-size partner, then Status+Note
    share the final row the same way Contact+Company did. --}}
    <div class="flex flex-col gap-5 lg:flex-row lg:items-start">
        <div class="flex flex-col gap-5 lg:w-88 lg:shrink-0">
            @include('livewire.tenant.leads.partials.detail-card', ['lead' => $lead, 'timeline' => $this->timeline, 'bodyView' => $bodyView, 'compact' => true])
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
</div>
