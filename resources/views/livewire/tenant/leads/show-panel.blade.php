<div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_15rem_15rem_15rem] xl:items-start">
    <div class="xl:col-span-4">
        @include('livewire.tenant.leads.partials.header', ['lead' => $lead, 'canDelete' => $this->canDelete, 'compact' => true])
        @include('livewire.tenant.leads.partials.status-badges', ['lead' => $lead])
    </div>

    {{-- Main column: conversation/source info, then history — the two
    sections whose height varies with the lead's own data rather than a
    fixed field count, so they get the widest column. Activity keeps its
    own internal scroll region (max-h-96) regardless of this layout: it's
    unbounded over a lead's lifetime, so it's the one section that should
    always scroll in place rather than stretch the page. --}}
    <div class="flex flex-col gap-6">
        @include('livewire.tenant.leads.partials.detail-card', ['lead' => $lead, 'timeline' => $this->timeline, 'bodyView' => $bodyView, 'compact' => true])
        @include('livewire.tenant.leads.partials.activity-card', ['activity' => $this->activity])

        @if ($this->canUpdate)
            @include('livewire.tenant.leads.partials.note-form')
        @endif
    </div>

    {{-- The 5 profile/pipeline cards split across three narrow columns
    instead of stacking in one (or piling into just two) — grouped so each
    column ends up roughly the same total height, rather than one column
    carrying most of the content. --}}
    <div class="flex flex-col gap-6">
        @if ($this->canUpdate)
            @include('livewire.tenant.leads.partials.contact-form')
        @endif

        @if ($this->canUpdate)
            @include('livewire.tenant.leads.partials.status-form', ['statusOptions' => $this->statusOptions(), 'priorityOptions' => $this->priorityOptions()])
        @endif
    </div>

    <div class="flex flex-col gap-6">
        @include('livewire.tenant.leads.partials.company-form', ['lead' => $lead, 'editable' => $this->canUpdate])

        @if ($this->canAssign)
            @include('livewire.tenant.leads.partials.assignment-form', ['assignableUsers' => $this->assignableUsers])
        @endif
    </div>

    <div class="flex flex-col gap-6">
        @include('livewire.tenant.leads.partials.deal-form', ['lead' => $lead, 'editable' => $this->canUpdate])
    </div>
</div>
