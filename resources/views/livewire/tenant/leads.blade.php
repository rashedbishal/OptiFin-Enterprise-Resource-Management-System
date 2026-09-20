@include('partials.lead-management-breadcrumbs', ['leadManagementTrail' => [['label' => __('Leads')]]])

<div
    class="flex flex-1 flex-col h-full min-h-0 overflow-auto"
    x-data
    x-init="
        let mobile = window.matchMedia('(max-width: 639px)');
        $wire.setPerPage(mobile.matches ? 10 : 15);
        mobile.addEventListener('change', (e) => $wire.setPerPage(e.matches ? 10 : 15));
    "
    wire:init="completeInitialLoad"
>
    {{-- Header: heading, actions, and filters stay fixed at top. --}}
    <div class="shrink-0 z-10 flex flex-col gap-2 bg-white px-4 py-3 lg:gap-3.5 lg:px-8 lg:py-4 dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <flux:heading size="lg" class="hidden lg:block lg:text-2xl font-semibold">{{ __('Leads') }}</flux:heading>
            <div class="flex flex-wrap items-center gap-1.5 sm:gap-2 w-full sm:w-auto">
                <div class="flex items-center gap-1.5 sm:gap-2 flex-1 sm:flex-initial min-w-0">
                    @if ($this->canCreateLead)
                        <flux:button variant="primary" icon="plus" size="sm" wire:click="createLead" class="flex-1 sm:flex-none">{{ __('New Lead') }}</flux:button>
                    @endif
                    <flux:button icon="arrow-down-tray" size="sm" wire:click="export" class="flex-1 sm:flex-none">{{ __('Export CSV') }}</flux:button>
                </div>
                @if ($this->canCreateLead)
                    <flux:button icon="arrow-up-tray" size="sm" :href="route('tenant-lead-imports.index')" wire:navigate class="w-full sm:w-auto flex-initial">{{ __('Import Leads') }}</flux:button>
                @endif
            </div>
        </div>

        @php
            $statusOptionsFormatted = array_merge(
                [['value' => '', 'label' => __('All statuses')]],
                collect($this->statusOptions())->map(fn($opt) => ['value' => (string) $opt->id, 'label' => $opt->label()])->toArray()
            );
            $priorityOptionsFormatted = array_merge(
                [['value' => '', 'label' => __('All priorities')]],
                collect($this->priorityOptions())->map(fn($opt) => ['value' => $opt, 'label' => ucfirst($opt)])->toArray()
            );
            $sourceTypeOptionsFormatted = array_merge(
                [['value' => '', 'label' => __('All sources')]],
                collect($this->sourceTypeOptions())->map(fn($opt) => ['value' => $opt, 'label' => \App\Enums\LeadSourceType::from($opt)->label()])->toArray()
            );
            $tagOptionsFormatted = array_merge(
                [['value' => '', 'label' => __('All tags')]],
                collect($this->tagOptions())->map(fn($opt) => ['value' => (string) $opt->id, 'label' => $opt->name])->toArray()
            );
            if ($this->isClientAdmin) {
                $assignedToOptionsFormatted = array_merge(
                    [
                        ['value' => '', 'label' => __('All users')],
                        ['value' => 'unassigned', 'label' => __('Unassigned')],
                    ],
                    collect($this->assignableUsers)->map(fn($u) => ['value' => (string) $u->id, 'label' => $u->name])->toArray()
                );
            }

            // Distinguishes "no leads at all" from "your search/filters
            // matched nothing" for the @empty blocks below — the former
            // keeps the existing "No leads yet." copy, the latter gets its
            // own message rather than implying the tenant has no data.
            $hasActiveFilters = $status !== ''
                || $priority !== ''
                || $sourceType !== ''
                || ($this->isClientAdmin && $assignedTo !== '')
                || $tag !== ''
                || $fromDate !== ''
                || $toDate !== ''
                || $search !== '';

            // Every action that re-queries $this->leads: the eight
            // wire:model.live-bound filter properties (see updating()'s
            // reset-page array) plus every pagination trait method the
            // published pagination view can call (gotoPage/previousPage/
            // nextPage/setPage), the mobile/desktop setPerPage() called
            // from this page's x-init, AND completeInitialLoad() (fired
            // once via wire:init below, right after mount — see
            // $initialLoadComplete's docblock on Index for why the very
            // first paint needs the skeleton too, not just later filter/
            // search/pagination changes). wire:loading pairs below watch
            // exactly this list so the skeleton only appears while the
            // LIST itself is being fetched — not for unrelated requests on
            // this same component (opening the New Lead modal, exporting,
            // deleting a row, toggling an alert switch, ...), which would
            // otherwise also trip a bare wire:loading with no wire:target.
            $listLoadingTargets = 'status,priority,sourceType,assignedTo,tag,fromDate,toDate,search,gotoPage,previousPage,nextPage,setPage,setPerPage,completeInitialLoad';

            // wire:loading's class toggle only takes effect once Livewire's
            // JS actually dispatches a request — there's a real gap between
            // the browser painting this raw HTML and the wire:init below
            // firing. Left to wire:loading alone, the skeleton (hidden by
            // default, revealed on loading) and the real content (visible
            // by default, hidden on loading) would BOTH show their
            // "not loading" state during that gap — meaning the real
            // side's empty-state ("No leads yet") would flash before the
            // skeleton ever appears, since $this->leads is genuinely empty
            // pre-initialLoadComplete. $showSkeletonByDefault flips which
            // side's baked-in static class starts hidden, decided from
            // server-known state at render time — no JS timing gap
            // possible. Once initialLoadComplete is true (permanently, for
            // the rest of this component's lifetime), this collapses back
            // to the original defaults and wire:loading alone handles every
            // later search/filter/pagination transition correctly.
            $showSkeletonByDefault = ! $initialLoadComplete;
        @endphp

        <div class="grid grid-cols-2 gap-2 md:grid-cols-3 lg:grid-cols-8 lg:gap-3">
            <x-dropdown-select wire:model.live="status" :placeholder="__('All statuses')" :options="$statusOptionsFormatted" icon="squares-2x2" />
            <x-dropdown-select wire:model.live="priority" :placeholder="__('All priorities')" :options="$priorityOptionsFormatted" icon="funnel" />
            <x-dropdown-select wire:model.live="sourceType" :placeholder="__('All sources')" :options="$sourceTypeOptionsFormatted" icon="tag" />
            {{-- icon="hashtag", not "tag" — the Source filter above already
            uses the Heroicon literally named "tag" for an unrelated concept
            (which channel captured the lead), and reusing it here for the
            actual Tags feature would visually conflate the two. --}}
            <x-dropdown-select wire:model.live="tag" :placeholder="__('All tags')" :options="$tagOptionsFormatted" icon="hashtag" />

            @if ($this->isClientAdmin)
                <x-dropdown-select wire:model.live="assignedTo" :placeholder="__('All users')" :options="$assignedToOptionsFormatted" icon="user" />
            @endif

            {{-- !h-auto !py-1.5 !text-xs sm:!text-sm overrides x-date-picker's
            own default (h-10 px-3 py-2 text-sm, sized to match flux:input for
            its OTHER use in the deal-form grid further down this page) so it
            shrinks to x-dropdown-select's natural button height instead —
            important modifiers because Blade's $attributes->merge() just
            concatenates classes, it doesn't resolve the h-10/py-2 conflict
            on its own. --}}
            <x-date-picker wire:model.live="fromDate" :label="__('From Date')" :placeholder="__('From Date')" :max="$toDate ?: now()->toDateString()" class="!h-auto !py-1.5 !text-xs sm:!text-sm" />
            <x-date-picker wire:model.live="toDate" :label="__('To Date')" :placeholder="__('To Date')" :min="$fromDate" :max="now()->toDateString()" class="!h-auto !py-1.5 !text-xs sm:!text-sm" />

            {{-- col-span-2/3 keeps it on its own full-width row below the
            dropdowns/dates on mobile and tablet (2- and 3-column grids) —
            no span at all from lg up, where the grid grows to 8 columns
            (one more than the 7 dropdown/date fields for a Client Admin:
            status, priority, source, tag, assigned-to, from/to date)
            specifically so the search box sits as a normal 8th cell in
            that same row instead of on its own line. A non-admin (no
            assigned-to filter) leaves one cell empty at this breakpoint
            instead — same asymmetric-fill behavior this grid already had
            before the Tags filter was added. size="sm" matches Flux's own
            input height (h-10 by default) to x-dropdown-select/x-date-
            picker's button height (py-1.5, no fixed h-*). --}}
            <flux:input
                wire:model.live.debounce.400ms="search"
                icon="magnifying-glass"
                clearable
                size="sm"
                :placeholder="__('Search by Lead ID, name, phone, email, company, location, win %, or deal value')"
                class="col-span-2 md:col-span-3 lg:col-span-1"
            />
        </div>

        @if ($this->isClientAdmin)
            <div class="flex flex-wrap items-center gap-x-8 gap-y-3">
                <div class="flex flex-wrap items-center gap-2">
                    <flux:switch :checked="auth()->user()->tenant->meeting_alert_enabled" wire:click="toggleMeetingAlert" />
                    <flux:text>{{ __('Meeting date alerts') }}</flux:text>
                    @if (auth()->user()->tenant->meeting_alert_enabled)
                        <flux:link as="button" type="button" variant="subtle" wire:click="openMeetingAlertDaysEditor">
                            {{ trans_choice('(:count day before)|(:count days before)', auth()->user()->tenant->meeting_alert_days_before, ['count' => auth()->user()->tenant->meeting_alert_days_before]) }}
                        </flux:link>
                    @endif
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <flux:switch :checked="auth()->user()->tenant->followup_alert_enabled" wire:click="toggleFollowUpAlert" />
                    <flux:text>{{ __('Next follow-up alerts') }}</flux:text>
                    @if (auth()->user()->tenant->followup_alert_enabled)
                        <flux:link as="button" type="button" variant="subtle" wire:click="openFollowUpAlertDaysEditor">
                            {{ trans_choice('(:count day before)|(:count days before)', auth()->user()->tenant->followup_alert_days_before, ['count' => auth()->user()->tenant->followup_alert_days_before]) }}
                        </flux:link>
                    @endif
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <flux:switch :checked="auth()->user()->tenant->task_alert_enabled" wire:click="toggleTaskAlert" />
                    <flux:text>{{ __('Task due-date alerts') }}</flux:text>
                    @if (auth()->user()->tenant->task_alert_enabled)
                        <flux:link as="button" type="button" variant="subtle" wire:click="openTaskAlertDaysEditor">
                            {{ trans_choice('(:count day before)|(:count days before)', auth()->user()->tenant->task_alert_days_before, ['count' => auth()->user()->tenant->task_alert_days_before]) }}
                        </flux:link>
                    @endif
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <flux:switch :checked="auth()->user()->tenant->lead_nurturing_notify_enabled" wire:click="toggleNurturingNotify" />
                    <flux:text>{{ __('Inactivity notification') }}</flux:text>
                    <flux:switch :checked="auth()->user()->tenant->lead_nurturing_task_enabled" wire:click="toggleNurturingTask" />
                    <flux:text>{{ __('Inactivity follow-up task') }}</flux:text>
                    @if (auth()->user()->tenant->lead_nurturing_notify_enabled || auth()->user()->tenant->lead_nurturing_task_enabled)
                        <flux:link as="button" type="button" variant="subtle" wire:click="openNurturingDaysEditor">
                            {{ trans_choice('(inactive after :count day)|(inactive after :count days)', auth()->user()->tenant->lead_nurturing_inactive_days, ['count' => auth()->user()->tenant->lead_nurturing_inactive_days]) }}
                        </flux:link>
                    @endif
                </div>
            </div>
        @endif
    </div>

    @if ($this->isClientAdmin)
        <flux:modal wire:model.self="showMeetingAlertModal" class="md:w-96">
            <form wire:submit="saveMeetingAlertDays" class="flex flex-col gap-4">
                <div>
                    <flux:heading size="lg">{{ __('Meeting Date Alerts') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('How many days before the meeting date should the Kanban card start alerting the assigned user? 0 means alert only on the exact day.') }}</flux:text>
                </div>
                <flux:input wire:model="meetingAlertDaysInput" type="number" min="0" :label="__('Days before')" />
                <div class="flex justify-end gap-2">
                    <flux:button type="button" wire:click="$set('showMeetingAlertModal', false)">{{ __('Cancel') }}</flux:button>
                    <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal wire:model.self="showFollowUpAlertModal" class="md:w-96">
            <form wire:submit="saveFollowUpAlertDays" class="flex flex-col gap-4">
                <div>
                    <flux:heading size="lg">{{ __('Next Follow-up Alerts') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('How many days before the follow-up date should the Kanban card start alerting the assigned user? 0 means alert only on the exact day.') }}</flux:text>
                </div>
                <flux:input wire:model="followUpAlertDaysInput" type="number" min="0" :label="__('Days before')" />
                <div class="flex justify-end gap-2">
                    <flux:button type="button" wire:click="$set('showFollowUpAlertModal', false)">{{ __('Cancel') }}</flux:button>
                    <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal wire:model.self="showTaskAlertModal" class="md:w-96">
            <form wire:submit="saveTaskAlertDays" class="flex flex-col gap-4">
                <div>
                    <flux:heading size="lg">{{ __('Task Due-Date Alerts') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('How many days before a task\'s due date should it start alerting the assigned user? 0 means alert only on the exact day.') }}</flux:text>
                </div>
                <flux:input wire:model="taskAlertDaysInput" type="number" min="0" :label="__('Days before')" />
                <div class="flex justify-end gap-2">
                    <flux:button type="button" wire:click="$set('showTaskAlertModal', false)">{{ __('Cancel') }}</flux:button>
                    <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal wire:model.self="showNurturingDaysModal" class="md:w-96">
            <form wire:submit="saveNurturingDays" class="flex flex-col gap-4">
                <div>
                    <flux:heading size="lg">{{ __('Lead Inactivity Nurturing') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('After how many days with no status change should a lead be considered inactive? Shared by both the notification and follow-up-task actions above.') }}</flux:text>
                </div>
                <flux:input wire:model="nurturingDaysInput" type="number" min="0" max="365" :label="__('Days inactive')" />
                <div class="flex justify-end gap-2">
                    <flux:button type="button" wire:click="$set('showNurturingDaysModal', false)">{{ __('Cancel') }}</flux:button>
                    <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    {{-- Table content area: page-level content scrolls here, while the
    desktop table below owns its own vertical scrollbar. --}}
    <div>
        <style>
            @keyframes leadRowInsert {
                0% {
                    opacity: 0;
                    transform: translateY(-16px) scale(0.98);
                    background-color: rgba(16, 185, 129, 0.25);
                }
                40% {
                    opacity: 1;
                    transform: translateY(0) scale(1);
                    background-color: rgba(16, 185, 129, 0.18);
                }
                100% {
                    background-color: rgba(16, 185, 129, 0.08);
                }
            }

            @keyframes leadRowLift {
                0% {
                    opacity: 0.4;
                    transform: translateY(24px);
                    background-color: rgba(245, 158, 11, 0.25);
                }
                50% {
                    opacity: 1;
                    transform: translateY(0);
                    background-color: rgba(245, 158, 11, 0.16);
                }
                100% {
                    background-color: rgba(245, 158, 11, 0.08);
                }
            }

            .animate-lead-insert {
                animation: leadRowInsert 1.2s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            }

            .animate-lead-lift {
                animation: leadRowLift 1.2s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            }
        </style>
        <div class="p-4 sm:p-6 lg:p-8 flex flex-col gap-6 min-h-full">
            {{-- Mobile Card Stack (sm:hidden) --}}
            <div class="flex flex-col gap-3.5 sm:hidden">
                @foreach ($this->facebookLeadAdsSources as $source)
                    @if ($source->leads_count > 0)
                        {{-- Whole card is the click target once there's something to view —
                        no separate View All button, same treatment as the lead cards below.
                        A <div> below (not clickable) when there are no leads yet: there's
                        nothing for "view all" to open, and Delete lives there instead. --}}
                        {{-- Not being refetched by any of $listLoadingTargets
                        (a separate, unpaginated computed property) — stays
                        real content, but dimmed in step with the skeleton
                        cards below so the whole stack reads as "loading"
                        instead of live content sitting above placeholders. --}}
                        <a
                            href="{{ route('tenant-leads.source', $source) }}"
                            wire:navigate
                            wire:key="fb-lead-ads-source-card-{{ $source->id }}"
                            wire:loading.class="opacity-40 pointer-events-none"
                            wire:target="{{ $listLoadingTargets }}"
                            class="flex flex-col gap-3 rounded-xl border border-blue-200/80 bg-blue-50/50 p-4 shadow-sm active:opacity-80 dark:border-blue-900/60 dark:bg-blue-950/30"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <div class="flex flex-col">
                                    <span class="font-medium text-zinc-900 dark:text-white">{{ $source->name }}</span>
                                    <flux:text size="sm" variant="subtle">
                                        {{ __('Last submission :time', ['time' => \Illuminate\Support\Carbon::parse($source->leads_max_created_at)->diffForHumans()]) }}
                                    </flux:text>
                                </div>
                                <flux:badge size="sm" icon="{{ $source->type->icon() }}">{{ $source->type->label() }}</flux:badge>
                            </div>
                            <div class="flex items-center justify-between gap-2 pt-2.5 border-t border-blue-200/60 dark:border-blue-900/50">
                                <flux:badge size="sm" color="zinc">{{ trans_choice(':count lead|:count leads', $source->leads_count, ['count' => $source->leads_count]) }}</flux:badge>
                            </div>
                        </a>
                    @else
                        <div
                            wire:key="fb-lead-ads-source-card-{{ $source->id }}"
                            wire:loading.class="opacity-40 pointer-events-none"
                            wire:target="{{ $listLoadingTargets }}"
                            class="flex flex-col gap-3 rounded-xl border border-blue-200/80 bg-blue-50/50 p-4 shadow-sm dark:border-blue-900/60 dark:bg-blue-950/30"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <div class="flex flex-col">
                                    <span class="font-medium text-zinc-900 dark:text-white">{{ $source->name }}</span>
                                    <flux:text size="sm" variant="subtle">{{ __('No submissions yet') }}</flux:text>
                                </div>
                                <flux:badge size="sm" icon="{{ $source->type->icon() }}">{{ $source->type->label() }}</flux:badge>
                            </div>
                            <div class="flex items-center justify-between gap-2 pt-2.5 border-t border-blue-200/60 dark:border-blue-900/50">
                                <flux:badge size="sm" color="zinc">{{ trans_choice(':count lead|:count leads', $source->leads_count, ['count' => $source->leads_count]) }}</flux:badge>
                                @if ($this->canDeleteLeads)
                                    <flux:button size="sm" variant="danger" wire:click="confirmDeleteSource('{{ $source->id }}')">
                                        {{ __('Delete') }}
                                    </flux:button>
                                @endif
                            </div>
                        </div>
                    @endif
                @endforeach

                {{-- display:contents so this wrapper doesn't interfere with
                the sm:hidden stack's own flex/gap layout — it's here purely
                to give a single element for hiding to toggle instead of
                repeating it on every card.

                Deliberately wire:loading.CLASS, not the bare
                wire:loading.remove directive: Livewire's non-class display
                toggling (see toggleBooleanStateDirective in its JS source)
                only recognizes a fixed list of display values (inline,
                block, table, flex, grid, inline-flex, list-item) — anything
                else, including "contents", falls back to forcing
                `display: inline-block` via inline style. Worse, that
                fallback isn't even scoped to the "loading" state: due to
                how it caches/recomputes the display value across the
                start/end of a request, a plain wire:loading.remove element
                gets permanently pinned to inline-block once a SINGLE
                loading cycle has completed, not just while loading. Using
                `.class="!hidden"` instead only ever touches classList
                (never el.style.display), so it can't hit that fallback at
                all — `!hidden` (Tailwind's `display:none !important`) wins
                over `contents` regardless of class order, and removing it
                cleanly reveals `contents` again. Same reasoning applies to
                every other wire:loading toggle in this file that targets a
                table-structural element (tr/tbody) or "contents" div.

                class is conditional on $showSkeletonByDefault (see that
                variable's docblock above) so this starts hidden on the very
                first paint too — not just once Livewire's JS confirms a
                request is in flight. --}}
                <div wire:loading.class="!hidden" wire:target="{{ $listLoadingTargets }}" class="{{ $showSkeletonByDefault ? '!hidden' : '' }} contents">
                @forelse ($this->leads as $lead)
                    @php
                        $isRecentlyCreated = $this->recentlyCreatedLeadId === $lead->id
                            || ($lead->created_at && $lead->created_at->diffInSeconds(now()) < 30);
                        // Unlike $isRecentlyCreated above, this isn't time-windowed — it
                        // stays true (surviving a full page reload) until someone actually
                        // opens the lead, at which point Show::mount()/Index::viewLead()
                        // call Lead::markUpdateSeen(). See Lead::hasUnseenUpdate() for why.
                        $isRecentlyUpdated = ! $isRecentlyCreated && $lead->hasUnseenUpdate();
                        $cardTenant = auth()->user()->tenant;
                        $cardMeetingAlertActive = $lead->meetingAlertActive($cardTenant->meeting_alert_enabled, $cardTenant->meeting_alert_days_before);
                        $cardFollowUpAlertActive = $lead->followUpAlertActive($cardTenant->followup_alert_enabled, $cardTenant->followup_alert_days_before);
                        $cardHasActiveAlert = $cardMeetingAlertActive || $cardFollowUpAlertActive;
                    @endphp
                    {{-- The whole card is the click target (no separate View button
                    any more) — a real <a> rather than a stretched absolute overlay,
                    since there's no other interactive element left inside it to
                    layer above (the shimmering ID's flux:tooltip trigger is a plain
                    span, not a link/button, so nesting it inside <a> is valid HTML
                    and its hover/focus tooltip keeps working normally). --}}
                    <a
                        href="{{ route('tenant-leads.show', $lead) }}"
                        wire:navigate
                        wire:key="lead-card-{{ $lead->id }}"
                        @class([
                            'flex flex-col gap-2 rounded-lg p-3 shadow-sm transition-all duration-700 ease-out active:opacity-80',
                            'bg-zinc-50 border border-zinc-200/80 dark:bg-zinc-900/90 dark:border-zinc-700/80' => ! $isRecentlyCreated && ! $isRecentlyUpdated,
                            'animate-lead-insert bg-emerald-50/90 dark:bg-emerald-950/40 border-l-4 border-l-emerald-500 border-y border-r border-emerald-200 dark:border-emerald-800/80 shadow-emerald-500/10' => $isRecentlyCreated,
                            'animate-lead-lift bg-amber-50/90 dark:bg-amber-950/40 border-l-4 border-l-amber-500 border-y border-r border-amber-200 dark:border-amber-800/80 shadow-amber-500/10' => $isRecentlyUpdated,
                        ])
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex items-center gap-2">
                                @if ($lead->avatarUrl())
                                    <img src="{{ $lead->avatarUrl() }}" alt="" loading="lazy" class="size-7 shrink-0 rounded-full object-cover">
                                @endif
                                <div class="flex flex-col leading-tight">
                                    @if ($cardHasActiveAlert)
                                        {{-- Same per-character shimmer as the desktop table's Lead
                                        ID cell (see .lead-id-shimmer-text in app.css) — was missing
                                        here entirely, since this card stack never computed the
                                        alert flags the table's cell needs in the first place. --}}
                                        <flux:tooltip position="right">
                                            <span class="lead-id-shimmer-text font-mono text-[11px] font-semibold">{{ $lead->identifier }}</span>

                                            <flux:tooltip.content>
                                                <div class="flex flex-col gap-1">
                                                    <div>{{ __('Assigned to :name', ['name' => $lead->assignedUser?->name ?? __('Unassigned')]) }}</div>
                                                    @if ($cardMeetingAlertActive)
                                                        <div>
                                                            {{ __('Meeting scheduled on :date', ['date' => $lead->meeting_date->format('l, M j, Y')]) }}
                                                            {{ $lead->meetingTimeFormatted() ? __('at :time', ['time' => $lead->meetingTimeFormatted()]) : '' }}
                                                        </div>
                                                    @endif
                                                    @if ($cardFollowUpAlertActive)
                                                        <div>{{ __('Follow-up scheduled on :date', ['date' => $lead->next_follow_up_at->format('l, M j, Y')]) }}</div>
                                                    @endif
                                                </div>
                                            </flux:tooltip.content>
                                        </flux:tooltip>
                                    @else
                                        <span class="font-mono text-[11px] font-medium text-zinc-700 dark:text-zinc-300">{{ $lead->identifier }}</span>
                                    @endif
                                    <span @class(['text-sm font-semibold text-zinc-900 dark:text-white', 'font-bold' => $isRecentlyUpdated])>
                                        {{ $lead->name }}
                                    </span>
                                    <span class="text-[11px] text-zinc-500 dark:text-zinc-400">
                                        {{ $lead->assignedUser?->name ? __('Assigned to :name', ['name' => $lead->assignedUser->name]) : __('Unassigned') }}
                                    </span>
                                </div>
                            </div>
                            <flux:badge size="sm" icon="{{ $lead->source->type->icon() }}">{{ $lead->source->type->label() }}</flux:badge>
                        </div>

                        <div class="flex items-center justify-between gap-2 text-xs">
                            <div class="flex items-center gap-2">
                                <span class="text-zinc-500 dark:text-zinc-400">{{ __('Stage:') }}</span>
                                <flux:badge size="sm" :color="$lead->status->color()">{{ $lead->status->label() }}</flux:badge>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <span class="text-zinc-500 dark:text-zinc-400">{{ __('Priority:') }}</span>
                                <span @class(['font-medium capitalize text-zinc-800 dark:text-zinc-200', 'font-bold' => $isRecentlyUpdated])>
                                    {{ ucfirst($lead->priority->value) }}
                                </span>
                            </div>
                        </div>
                    </a>
                @empty
                    @if ($hasActiveFilters)
                        <div class="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-zinc-200 py-16 text-center dark:border-zinc-700">
                            <flux:icon.magnifying-glass class="size-8 text-zinc-300 dark:text-zinc-600" />
                            <flux:text variant="strong">{{ __('No leads match your search or filters.') }}</flux:text>
                            <flux:text size="sm" variant="subtle">{{ __('Try adjusting or clearing them.') }}</flux:text>
                        </div>
                    @elseif ($this->facebookLeadAdsSources->isEmpty())
                        <div class="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-zinc-200 py-16 text-center dark:border-zinc-700">
                            <flux:icon.inbox class="size-8 text-zinc-300 dark:text-zinc-600" />
                            <flux:text variant="strong">{{ __('No leads yet') }}</flux:text>
                            <flux:text size="sm" variant="subtle">{{ __('New leads from your connected channels will show up here.') }}</flux:text>
                        </div>
                    @endif
                @endforelse
                </div>

                {{-- Skeleton: same card shape as the real one above, shown
                only while the list itself is being refetched (see
                $listLoadingTargets) — not on unrelated requests like opening
                a modal. Card count matches $perPage (10 on mobile, set via
                the page's x-init) rather than a fixed number, so the stack's
                height doesn't visibly shrink to the skeleton then snap back
                once real cards replace it.

                Starts with `!hidden` baked into the static class list (not
                toggled on) and `wire:loading.remove.class="!hidden"` REMOVES
                it while loading, revealing the "flex" utility that's also
                always present — see the long comment above the real card
                stack's wrapper for why this has to be class-based rather
                than a bare wire:loading directive. Whether "!hidden" is
                baked in AT ALL is itself conditional on
                $showSkeletonByDefault — on the very first paint (before
                initialLoadComplete) this must start VISIBLE, not hidden,
                since $this->leads is genuinely empty until the wire:init
                request completes and there'd be nothing to reveal it
                otherwise. --}}
                <div class="{{ $showSkeletonByDefault ? '' : '!hidden' }} flex flex-col gap-3.5" wire:loading.remove.class="!hidden" wire:target="{{ $listLoadingTargets }}">
                    @for ($i = 0; $i < $perPage; $i++)
                        <div wire:key="lead-card-skeleton-{{ $i }}" class="flex flex-col gap-2 rounded-lg border border-zinc-200/80 bg-zinc-50 p-3 dark:border-zinc-700/80 dark:bg-zinc-900/90">
                            <div class="flex items-start justify-between gap-2">
                                <div class="flex flex-col gap-1.5">
                                    <div class="h-2.5 w-14 skeleton-shimmer rounded"></div>
                                    <div class="h-3.5 w-28 skeleton-shimmer rounded"></div>
                                    <div class="h-2.5 w-20 skeleton-shimmer rounded"></div>
                                </div>
                                <div class="h-5 w-16 shrink-0 skeleton-shimmer rounded-full"></div>
                            </div>
                            <div class="flex items-center justify-between gap-2">
                                <div class="h-4 w-16 skeleton-shimmer rounded-full"></div>
                                <div class="h-4 w-14 skeleton-shimmer rounded"></div>
                            </div>
                        </div>
                    @endfor
                </div>
            </div>

            {{-- Desktop / Tablet Table View (hidden sm:block) --}}
            <div class="hidden sm:block min-w-0 max-w-full">
                <div class="max-h-[calc(100dvh-18rem)] overflow-x-auto overflow-y-auto">
                <flux:table>
                    {{-- flux:table renders table-fixed (see vendor Flux's
                    table/index.blade.php) with no explicit column widths, so
                    without pinning them the browser derives each column's
                    width from whichever row happens to be the first VISIBLE
                    one — and that row changes depending on wire:loading
                    state (the always-visible Facebook Lead Ads row vs. a
                    real lead row vs. a skeleton row), each with different
                    content widths. That made the header, the live rows, and
                    the skeleton bars disagree on column positions.

                    Widths are pinned TWICE, deliberately redundant: a
                    <colgroup> (below) AND matching classes on every
                    <flux:table.column> header cell. Chromium honors the
                    colgroup alone; Firefox does not reliably apply
                    percentage <col> widths under table-fixed (confirmed via
                    a user screenshot — DevTools showed the colgroup present
                    in the DOM with correct classes, but columns still
                    unaligned) — Firefox is the one browser that reliably
                    needs the width to live on the header row's own cells
                    (the "first row" table-fixed always uses to lock column
                    tracks), which is also spec-legal and the more
                    universally-supported technique on its own. Keep both in
                    sync if column count/proportions ever change — a 9th
                    column (bulk-select checkbox, Feature #6) was added
                    first, ahead of Lead ID, so every row shape below (the
                    Facebook Lead Ads aggregate row, real lead rows, both
                    @empty branches' colspan, and the skeleton row) needed
                    one more cell too. --}}
                    <colgroup>
                        <col class="w-[4%]">
                        <col class="w-[7%]">
                        <col class="w-[17%]">
                        <col class="w-[12%]">
                        <col class="w-[11%]">
                        <col class="w-[9%]">
                        <col class="w-[13%]">
                        <col class="w-[13%]">
                        <col class="w-[14%]">
                    </colgroup>
                    <flux:table.columns>
                        <flux:table.column class="w-[4%]">
                            {{-- Tri-state-ish "select all on this page" checkbox: checked
                            when every row currently on screen is selected, unchecked
                            (indeterminate reads as unchecked here) otherwise. Toggling
                            adds every not-yet-selected row on the page, or clears the
                            whole page's worth if all are already selected — see
                            Index::toggleSelectAllOnPage(). --}}
                            @php
                                $pageLeadIds = $this->leads->pluck('id')->all();
                                $allPageLeadsSelected = $pageLeadIds !== [] && array_diff($pageLeadIds, $selectedLeadIds) === [];
                            @endphp
                            <flux:checkbox wire:click="toggleSelectAllOnPage" :checked="$allPageLeadsSelected" :disabled="$pageLeadIds === []" aria-label="{{ __('Select all leads on this page') }}" />
                        </flux:table.column>
                        <flux:table.column class="w-[7%]">{{ __('Lead ID') }}</flux:table.column>
                        <flux:table.column class="w-[17%]">{{ __('Name') }}</flux:table.column>
                        <flux:table.column class="w-[12%]">{{ __('Source') }}</flux:table.column>
                        <flux:table.column class="w-[11%]">{{ __('Status') }}</flux:table.column>
                        <flux:table.column class="w-[9%]">{{ __('Priority') }}</flux:table.column>
                        <flux:table.column class="w-[13%]">{{ __('Tags') }}</flux:table.column>
                        <flux:table.column class="w-[13%]">{{ __('Assigned To') }}</flux:table.column>
                        <flux:table.column class="w-[14%]"></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($this->facebookLeadAdsSources as $source)
                            {{-- This row isn't being refetched by any of
                            $listLoadingTargets (a separate, unpaginated
                            computed property the search/filters don't
                            touch), so it deliberately stays real content
                            rather than being replaced by a skeleton — but
                            left at full opacity it looked broken sitting
                            directly above skeleton bars, like stale content
                            next to a stuck loader. Dimming it in step with
                            the skeleton reads as "this whole table is
                            loading" instead. --}}
                            <flux:table.row
                                wire:key="fb-lead-ads-source-{{ $source->id }}"
                                wire:loading.class="opacity-40 pointer-events-none"
                                wire:target="{{ $listLoadingTargets }}"
                                class="bg-zinc-50/50 dark:bg-zinc-900/40"
                            >
                                {{-- Facebook Lead Ads aggregate rows represent many
                                leads at once — never individually bulk-selectable,
                                same reasoning as why the status/priority/assigned-to
                                dropdown filters above don't apply to them either. --}}
                                <flux:table.cell>—</flux:table.cell>
                                <flux:table.cell>—</flux:table.cell>
                                <flux:table.cell>
                                    <div class="flex flex-col">
                                        <span class="font-medium">{{ $source->name }}</span>
                                        <flux:text size="sm" variant="subtle">
                                            {{ $source->leads_max_created_at ? __('Last submission :time', ['time' => \Illuminate\Support\Carbon::parse($source->leads_max_created_at)->diffForHumans()]) : __('No submissions yet') }}
                                        </flux:text>
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell><flux:badge size="sm" icon="{{ $source->type->icon() }}">{{ $source->type->label() }}</flux:badge></flux:table.cell>
                                <flux:table.cell><flux:badge size="sm" color="zinc">{{ trans_choice(':count lead|:count leads', $source->leads_count, ['count' => $source->leads_count]) }}</flux:badge></flux:table.cell>
                                <flux:table.cell>—</flux:table.cell>
                                <flux:table.cell>—</flux:table.cell>
                                <flux:table.cell>—</flux:table.cell>
                                <flux:table.cell>
                                    <div class="flex justify-end gap-2">
                                        @if ($source->leads_count > 0)
                                            <flux:button size="sm" :href="route('tenant-leads.source', $source)" wire:navigate>{{ __('View All') }}</flux:button>
                                        @elseif ($this->canDeleteLeads)
                                            <flux:button size="sm" variant="danger" wire:click="confirmDeleteSource('{{ $source->id }}')">
                                                {{ __('Delete') }}
                                            </flux:button>
                                        @endif
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                        @forelse ($this->leads as $lead)
                            @php
                                $isRecentlyCreated = $this->recentlyCreatedLeadId === $lead->id
                                    || ($lead->created_at && $lead->created_at->diffInSeconds(now()) < 30);
                                $isRecentlyUpdated = ! $isRecentlyCreated && $lead->hasUnseenUpdate();
                                $rowTenant = auth()->user()->tenant;
                                $rowMeetingAlertActive = $lead->meetingAlertActive($rowTenant->meeting_alert_enabled, $rowTenant->meeting_alert_days_before);
                                $rowFollowUpAlertActive = $lead->followUpAlertActive($rowTenant->followup_alert_enabled, $rowTenant->followup_alert_days_before);
                                $rowHasActiveAlert = $rowMeetingAlertActive || $rowFollowUpAlertActive;
                            @endphp
                            <flux:table.row
                                wire:key="lead-{{ $lead->id }}"
                                wire:loading.class="!hidden"
                                wire:target="{{ $listLoadingTargets }}"
                                @class([
                                    'transition-all duration-700 ease-out',
                                    'animate-lead-insert bg-emerald-500/10 dark:bg-emerald-500/15 border-l-4 border-l-emerald-500' => $isRecentlyCreated,
                                    'animate-lead-lift bg-amber-500/10 dark:bg-amber-500/15 border-l-4 border-l-amber-500' => $isRecentlyUpdated,
                                    // See $showSkeletonByDefault's docblock: starts hidden
                                    // on the very first paint, before wire:loading's JS
                                    // toggle has had a chance to run at all.
                                    '!hidden' => $showSkeletonByDefault,
                                ])
                            >
                                <flux:table.cell>
                                    <flux:checkbox
                                        wire:click="toggleLeadSelection('{{ $lead->id }}')"
                                        :checked="in_array($lead->id, $selectedLeadIds, true)"
                                        aria-label="{{ __('Select :name', ['name' => $lead->name]) }}"
                                    />
                                </flux:table.cell>
                                <flux:table.cell>
                                    @if ($rowHasActiveAlert)
                                        {{-- Text itself shimmers (background-clip: text sweep,
                                        see .lead-id-shimmer-text in app.css) rather than a
                                        border/ring around it — a deliberately different effect
                                        from the Kanban card's lead-alert-shimmer, which is
                                        untouched by this. Fixed zinc/silver tones, not the
                                        per-status shimmer color the Kanban card uses. --}}
                                        <flux:tooltip position="right">
                                            <span class="lead-id-shimmer-text font-mono text-xs font-semibold">{{ $lead->identifier }}</span>

                                            <flux:tooltip.content>
                                                <div class="flex flex-col gap-1">
                                                    <div>{{ __('Assigned to :name', ['name' => $lead->assignedUser?->name ?? __('Unassigned')]) }}</div>
                                                    @if ($rowMeetingAlertActive)
                                                        <div>
                                                            {{ __('Meeting scheduled on :date', ['date' => $lead->meeting_date->format('l, M j, Y')]) }}
                                                            {{ $lead->meetingTimeFormatted() ? __('at :time', ['time' => $lead->meetingTimeFormatted()]) : '' }}
                                                        </div>
                                                    @endif
                                                    @if ($rowFollowUpAlertActive)
                                                        <div>{{ __('Follow-up scheduled on :date', ['date' => $lead->next_follow_up_at->format('l, M j, Y')]) }}</div>
                                                    @endif
                                                </div>
                                            </flux:tooltip.content>
                                        </flux:tooltip>
                                    @else
                                        <span class="font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $lead->identifier }}</span>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>
                                    <div class="flex items-center gap-2">
                                        @if ($lead->avatarUrl())
                                            <img src="{{ $lead->avatarUrl() }}" alt="" loading="lazy" class="size-6 shrink-0 rounded-full object-cover">
                                        @endif
                                        <span @class(['font-bold text-zinc-900 dark:text-white' => $isRecentlyUpdated])>
                                            {{ $lead->name }}
                                        </span>
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell><flux:badge size="sm" icon="{{ $lead->source->type->icon() }}">{{ $lead->source->type->label() }}</flux:badge></flux:table.cell>
                                <flux:table.cell><flux:badge size="sm" :color="$lead->status->color()">{{ $lead->status->label() }}</flux:badge></flux:table.cell>
                                <flux:table.cell>
                                    <span @class(['font-bold text-zinc-900 dark:text-white' => $isRecentlyUpdated])>
                                        {{ ucfirst($lead->priority->value) }}
                                    </span>
                                </flux:table.cell>
                                <flux:table.cell>
                                    @include('livewire.tenant.leads.partials.tag-chips', ['tags' => $lead->tags, 'emptyDash' => true])
                                </flux:table.cell>
                                <flux:table.cell>
                                    <span @class(['font-bold text-zinc-900 dark:text-white' => $isRecentlyUpdated])>
                                        {{ $lead->assignedUser?->name ?? __('Unassigned') }}
                                    </span>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <div class="flex justify-end gap-2">
                                        <flux:button size="sm" :href="route('tenant-leads.show', $lead)" wire:navigate>{{ __('View') }}</flux:button>
                                        @if ($this->canDeleteLeads && $lead->source->type->isIndividuallyDeletable())
                                            <flux:button size="sm" variant="danger" wire:click="confirmDeleteLead('{{ $lead->id }}')">
                                                {{ __('Delete') }}
                                            </flux:button>
                                        @endif
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            @if ($hasActiveFilters)
                                <flux:table.row wire:loading.class="!hidden" wire:target="{{ $listLoadingTargets }}" @class(['!hidden' => $showSkeletonByDefault])>
                                    <flux:table.cell colspan="9">
                                        <div class="flex flex-col items-center justify-center gap-3 py-16 text-center">
                                            <flux:icon.magnifying-glass class="size-8 text-zinc-300 dark:text-zinc-600" />
                                            <flux:text variant="strong">{{ __('No leads match your search or filters.') }}</flux:text>
                                            <flux:text size="sm" variant="subtle">{{ __('Try adjusting or clearing them.') }}</flux:text>
                                        </div>
                                    </flux:table.cell>
                                </flux:table.row>
                            @elseif ($this->facebookLeadAdsSources->isEmpty())
                                <flux:table.row wire:loading.class="!hidden" wire:target="{{ $listLoadingTargets }}" @class(['!hidden' => $showSkeletonByDefault])>
                                    <flux:table.cell colspan="9">
                                        <div class="flex flex-col items-center justify-center gap-3 py-16 text-center">
                                            <flux:icon.inbox class="size-8 text-zinc-300 dark:text-zinc-600" />
                                            <flux:text variant="strong">{{ __('No leads yet') }}</flux:text>
                                            <flux:text size="sm" variant="subtle">{{ __('New leads from your connected channels will show up here.') }}</flux:text>
                                        </div>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endif
                        @endforelse
                    </flux:table.rows>

                    {{-- Skeleton: separate <tbody> (multiple tbody per table
                    is valid HTML) shown only while the list itself is being
                    refetched — see $listLoadingTargets above. Row count
                    matches $perPage (15 on desktop) rather than a fixed
                    number: a shorter fixed count made the table visibly
                    shrink to the skeleton's height and then snap back once
                    real rows replaced it.

                    class="!hidden" is baked in statically and REMOVED while
                    loading (wire:loading.remove.class), not the other way
                    around — see the long comment on the mobile card stack's
                    wrapper above for why a bare wire:loading directive can't
                    be used on a <tbody> at all (it can only fall back to
                    `display: inline-block`, which breaks a tbody's row-group
                    role and made its cells ignore the table's fixed column
                    widths entirely — this is what was actually causing the
                    header/skeleton column misalignment, not a browser- or
                    colgroup-specific quirk).

                    Whether "!hidden" starts present AT ALL is conditional on
                    $showSkeletonByDefault (see its docblock near the top of
                    this file) — on the very first paint this must start
                    VISIBLE, or "No leads yet" flashes from the real @empty
                    rows above before wire:loading's JS has even had a
                    chance to run once. --}}
                    <flux:table.rows @class(['!hidden' => ! $showSkeletonByDefault]) wire:loading.remove.class="!hidden" wire:target="{{ $listLoadingTargets }}">
                        @for ($i = 0; $i < $perPage; $i++)
                            <flux:table.row wire:key="lead-row-skeleton-{{ $i }}">
                                <flux:table.cell><div class="size-[1.125rem] skeleton-shimmer rounded-[.3rem]"></div></flux:table.cell>
                                <flux:table.cell><div class="h-3 w-16 skeleton-shimmer rounded"></div></flux:table.cell>
                                <flux:table.cell><div class="h-3 w-32 skeleton-shimmer rounded"></div></flux:table.cell>
                                <flux:table.cell><div class="h-5 w-20 skeleton-shimmer rounded-full"></div></flux:table.cell>
                                <flux:table.cell><div class="h-5 w-16 skeleton-shimmer rounded-full"></div></flux:table.cell>
                                <flux:table.cell><div class="h-3 w-14 skeleton-shimmer rounded"></div></flux:table.cell>
                                <flux:table.cell>
                                    <div class="flex gap-1">
                                        <div class="h-4 w-10 skeleton-shimmer rounded-full"></div>
                                        <div class="h-4 w-10 skeleton-shimmer rounded-full"></div>
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell><div class="h-3 w-20 skeleton-shimmer rounded"></div></flux:table.cell>
                                <flux:table.cell>
                                    <div class="flex justify-end gap-2">
                                        <div class="h-6 w-14 skeleton-shimmer rounded"></div>
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>
                        @endfor
                    </flux:table.rows>
                </flux:table>
                </div>
            </div>

            {{ $this->leads->links() }}
        </div>
    </div>

    @include('livewire.tenant.leads.partials.bulk-actions-toolbar')

    @if ($this->canCreateLead)
        <flux:modal wire:model.self="showCreateLeadModal" :dismissible="false" scroll="body" class="w-full md:max-w-[46rem] lg:max-w-[80rem]">
            <form wire:submit="saveLead" class="flex flex-col gap-6">
                <div>
                    <flux:heading size="lg">{{ __('New Lead') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('Register a lead that came in outside your connected channels — a call, a walk-in, a referral.') }}</flux:text>
                </div>

                <div class="grid gap-x-14 gap-y-6 lg:grid-cols-2">
                    <div class="flex flex-col gap-6">
                        <div class="flex flex-col gap-4">
                            <div class="flex items-center gap-2">
                                <flux:icon.user variant="micro" class="text-zinc-400 dark:text-zinc-500" />
                                <flux:heading size="sm">{{ __('Contact') }}</flux:heading>
                            </div>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <flux:input wire:model="leadName" :label="__('Contact Person')" placeholder="{{ __('e.g. Rafiqul Islam') }}" required />
                                <flux:input wire:model="leadPhone" type="tel" :label="__('Phone / WhatsApp')" placeholder="{{ __('e.g. +880 1712-345678') }}" required />
                                <flux:input wire:model="leadEmail" type="email" :label="__('Email Address')" placeholder="{{ __('e.g. rafiq@apexlogistics.com') }}" class="sm:col-span-2" />
                            </div>
                        </div>

                        <flux:separator />

                        <div class="flex flex-col gap-4">
                            <div class="flex items-center gap-2">
                                <flux:icon.building-office variant="micro" class="text-zinc-400 dark:text-zinc-500" />
                                <flux:heading size="sm">{{ __('Company') }}</flux:heading>
                            </div>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <flux:input wire:model="leadCompany" :label="__('Company / Organization')" placeholder="{{ __('e.g. Apex Global Logistics Ltd.') }}" />
                                <flux:input wire:model="leadWebsite" type="url" :label="__('Website URL')" placeholder="{{ __('e.g. https://apexlogistics.com') }}" />
                                <flux:input wire:model="leadIndustry" :label="__('Industry Sector')" placeholder="{{ __('e.g. Technology & Digital') }}" />
                                <flux:input wire:model="leadLocation" :label="__('Location / City')" placeholder="{{ __('e.g. Dhaka, Bangladesh') }}" />
                            </div>
                        </div>

                        <flux:separator />

                        <flux:textarea wire:model="leadMessage" :label="__('Intake Notes / Scope Details')" placeholder="{{ __('Key client requirements, current challenges, timeline expectations...') }}" rows="3" />
                    </div>

                    <div class="flex flex-col gap-6">
                        <div class="flex flex-col gap-4">
                            <div class="flex items-center gap-2">
                                <flux:icon.currency-dollar variant="micro" class="text-zinc-400 dark:text-zinc-500" />
                                <flux:heading size="sm">{{ __('Deal') }}</flux:heading>
                            </div>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <flux:input wire:model="leadServiceInterest" :label="__('Service Interested')" placeholder="{{ __('e.g. Custom Web Application & SaaS') }}" class="sm:col-span-2" />
                                <flux:input wire:model="leadSource" :label="__('Lead Source')" placeholder="{{ __('e.g. Referral, Facebook Ad, Trade Show') }}" class="sm:col-span-2" />

                                <flux:input.group :label="__('Est. Budget')">
                                    <flux:input.group.prefix class="!px-2">
                                        <select wire:model.live="leadEstimatedBudgetCurrencyId" class="cursor-pointer border-0 bg-transparent p-0 pe-5 text-sm font-medium focus:ring-0">
                                            @foreach ($this->tenantCurrencies as $currency)
                                                <option value="{{ $currency->id }}">{{ $currency->code }}</option>
                                            @endforeach
                                        </select>
                                    </flux:input.group.prefix>
                                    <flux:input wire:model="leadEstimatedBudget" type="number" step="0.01" min="0" placeholder="0.00" />
                                </flux:input.group>

                                <flux:input.group :label="__('Deal Value')">
                                    <flux:input.group.prefix class="!px-2">
                                        <select wire:model.live="leadDealValueCurrencyId" class="cursor-pointer border-0 bg-transparent p-0 pe-5 text-sm font-medium focus:ring-0">
                                            @foreach ($this->tenantCurrencies as $currency)
                                                <option value="{{ $currency->id }}">{{ $currency->code }}</option>
                                            @endforeach
                                        </select>
                                    </flux:input.group.prefix>
                                    <flux:input wire:model="leadDealValue" type="number" step="0.01" min="0" placeholder="0.00" />
                                </flux:input.group>

                                <flux:input.group :label="__('Win Probability')">
                                    <flux:input wire:model="leadWinProbability" type="number" min="0" max="100" placeholder="50" />
                                    <flux:input.group.suffix>%</flux:input.group.suffix>
                                </flux:input.group>

                                <div class="grid grid-cols-1 gap-4 sm:col-span-2 sm:grid-cols-2">
                                    <x-datetime-picker wire:model="leadMeetingDate" :label="__('Meeting Date')" :placeholder="__('Meeting Date')" :min="now()->toDateString()" />
                                    <x-date-picker wire:model="leadNextFollowUpAt" :label="__('Next Follow-up Date')" :placeholder="__('Next Follow-up Date')" :min="now()->toDateString()" />
                                </div>
                            </div>
                        </div>

                        <flux:separator />

                        <div class="flex flex-col gap-4">
                            <div class="flex items-center gap-2">
                                <flux:icon.flag variant="micro" class="text-zinc-400 dark:text-zinc-500" />
                                <flux:heading size="sm">{{ __('Pipeline') }}</flux:heading>
                            </div>
                            @php
                                $createStatusOpts = collect($this->statusOptions())->map(fn($o) => ['value' => (string) $o->id, 'label' => $o->label()])->toArray();
                                $createPriorityOpts = collect($this->priorityOptions())->map(fn($o) => ['value' => $o, 'label' => ucfirst($o)])->toArray();
                                if ($this->isClientAdmin) {
                                    $createAssigneeOpts = array_merge(
                                        [['value' => '', 'label' => __('Unassigned')]],
                                        collect($this->assignableUsers)->map(fn($u) => ['value' => (string) $u->id, 'label' => $u->name])->toArray()
                                    );
                                }
                            @endphp
                            <div class="grid gap-4 sm:grid-cols-2">
                                <x-dropdown-select wire:model="leadStatus" :label="__('Pipeline Stage')" :options="$createStatusOpts" />
                                <x-dropdown-select wire:model="leadPriority" :label="__('Priority')" :options="$createPriorityOpts" />
                                @if ($this->isClientAdmin)
                                    <div class="sm:col-span-2">
                                        <x-dropdown-select wire:model="leadAssigneeId" :label="__('Assigned Sales Executive')" :placeholder="__('Unassigned')" :options="$createAssigneeOpts" />
                                    </div>
                                @endif
                            </div>
                        </div>

                        @if ($this->activeCustomFieldDefinitions->isNotEmpty())
                            <flux:separator />

                            <div class="flex flex-col gap-4">
                                <div class="flex items-center gap-2">
                                    <flux:icon.squares-plus variant="micro" class="text-zinc-400 dark:text-zinc-500" />
                                    <flux:heading size="sm">{{ __('Custom Fields') }}</flux:heading>
                                </div>
                                <div class="grid gap-4 sm:grid-cols-2">
                                    @include('livewire.tenant.leads.partials.custom-fields-form-fields', ['definitions' => $this->activeCustomFieldDefinitions])
                                </div>
                            </div>
                        @endif
                    </div>
                </div>

                <flux:separator />

                <div class="flex justify-end gap-2">
                    <flux:button type="button" wire:click="$set('showCreateLeadModal', false)">{{ __('Cancel') }}</flux:button>
                    <flux:button type="submit" variant="primary">{{ __('Create Lead') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    {{-- Reused for bulk delete too (Feature #6) — see Index::confirmBulkDelete()/
    $bulkDeleteMode — rather than a second copy of this modal, which would
    otherwise put two flux:input wire:model="deletePassword" fields (same
    DOM id) on the page at once. --}}
    <x-confirm-password-delete-modal
        wire:model.self="showDeleteLeadModal"
        :title="$bulkDeleteMode ? __('Delete selected leads?') : __('Delete this lead?')"
        :description="$bulkDeleteMode ? __('This permanently deletes :count selected leads and their history. Enter your password to confirm.', ['count' => $this->selectedCount]) : null"
        confirm-action="deleteLeadConfirmed"
        close-action="closeDeleteLeadModal"
    />

    <x-confirm-modal
        wire:model.self="showDeleteSourceModal"
        :title="__('Delete this lead source?')"
        confirm-action="deleteSourceConfirmed"
        :confirm-label="__('Delete')"
    />
</div>
