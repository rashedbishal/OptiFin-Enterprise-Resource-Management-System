<div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_22rem] xl:items-start">
    <div class="xl:col-span-2">
        @include('livewire.tenant.leads.partials.header', ['lead' => $lead, 'canDelete' => $this->canDelete, 'compact' => true])
        @include('livewire.tenant.leads.partials.status-badges', ['lead' => $lead])
    </div>

    <div class="flex flex-col gap-6">
        @include('livewire.tenant.leads.partials.detail-card', ['lead' => $lead, 'timeline' => $this->timeline, 'bodyView' => $bodyView, 'compact' => true])
        @include('livewire.tenant.leads.partials.activity-card', ['activity' => $this->activity])

        @if ($this->canUpdate)
            @include('livewire.tenant.leads.partials.note-form')
        @endif
    </div>

    <div class="flex flex-col gap-6">
        @if ($this->canUpdate)
            @include('livewire.tenant.leads.partials.contact-form')
        @endif
        @include('livewire.tenant.leads.partials.company-form', ['lead' => $lead, 'editable' => $this->canUpdate])
        @include('livewire.tenant.leads.partials.deal-form', ['lead' => $lead, 'editable' => $this->canUpdate])

        @if ($this->canUpdate)
            @include('livewire.tenant.leads.partials.status-form', ['statusOptions' => $this->statusOptions(), 'priorityOptions' => $this->priorityOptions()])
        @endif

        @if ($this->canAssign)
            @include('livewire.tenant.leads.partials.assignment-form', ['assignableUsers' => $this->assignableUsers])
        @endif
    </div>
</div>
