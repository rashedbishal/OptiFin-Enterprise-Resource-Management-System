<div class="flex flex-col gap-6">
    @include('livewire.tenant.leads.partials.header', ['lead' => $lead, 'canDelete' => $this->canDelete, 'compact' => true])

    @include('livewire.tenant.leads.partials.status-badges', ['lead' => $lead])

    @if ($this->canUpdate)
        @include('livewire.tenant.leads.partials.status-form', ['statusOptions' => $this->statusOptions(), 'priorityOptions' => $this->priorityOptions()])
    @endif

    @if ($this->canAssign)
        @include('livewire.tenant.leads.partials.assignment-form', ['assignableUsers' => $this->assignableUsers])
    @endif

    @include('livewire.tenant.leads.partials.detail-card', ['lead' => $lead, 'timeline' => $this->timeline, 'bodyView' => $bodyView, 'compact' => true])
    @include('livewire.tenant.leads.partials.activity-card', ['activity' => $this->activity])

    @if ($this->canUpdate)
        @include('livewire.tenant.leads.partials.note-form')
    @endif
</div>
