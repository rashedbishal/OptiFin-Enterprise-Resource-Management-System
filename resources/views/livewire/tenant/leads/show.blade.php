<div class="flex flex-1 flex-col gap-6">
    <x-breadcrumbs :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard')],
        ['label' => __('Leads'), 'href' => route('tenant-leads.index')],
        ['label' => $lead->name],
    ]" />

    @include('livewire.tenant.leads.partials.header', ['lead' => $lead, 'canDelete' => $this->canDelete])

    <div class="grid gap-6 md:grid-cols-3">
        <div class="flex flex-col gap-6 md:col-span-2">
            @include('livewire.tenant.leads.partials.detail-card', ['lead' => $lead, 'timeline' => $this->timeline, 'bodyView' => $bodyView])
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
            @else
                <flux:card>
                    <flux:heading size="lg" class="mb-4">{{ __('Status') }}</flux:heading>
                    @include('livewire.tenant.leads.partials.status-badges', ['lead' => $lead])
                </flux:card>
            @endif

            @if ($this->canAssign)
                @include('livewire.tenant.leads.partials.assignment-form', ['assignableUsers' => $this->assignableUsers])
            @endif
        </div>
    </div>
</div>
