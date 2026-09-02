<div class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_12rem_12rem_12rem_12rem] lg:items-start">
    <div class="lg:col-span-5">
        @include('livewire.tenant.leads.partials.header', ['lead' => $lead, 'canDelete' => $this->canDelete, 'compact' => true])
        @include('livewire.tenant.leads.partials.status-badges', ['lead' => $lead])
    </div>

    {{-- Main column: conversation/source info, then history — the two
    sections whose height varies with the lead's own data rather than a
    fixed field count, so they get the widest column. Both keep their own
    internal scroll region (capped tighter here than on the full page, via
    each partial's own $compact flag) rather than growing the whole page:
    a lead's conversation/activity history is unbounded over its lifetime,
    so at some point it has to scroll somewhere rather than push the rest
    of the panel down. --}}
    <div class="flex flex-col gap-5">
        @include('livewire.tenant.leads.partials.detail-card', ['lead' => $lead, 'timeline' => $this->timeline, 'bodyView' => $bodyView, 'compact' => true])
        @include('livewire.tenant.leads.partials.activity-card', ['activity' => $this->activity, 'compact' => true])

        @if ($this->canUpdate)
            @include('livewire.tenant.leads.partials.note-form')
        @endif
    </div>

    {{-- Contact, Company and Deal each get their own column — they're the
    3 heaviest cards, and pairing any two of them together (as an earlier
    version of this layout did) made that column the tallest by a wide
    margin. Assignment only ever renders for a Client Admin (LeadPolicy::
    assign requires it) — for the Client User this panel is actually built
    for, that 4th column is just Status alone, so pairing them costs
    nothing in the common case and only grows for the admin viewing their
    own panel. --}}
    <div class="flex flex-col gap-5">
        @if ($this->canUpdate)
            @include('livewire.tenant.leads.partials.contact-form')
        @endif
    </div>

    <div class="flex flex-col gap-5">
        @include('livewire.tenant.leads.partials.company-form', ['lead' => $lead, 'editable' => $this->canUpdate])
    </div>

    <div class="flex flex-col gap-5">
        @include('livewire.tenant.leads.partials.deal-form', ['lead' => $lead, 'editable' => $this->canUpdate])
    </div>

    <div class="flex flex-col gap-5">
        @if ($this->canUpdate)
            @include('livewire.tenant.leads.partials.status-form', ['statusOptions' => $this->statusOptions(), 'priorityOptions' => $this->priorityOptions()])
        @endif

        @if ($this->canAssign)
            @include('livewire.tenant.leads.partials.assignment-form', ['assignableUsers' => $this->assignableUsers])
        @endif
    </div>
</div>
