@push('breadcrumbs')
    <x-breadcrumbs :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard')],
        ['label' => __('Leads'), 'href' => route('tenant-leads.index')],
        ['label' => $lead->name],
    ]" />
@endpush

<div class="flex flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8 overflow-y-auto min-h-0">
    @include('livewire.tenant.leads.partials.lead-hero', ['lead' => $lead, 'canDelete' => $this->canDelete])

    @include('livewire.tenant.leads.partials.pipeline-stepper', ['lead' => $lead])

    <div class="flex flex-wrap items-center gap-1 border-b border-zinc-200 dark:border-zinc-700">
        <flux:button
            type="button"
            size="sm"
            variant="ghost"
            wire:click="$set('activeTab', 'overview')"
            class="rounded-none {{ $activeTab === 'overview' ? 'border-b-2 border-accent text-accent' : '' }}"
        >
            {{ __('Overview & Intel') }}
        </flux:button>
        <flux:button
            type="button"
            size="sm"
            variant="ghost"
            wire:click="$set('activeTab', 'activity')"
            class="rounded-none {{ $activeTab === 'activity' ? 'border-b-2 border-accent text-accent' : '' }}"
        >
            {{ __('Activity Timeline') }} ({{ count($this->activity) }})
        </flux:button>

        {{-- No lead-to-client conversion feature exists in this app —
        disabled placeholder only, matching the requested layout without
        adding functionality that isn't there. --}}
        <flux:button type="button" size="sm" variant="ghost" icon="arrow-right-circle" disabled class="ms-auto" title="{{ __('Coming soon') }}">
            {{ __('Convert to Client') }}
        </flux:button>
    </div>

    @if ($activeTab === 'overview')
        <div class="flex flex-col gap-6">
            <div class="grid gap-6 md:grid-cols-2">
                @include('livewire.tenant.leads.partials.contact-info-card', ['lead' => $lead])
                @include('livewire.tenant.leads.partials.pipeline-attributes-card', ['lead' => $lead, 'isManual' => $this->isManualLead()])
            </div>

            @include('livewire.tenant.leads.partials.detail-card', ['lead' => $lead, 'timeline' => $this->timeline, 'bodyView' => $bodyView])
        </div>
    @else
        {{-- Right column is a flex column stretched (CSS Grid's default
        align-items: stretch) to the same height as the Activity card's grid
        cell, then justify-between spaces its two rows (Add Note, then the
        merged Status & Assignment card) out to fill that height evenly —
        no h-full/flex-1 on the cards themselves, since that combination
        inside a grid row already broke the Contact/Company/Status/Note
        cards once before (see .ai/rules/tenant-leads.md). --}}
        <div class="grid gap-6 md:grid-cols-2">
            <div class="{{ ($this->canUpdate || $this->canAssign) ? '' : 'md:col-span-2' }}">
                @include('livewire.tenant.leads.partials.activity-card', ['activity' => $this->activity])
            </div>

            @if ($this->canUpdate || $this->canAssign)
                <div class="flex flex-col justify-between gap-6">
                    @if ($this->canUpdate)
                        @include('livewire.tenant.leads.partials.note-form', ['compact' => true])
                    @endif

                    @include('livewire.tenant.leads.partials.status-assignment-form', [
                        'statusOptions' => $this->statusOptions(),
                        'priorityOptions' => $this->priorityOptions(),
                        'assignableUsers' => $this->assignableUsers,
                    ])
                </div>
            @endif
        </div>
    @endif

    <x-confirm-password-delete-modal wire:model.self="showDeleteModal" />
</div>
