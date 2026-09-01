<div class="flex flex-col gap-6">
    @include('livewire.tenant.leads.partials.header', ['lead' => $lead, 'isClientAdmin' => $this->isClientAdmin, 'compact' => true])

    <div class="flex items-center gap-2">
        <flux:badge size="sm" :color="$lead->status->color()">{{ $lead->status->label() }}</flux:badge>
        <flux:badge size="sm" :color="$lead->priority->color()">{{ $lead->priority->label() }}</flux:badge>
    </div>

    @include('livewire.tenant.leads.partials.status-form', ['statusOptions' => $this->statusOptions(), 'priorityOptions' => $this->priorityOptions()])

    @if ($this->isClientAdmin)
        @include('livewire.tenant.leads.partials.assignment-form', ['assignableUsers' => $this->assignableUsers])
    @endif

    @include('livewire.tenant.leads.partials.detail-card', ['lead' => $lead, 'timeline' => $this->timeline, 'bodyView' => $bodyView, 'compact' => true])
    @include('livewire.tenant.leads.partials.activity-card', ['activity' => $this->activity])
    @include('livewire.tenant.leads.partials.note-form')
</div>
