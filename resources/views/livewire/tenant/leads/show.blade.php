<div class="flex flex-1 flex-col gap-6">
    <x-breadcrumbs :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard')],
        ['label' => __('Leads'), 'href' => route('tenant-leads.index')],
        ['label' => $lead->name],
    ]" />

    @include('livewire.tenant.leads.partials.header', ['lead' => $lead, 'isClientAdmin' => $this->isClientAdmin])

    <div class="grid gap-6 md:grid-cols-3">
        <div class="flex flex-col gap-6 md:col-span-2">
            @include('livewire.tenant.leads.partials.detail-card', ['lead' => $lead, 'timeline' => $this->timeline, 'bodyView' => $bodyView])
            @include('livewire.tenant.leads.partials.activity-card', ['activity' => $this->activity])
            @include('livewire.tenant.leads.partials.note-form')
        </div>

        <div class="flex flex-col gap-6">
            @include('livewire.tenant.leads.partials.status-form', ['statusOptions' => $this->statusOptions(), 'priorityOptions' => $this->priorityOptions()])

            @if ($this->isClientAdmin)
                @include('livewire.tenant.leads.partials.assignment-form', ['assignableUsers' => $this->assignableUsers])
            @endif
        </div>
    </div>
</div>
