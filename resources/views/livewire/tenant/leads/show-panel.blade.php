<div class="flex flex-col gap-5">
    @include('livewire.tenant.leads.partials.header', ['lead' => $lead, 'canDelete' => $this->canDelete, 'compact' => true])
    @include('livewire.tenant.leads.partials.status-badges', ['lead' => $lead])

    {{-- A single flat auto-fit grid, not nested breakpoint-specific grids: the
    number of columns scales continuously with the flyout's actual available
    width (1, 2, 3... as it grows) instead of jumping at a few fixed
    breakpoints, so extra width above ~1280px keeps getting used instead of
    the layout plateauing. Every card is the same minmax(22rem, 1fr) track —
    detail-card/activity-card don't need double width, since their own
    conversation/timeline content already scrolls internally via `compact`. --}}
    {{-- No `items-start`: every card in a row stretches to match the
    tallest one (grid's default `align-items: stretch`), so row edges line
    up instead of each card sizing to its own content. --}}
    <div class="grid grid-cols-[repeat(auto-fit,minmax(19rem,1fr))] gap-5">
        @include('livewire.tenant.leads.partials.detail-card', ['lead' => $lead, 'timeline' => $this->timeline, 'bodyView' => $bodyView, 'compact' => true])
        @include('livewire.tenant.leads.partials.activity-card', ['activity' => $this->activity, 'compact' => true])

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
        @if ($this->canUpdate)
            @include('livewire.tenant.leads.partials.note-form')
        @endif
    </div>
</div>
