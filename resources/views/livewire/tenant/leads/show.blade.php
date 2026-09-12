@push('breadcrumbs')
    <x-breadcrumbs :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard')],
        ['label' => __('Leads'), 'href' => route('tenant-leads.index')],
        ['label' => $lead->name],
    ]" />
@endpush

<div class="flex flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8 overflow-y-auto min-h-0" wire:init="completeInitialLoad">
    @include('livewire.tenant.leads.partials.lead-hero', ['lead' => $lead, 'canDelete' => $this->canDelete])

    @include('livewire.tenant.leads.partials.pipeline-stepper', ['lead' => $lead])

    @include('livewire.tenant.leads.partials.duplicate-warning')

    @include('livewire.tenant.leads.partials.score-breakdown', ['lead' => $lead])

    {{-- Active tab reads as a raised "file tab" merging into the panel
    below (card-like surface + -mb-px so its bottom edge overlaps the
    divider) rather than only a colored underline — plain text on the bare
    canvas didn't give the tab bar enough presence on its own. --}}
    <div class="flex flex-wrap items-center gap-1 border-b border-zinc-200 dark:border-zinc-700">
        <flux:button
            type="button"
            size="sm"
            variant="ghost"
            wire:click="switchTab('overview')"
            class="-mb-px rounded-t-lg rounded-b-none transition-colors duration-150 {{ $activeTab === 'overview' ? 'border border-b-0 border-zinc-200 bg-white text-accent dark:border-zinc-700 dark:bg-white/10' : 'text-zinc-500 dark:text-zinc-400' }}"
        >
            {{ __('Overview & Intel') }}
        </flux:button>
        <flux:button
            type="button"
            size="sm"
            variant="ghost"
            wire:click="switchTab('activity')"
            class="-mb-px rounded-t-lg rounded-b-none transition-colors duration-150 {{ $activeTab === 'activity' ? 'border border-b-0 border-zinc-200 bg-white text-accent dark:border-zinc-700 dark:bg-white/10' : 'text-zinc-500 dark:text-zinc-400' }}"
        >
            {{-- Blank instead of "(0)" before initialLoadComplete — activity()
            returns [] while deferred, and a literal 0 would misleadingly
            claim this lead has no activity at all rather than "not loaded
            yet". --}}
            {{ __('Assignment & Activities') }}{{ $initialLoadComplete ? ' ('.count($this->activity).')' : '' }}
        </flux:button>

        {{-- No lead-to-client conversion feature exists in this app —
        disabled placeholder only, matching the requested layout without
        adding functionality that isn't there. --}}
        <flux:button type="button" size="sm" variant="ghost" icon="arrow-right-circle" disabled class="ms-auto" title="{{ __('Coming soon') }}">
            {{ __('Convert to Client') }}
        </flux:button>
    </div>

    @if ($activeTab === 'overview')
        @php
            // A manually-entered ("Custom") lead has no external source of
            // truth to defer to, unlike a webhook-sourced one — so, unlike
            // every other source type, its Contact/Company/Deal cards can
            // be switched into an edit form via their own "Edit" link,
            // reusing the exact same shared forms already used by the
            // Kanban flyout (show-panel.blade.php) rather than forking new
            // ones. Computed once here (not inline per-card below) so a
            // tampered client-side editingContact/Company/Deal value can
            // never flip a card into its form for a lead/viewer this
            // doesn't actually apply to — each include's own `editable`
            // stays false regardless unless this is true. A manual lead's
            // Overview tab uses contact-form/company-form/deal-form instead
            // of pipeline-attributes-card entirely (not just when editing),
            // since their read-only branches already group the same fields
            // the way this page's mockup wants ("Contact"/"Company"/"Deal"
            // as three separate cards) — trading away pipeline-attributes-
            // card's "Source Channel" label (always "Custom" here,
            // uninformative). Created Date stays visible via
            // detail-card.blade.php below regardless.
            $canEditManualDetails = $this->isManualLead() && $this->canUpdate;
        @endphp
        {{-- wire:init here (not just on the page's own root) fires
        markTabContentReady() every time this branch mounts — including
        every time you switch BACK to this tab, not just the very first
        page load. The wire:key is load-bearing, not decorative: without
        it, Livewire's morph reuses this SAME div for the Activity
        branch's root too (confirmed empirically — tagged the node with a
        JS marker, switched tabs, the marker survived), since @if/@else
        alone doesn't guarantee removal — the two branches' roots just
        happen to occupy the same position in the tree, so morphdom treats
        them as "the same slot" to patch in place rather than two distinct
        elements. wire:init only fires on a genuine connect-to-DOM event,
        so a reused node means it silently never refires on subsequent
        switches — this was the whole feature's actual failure mode until
        this key was added. See Show::$tabContentReady's docblock. --}}
        <div class="flex flex-col gap-6" wire:key="overview-tab-content" wire:init="markTabContentReady">
            @if ($this->isManualLead())
                {{-- Restores pipeline-attributes-card's unconditional
                "Assigned Sales Executive" line, dropped when manual leads
                switched to the three cards above — the Activity tab's own
                assignment display is NOT an equivalent fallback, since its
                entire right column (Note + Status & Assignment) only
                renders when `canUpdate || canAssign`, leaving a viewer with
                just `view` permission (a valid role/permission combination,
                see LeadPolicy) with nowhere at all to see who this lead is
                assigned to. Shown regardless of that gate, matching
                pipeline-attributes-card's own always-visible treatment for
                every other source type. --}}
                <flux:text size="sm" variant="subtle">
                    {{ __('Assigned to: :name', ['name' => $lead->assignedUser?->name ?? __('Unassigned')]) }}
                </flux:text>
            @endif

            <div class="grid gap-6 md:grid-cols-2">
                @if ($this->isManualLead())
                    @include('livewire.tenant.leads.partials.contact-form', [
                        'lead' => $lead,
                        'canEdit' => $canEditManualDetails,
                        'editable' => $canEditManualDetails && $editingContact,
                    ])
                    @include('livewire.tenant.leads.partials.company-form', [
                        'lead' => $lead,
                        'canEdit' => $canEditManualDetails,
                        'editable' => $canEditManualDetails && $editingCompany,
                    ])
                @else
                    @include('livewire.tenant.leads.partials.contact-info-card', ['lead' => $lead])
                    @include('livewire.tenant.leads.partials.pipeline-attributes-card', ['lead' => $lead, 'isManual' => false, 'canUpdate' => $this->canUpdate])
                @endif
            </div>

            @if ($this->isManualLead())
                @include('livewire.tenant.leads.partials.deal-form', [
                    'lead' => $lead,
                    'isManual' => true,
                    'canEdit' => $canEditManualDetails,
                    'editable' => $canEditManualDetails && $editingDeal,
                ])
            @endif

            @include('livewire.tenant.leads.partials.deals-card', ['lead' => $lead])

            @include('livewire.tenant.leads.partials.tasks-card', ['lead' => $lead])

            @include('livewire.tenant.leads.partials.custom-fields-card', ['lead' => $lead])

            @include('livewire.tenant.leads.partials.consent-card', ['lead' => $lead])

            @include('livewire.tenant.leads.partials.message-composer-card')

            @include('livewire.tenant.leads.partials.notes-card')

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
        {{-- Mobile wants a different stacking order than desktop (Status &
        Assignment, then Add Note, then Activity) without touching the
        desktop grid above. `order-*` alone can't do that here, since Note
        and Status & Assignment are nested inside the "right column" flex
        box below, not direct siblings of the Activity cell — order only
        reorders siblings within the same flex/grid container. Fix: the
        right column is `contents` below md (it stops being a box at all,
        so its two children become direct items of the outer grid, free to
        take their own `order-*`) and reverts to `flex flex-col` at md+,
        re-forming the exact original two-column box (both children's
        `md:order-none` drops back to plain DOM order — Note, then Status —
        inside it), so the desktop layout is byte-for-byte unchanged. --}}
        {{-- wire:init + wire:key here (matching the Overview branch's root
        above) fires markTabContentReady() every time this branch mounts —
        the wire:key is load-bearing, see the long comment on that other
        root for why. --}}
        <div class="grid gap-6 md:grid-cols-2" wire:key="activity-tab-content" wire:init="markTabContentReady">
            <div class="order-3 md:order-none md:relative {{ ($this->canUpdate || $this->canAssign) ? '' : 'md:col-span-2' }}">
                <div class="md:absolute md:inset-0">
                    @include('livewire.tenant.leads.partials.activity-card', ['activity' => $this->activity, 'fillHeight' => true])
                </div>
            </div>

            @if ($this->canUpdate || $this->canAssign)
                <div class="contents md:flex md:flex-col md:justify-between md:gap-6">
                    @if ($this->canUpdate)
                        <div class="order-2 md:order-none">
                            @include('livewire.tenant.leads.partials.note-form', ['compact' => true])
                        </div>
                    @endif

                    <div class="order-1 md:order-none">
                        @include('livewire.tenant.leads.partials.status-assignment-form', [
                            'statusOptions' => $this->statusOptions(),
                            'priorityOptions' => $this->priorityOptions(),
                            'assignableUsers' => $this->assignableUsers,
                        ])
                    </div>
                </div>
            @endif
        </div>
    @endif

    <x-confirm-password-delete-modal wire:model.self="showDeleteModal" />

    {{-- Previously only declared in show-panel.blade.php (the Kanban
    flyout) — this page had no modal at all to confirm a cancellation
    against, so confirmCancelMeeting()/confirmCancelFollowUp() (called from
    pipeline-attributes-card.blade.php's Cancel buttons below, and from
    deal-form.blade.php's for a manual lead) would flip showCancelMeeting/
    FollowUpModal true with nothing on screen to show for it, leaving no
    way to actually confirm and complete the cancellation on this page. --}}
    <x-confirm-modal
        wire:model.self="showCancelMeetingModal"
        :title="__('Cancel this meeting?')"
        confirm-action="cancelMeetingConfirmed"
        :confirm-label="__('Cancel Meeting')"
        variant="filled"
    />

    <x-confirm-modal
        wire:model.self="showCancelFollowUpModal"
        :title="__('Cancel this follow-up?')"
        confirm-action="cancelFollowUpConfirmed"
        :confirm-label="__('Cancel Follow-up')"
        variant="filled"
    />
</div>
