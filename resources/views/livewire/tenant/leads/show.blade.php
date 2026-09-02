<div class="flex flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8 overflow-y-auto min-h-0">
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
            {{-- Contact/Company/Deal are only ever editable from the Client
            User's Kanban flyout (show-panel.blade.php) — this full page is
            the Client Admin's view, so both cards render read-only here
            regardless of update permission, and there's no Contact card at
            all (the header already shows name/email/phone read-only). --}}
            @include('livewire.tenant.leads.partials.company-form', ['lead' => $lead, 'editable' => false])
            @include('livewire.tenant.leads.partials.deal-form', ['lead' => $lead, 'editable' => false, 'isManual' => $this->isManualLead])

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
