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

    {{-- Active tab reads as a raised "file tab" merging into the panel
    below (card-like surface + -mb-px so its bottom edge overlaps the
    divider) rather than only a colored underline — plain text on the bare
    canvas didn't give the tab bar enough presence on its own. --}}
    <div class="flex flex-wrap items-center gap-1 border-b border-zinc-200 dark:border-zinc-700">
        <flux:button
            type="button"
            size="sm"
            variant="ghost"
            wire:click="$set('activeTab', 'overview')"
            class="-mb-px rounded-t-lg rounded-b-none transition-colors duration-150 {{ $activeTab === 'overview' ? 'border border-b-0 border-zinc-200 bg-white text-accent dark:border-zinc-700 dark:bg-white/10' : 'text-zinc-500 dark:text-zinc-400' }}"
        >
            {{ __('Overview & Intel') }}
        </flux:button>
        <flux:button
            type="button"
            size="sm"
            variant="ghost"
            wire:click="$set('activeTab', 'activity')"
            class="-mb-px rounded-t-lg rounded-b-none transition-colors duration-150 {{ $activeTab === 'activity' ? 'border border-b-0 border-zinc-200 bg-white text-accent dark:border-zinc-700 dark:bg-white/10' : 'text-zinc-500 dark:text-zinc-400' }}"
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
        {{-- Right column drives the row's height (its natural, in-flow
        content); Activity must match it exactly without feeding its own
        (much taller, scrollable) content back into the grid row's auto
        height calculation — combining h-full+flex-1 directly in the grid
        row did exactly that once before (see .ai/rules/tenant-leads.md):
        the row ballooned to fit the whole activity list and the internal
        scrollbar never kicked in. Fix: at md+ (where the grid actually
        goes two-column), the Activity card is wrapped in an md:absolute
        md:inset-0 box inside a md:relative cell — an absolutely positioned
        box is out-of-flow, so it contributes zero intrinsic height to the
        row, letting the right column alone size the row; the box then
        simply fills whatever height that produces, and the card's own
        overflow-y-auto region scrolls within it. Below md the grid is
        single-column, so this reverts to plain natural-height stacking. --}}
        <div class="grid gap-6 md:grid-cols-2">
            <div class="md:relative {{ ($this->canUpdate || $this->canAssign) ? '' : 'md:col-span-2' }}">
                <div class="md:absolute md:inset-0">
                    @include('livewire.tenant.leads.partials.activity-card', ['activity' => $this->activity, 'fillHeight' => true])
                </div>
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
