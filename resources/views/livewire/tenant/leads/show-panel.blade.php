<div class="flex flex-col gap-5">
    @include('livewire.tenant.leads.partials.header', ['lead' => $lead, 'canDelete' => $this->canDelete, 'compact' => true])
    @include('livewire.tenant.leads.partials.status-badges', ['lead' => $lead])

    {{-- Masonry, not a uniform-row grid: these cards have genuinely
    different natural heights (Details/Activity are a few lines; Deal is a
    full form), and forcing them into equal-height rows just padded the
    short ones with dead white space. CSS multi-column lets each card keep
    its own height while still tiling neatly — `column-width` picks the
    column count from available width the same way auto-fit did, and
    `break-inside-avoid` stops a card from being split across columns. --}}
    <div class="gap-5 [column-width:19rem]">
        <div class="mb-5 break-inside-avoid">
            @include('livewire.tenant.leads.partials.detail-card', ['lead' => $lead, 'timeline' => $this->timeline, 'bodyView' => $bodyView, 'compact' => true])
        </div>
        <div class="mb-5 break-inside-avoid">
            @include('livewire.tenant.leads.partials.activity-card', ['activity' => $this->activity, 'compact' => true])
        </div>

        @if ($this->canUpdate)
            <div class="mb-5 break-inside-avoid">
                @include('livewire.tenant.leads.partials.contact-form')
            </div>
        @endif
        <div class="mb-5 break-inside-avoid">
            @include('livewire.tenant.leads.partials.company-form', ['lead' => $lead, 'editable' => $this->canUpdate])
        </div>
        <div class="mb-5 break-inside-avoid">
            @include('livewire.tenant.leads.partials.deal-form', ['lead' => $lead, 'editable' => $this->canUpdate, 'isManual' => $this->isManualLead])
        </div>

        @if ($this->canUpdate)
            <div class="mb-5 break-inside-avoid">
                @include('livewire.tenant.leads.partials.status-form', ['statusOptions' => $this->statusOptions(), 'priorityOptions' => $this->priorityOptions()])
            </div>
        @endif
        @if ($this->canAssign)
            <div class="mb-5 break-inside-avoid">
                @include('livewire.tenant.leads.partials.assignment-form', ['assignableUsers' => $this->assignableUsers])
            </div>
        @endif
        @if ($this->canUpdate)
            <div class="mb-5 break-inside-avoid">
                @include('livewire.tenant.leads.partials.note-form')
            </div>
        @endif
    </div>
</div>
