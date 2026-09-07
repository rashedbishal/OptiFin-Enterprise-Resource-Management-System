@push('breadcrumbs')
    <x-breadcrumbs :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard')],
        ['label' => __('Leads'), 'href' => route('tenant-leads.index')],
        ['label' => $this->leadSource->name],
    ]" />
@endpush

<div class="flex flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8 overflow-y-auto min-h-0" wire:init="completeInitialLoad">
    <div class="flex items-center justify-between">
        <flux:heading size="xl" class="hidden lg:block">{{ $this->leadSource->name }}</flux:heading>
        <div class="flex gap-2">
            <flux:button icon="arrow-down-tray" wire:click="downloadXlsx">{{ __('Download XLSX') }}</flux:button>
            <flux:button :href="route('tenant-leads.index')" wire:navigate>{{ __('Back to Leads') }}</flux:button>
        </div>
    </div>

    @php
        $statusOpts = array_merge(
            [['value' => '', 'label' => __('All statuses')]],
            collect($this->statusOptions())->map(fn($o) => ['value' => $o, 'label' => ucfirst(str_replace('_', ' ', $o))])->toArray()
        );
        $priorityOpts = array_merge(
            [['value' => '', 'label' => __('All priorities')]],
            collect($this->priorityOptions())->map(fn($o) => ['value' => $o, 'label' => ucfirst($o)])->toArray()
        );
        if ($this->isClientAdmin) {
            $assignedToOpts = array_merge(
                [
                    ['value' => '', 'label' => __('All users')],
                    ['value' => 'unassigned', 'label' => __('Unassigned')],
                ],
                collect($this->assignableUsers)->map(fn($u) => ['value' => (string) $u->id, 'label' => $u->name])->toArray()
            );
        }

        // Every action that re-queries $this->leads: SourceLeads::updating()/
        // updated() reset the page and refetch on ANY property change (no
        // property-name allowlist like the main Leads page has), so this is
        // simply every FiltersLeadsQuery property this page exposes a filter
        // for (no sourceType here — the page is already scoped to one
        // source) plus the pagination-trait methods the published
        // pagination view can call, plus completeInitialLoad() (fired once
        // via wire:init on the root element, right after mount — see
        // SourceLeads::$initialLoadComplete's docblock for why the very
        // first paint needs the skeleton too). See leads.blade.php's
        // identical $listLoadingTargets for why this exact list matters —
        // wire:loading must never fire on unrelated requests (Download
        // XLSX, ...).
        $listLoadingTargets = 'status,priority,assignedTo,fromDate,toDate,search,gotoPage,previousPage,nextPage,setPage,completeInitialLoad';

        // paginate(25) is fixed here (no per-viewport setPerPage() like the
        // main Leads page), so the skeleton row/card count is simply this
        // constant — kept in one place so it can't drift out of sync with
        // the real ->paginate(25) call in SourceLeads::leads().
        $sourceLeadsPerPage = 25;

        // See the identical $showSkeletonByDefault in leads.blade.php for
        // the full explanation: wire:loading's class toggle only takes
        // effect once Livewire's JS actually dispatches a request, and
        // there's a real gap between this raw HTML painting and the
        // wire:init below firing. Without this, the real (but genuinely
        // empty pre-initialLoadComplete) content would flash "No leads yet"
        // before the skeleton ever appears.
        $showSkeletonByDefault = ! $initialLoadComplete;

        // Distinguishes "no leads at all" from "your search/filters matched
        // nothing" for the @empty blocks below — same reasoning as
        // leads.blade.php's identical $hasActiveFilters (no sourceType
        // here, this page is already scoped to one source).
        $hasActiveFilters = $status !== ''
            || $priority !== ''
            || ($this->isClientAdmin && $assignedTo !== '')
            || $fromDate !== ''
            || $toDate !== ''
            || $search !== '';
    @endphp

    <div class="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-6">
        <x-dropdown-select wire:model.live="status" :placeholder="__('All statuses')" :options="$statusOpts" icon="squares-2x2" />
        <x-dropdown-select wire:model.live="priority" :placeholder="__('All priorities')" :options="$priorityOpts" icon="funnel" />

        @if ($this->isClientAdmin)
            <x-dropdown-select wire:model.live="assignedTo" :placeholder="__('All users')" :options="$assignedToOpts" icon="user" />
        @endif

        {{-- !h-auto !py-1.5 !text-xs sm:!text-sm overrides x-date-picker's own
        default (h-10 px-3 py-2 text-sm) down to x-dropdown-select's natural
        button height — see the matching comment on the main Leads page's
        filter bar (leads.blade.php) for why plain class="" wouldn't do it. --}}
        <x-date-picker wire:model.live="fromDate" :label="__('From Date')" :placeholder="__('From Date')" :max="$toDate ?: now()->toDateString()" class="!h-auto !py-1.5 !text-xs sm:!text-sm" />
        <x-date-picker wire:model.live="toDate" :label="__('To Date')" :placeholder="__('To Date')" :min="$fromDate" :max="now()->toDateString()" class="!h-auto !py-1.5 !text-xs sm:!text-sm" />

        {{-- Same col-span pattern as the main Leads page's search box: full
        width on its own row on mobile/tablet (col-span-2/3), a normal cell
        alongside the dropdowns/dates at lg. size="sm" matches Flux's own
        input height (h-10 by default) to x-dropdown-select/x-date-picker's
        button height (py-1.5, no fixed h-*) — without it the search box
        stands noticeably taller than the fields beside it. Only matches
        Lead ID, unlike the main page's search (see SourceLeads::applyLeadIdSearch()). --}}
        <flux:input
            wire:model.live.debounce.400ms="search"
            icon="magnifying-glass"
            clearable
            size="sm"
            :placeholder="__('Search by Lead ID')"
            class="col-span-2 md:col-span-3 lg:col-span-1"
        />
    </div>

    {{-- Mobile Card Stack (sm:hidden) — deliberately minimal compared to the
    desktop table: just Lead ID (shimmering when a meeting/follow-up alert is
    active, same as the main Leads table), Status, and Submitted time. The
    Instant Form's dynamic field columns, Name, and Form live only in the
    desktop table — there's no room to usefully show a variable number of
    per-form fields in a compact card. The whole card is the click target
    (no separate View button), same pattern as the main Leads page's cards. --}}
    <div class="flex flex-col gap-2.5 sm:hidden">
        {{-- display:contents wrapper + skeleton sibling below, same pattern
        as the main Leads page's mobile card stack (leads.blade.php) —
        including the SAME wire:loading.class="!hidden" technique (never a
        bare wire:loading/.remove on this or on the desktop <tbody>/<tr>
        below: Livewire's non-class display toggling only knows a handful of
        display values and falls back to `display: inline-block` for
        anything else, including "contents" and a <tbody>'s
        "table-row-group" — which breaks table column alignment and, worse,
        permanently corrupts a `.remove` element to inline-block after the
        first completed loading cycle, not just during it). --}}
        <div wire:loading.class="!hidden" wire:target="{{ $listLoadingTargets }}" class="{{ $showSkeletonByDefault ? '!hidden' : '' }} contents">
        @forelse ($this->leads as $lead)
            @php
                $cardTenant = auth()->user()->tenant;
                $cardMeetingAlertActive = $lead->meetingAlertActive($cardTenant->meeting_alert_enabled, $cardTenant->meeting_alert_days_before);
                $cardFollowUpAlertActive = $lead->followUpAlertActive($cardTenant->followup_alert_enabled, $cardTenant->followup_alert_days_before);
                $cardHasActiveAlert = $cardMeetingAlertActive || $cardFollowUpAlertActive;
            @endphp
            <a
                href="{{ route('tenant-leads.show', $lead) }}"
                wire:navigate
                wire:key="fb-source-lead-card-{{ $lead->id }}"
                class="flex items-center justify-between gap-3 rounded-lg border border-zinc-200/80 bg-zinc-50 p-3 shadow-sm active:opacity-80 dark:border-zinc-700/80 dark:bg-zinc-900/90"
            >
                <div class="flex flex-col gap-0.5 leading-tight">
                    @if ($cardHasActiveAlert)
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
                    <span class="text-[11px] text-zinc-500 dark:text-zinc-400">{{ $lead->created_at?->diffForHumans() }}</span>
                </div>
                <flux:badge size="sm" :color="$lead->status->color()">{{ $lead->status->label() }}</flux:badge>
            </a>
        @empty
            @if ($hasActiveFilters)
                <div class="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-zinc-200 py-16 text-center dark:border-zinc-700">
                    <flux:icon.magnifying-glass class="size-8 text-zinc-300 dark:text-zinc-600" />
                    <flux:text variant="strong">{{ __('No leads match your search or filters.') }}</flux:text>
                    <flux:text size="sm" variant="subtle">{{ __('Try adjusting or clearing them.') }}</flux:text>
                </div>
            @else
                <div class="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-zinc-200 py-16 text-center dark:border-zinc-700">
                    <flux:icon.inbox class="size-8 text-zinc-300 dark:text-zinc-600" />
                    <flux:text variant="strong">{{ __('No leads yet') }}</flux:text>
                    <flux:text size="sm" variant="subtle">{{ __('New leads submitted through this Facebook Lead Ads source will show up here.') }}</flux:text>
                </div>
            @endif
        @endforelse
        </div>

        <div class="{{ $showSkeletonByDefault ? '' : '!hidden' }} flex flex-col gap-2.5" wire:loading.remove.class="!hidden" wire:target="{{ $listLoadingTargets }}">
            @for ($i = 0; $i < $sourceLeadsPerPage; $i++)
                <div wire:key="fb-source-lead-card-skeleton-{{ $i }}" class="flex items-center justify-between gap-3 rounded-lg border border-zinc-200/80 bg-zinc-50 p-3 dark:border-zinc-700/80 dark:bg-zinc-900/90">
                    <div class="flex flex-col gap-1.5">
                        <div class="h-2.5 w-14 skeleton-shimmer rounded"></div>
                        <div class="h-2.5 w-20 skeleton-shimmer rounded"></div>
                    </div>
                    <div class="h-5 w-16 shrink-0 skeleton-shimmer rounded-full"></div>
                </div>
            @endfor
        </div>
    </div>

    {{-- Desktop / Tablet Table View (hidden sm:block) --}}
    <div class="hidden overflow-x-auto sm:block">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Lead ID') }}</flux:table.column>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                @foreach ($this->fieldColumns as $column)
                    <flux:table.column>{{ ucwords(str_replace('_', ' ', $column)) }}</flux:table.column>
                @endforeach
                <flux:table.column>{{ __('Form') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Submitted') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->leads as $lead)
                    @php
                        $rowTenant = auth()->user()->tenant;
                        $rowMeetingAlertActive = $lead->meetingAlertActive($rowTenant->meeting_alert_enabled, $rowTenant->meeting_alert_days_before);
                        $rowFollowUpAlertActive = $lead->followUpAlertActive($rowTenant->followup_alert_enabled, $rowTenant->followup_alert_days_before);
                        $rowHasActiveAlert = $rowMeetingAlertActive || $rowFollowUpAlertActive;
                    @endphp
                    <flux:table.row wire:key="lead-{{ $lead->id }}" wire:loading.class="!hidden" wire:target="{{ $listLoadingTargets }}" @class(['!hidden' => $showSkeletonByDefault])>
                        <flux:table.cell>
                            @if ($rowHasActiveAlert)
                                {{-- Same per-character shimmer as the main Leads table's Lead
                                ID cell (see .lead-id-shimmer-text in app.css). --}}
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
                        <flux:table.cell>{{ $lead->name }}</flux:table.cell>
                        @php $fieldValues = $lead->leadAdFieldValues(); @endphp
                        @foreach ($this->fieldColumns as $column)
                            <flux:table.cell>{{ $fieldValues[$column] ?? '—' }}</flux:table.cell>
                        @endforeach
                        <flux:table.cell>{{ $lead->source_data['form_name'] ?? '—' }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm" :color="$lead->status->color()">{{ $lead->status->label() }}</flux:badge></flux:table.cell>
                        <flux:table.cell>{{ $lead->created_at?->diffForHumans() }}</flux:table.cell>
                        <flux:table.cell>
                            <div class="flex justify-end">
                                <flux:button size="sm" :href="route('tenant-leads.show', $lead)" wire:navigate>{{ __('View') }}</flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    @if ($hasActiveFilters)
                        <flux:table.row wire:loading.class="!hidden" wire:target="{{ $listLoadingTargets }}" @class(['!hidden' => $showSkeletonByDefault])>
                            <flux:table.cell colspan="{{ count($this->fieldColumns) + 6 }}">
                                <div class="flex flex-col items-center justify-center gap-3 py-16 text-center">
                                    <flux:icon.magnifying-glass class="size-8 text-zinc-300 dark:text-zinc-600" />
                                    <flux:text variant="strong">{{ __('No leads match your search or filters.') }}</flux:text>
                                    <flux:text size="sm" variant="subtle">{{ __('Try adjusting or clearing them.') }}</flux:text>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @else
                        <flux:table.row wire:loading.class="!hidden" wire:target="{{ $listLoadingTargets }}" @class(['!hidden' => $showSkeletonByDefault])>
                            <flux:table.cell colspan="{{ count($this->fieldColumns) + 6 }}">
                                <div class="flex flex-col items-center justify-center gap-3 py-16 text-center">
                                    <flux:icon.inbox class="size-8 text-zinc-300 dark:text-zinc-600" />
                                    <flux:text variant="strong">{{ __('No leads yet') }}</flux:text>
                                    <flux:text size="sm" variant="subtle">{{ __('New leads submitted through this Facebook Lead Ads source will show up here.') }}</flux:text>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endif
                @endforelse
            </flux:table.rows>

            {{-- Skeleton: a second <tbody> (valid HTML), hidden by default
            (class="!hidden" baked in, removed while loading — same
            .remove.class technique as the mobile stack above and the main
            Leads table). Cell count per row is 2 (Lead ID, Name) + one per
            dynamic Instant Form field column + 4 (Form, Status, Submitted,
            Actions) — matching the real header's column count exactly, same
            as the @empty row's colspan above. --}}
            <flux:table.rows @class(['!hidden' => ! $showSkeletonByDefault]) wire:loading.remove.class="!hidden" wire:target="{{ $listLoadingTargets }}">
                @for ($i = 0; $i < $sourceLeadsPerPage; $i++)
                    <flux:table.row wire:key="fb-source-lead-row-skeleton-{{ $i }}">
                        <flux:table.cell><div class="h-3 w-16 skeleton-shimmer rounded"></div></flux:table.cell>
                        <flux:table.cell><div class="h-3 w-28 skeleton-shimmer rounded"></div></flux:table.cell>
                        @for ($f = 0; $f < count($this->fieldColumns); $f++)
                            <flux:table.cell><div class="h-3 w-20 skeleton-shimmer rounded"></div></flux:table.cell>
                        @endfor
                        <flux:table.cell><div class="h-3 w-16 skeleton-shimmer rounded"></div></flux:table.cell>
                        <flux:table.cell><div class="h-5 w-16 skeleton-shimmer rounded-full"></div></flux:table.cell>
                        <flux:table.cell><div class="h-3 w-14 skeleton-shimmer rounded"></div></flux:table.cell>
                        <flux:table.cell>
                            <div class="flex justify-end">
                                <div class="h-6 w-14 skeleton-shimmer rounded"></div>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endfor
            </flux:table.rows>
        </flux:table>
    </div>

    {{ $this->leads->links() }}
</div>
