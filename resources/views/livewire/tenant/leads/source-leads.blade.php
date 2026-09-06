@push('breadcrumbs')
    <x-breadcrumbs :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard')],
        ['label' => __('Leads'), 'href' => route('tenant-leads.index')],
        ['label' => $this->leadSource->name],
    ]" />
@endpush

<div class="flex flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8 overflow-y-auto min-h-0">
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
    @endphp

    <div class="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-6">
        <x-dropdown-select wire:model.live="status" :placeholder="__('All statuses')" :options="$statusOpts" icon="squares-2x2" />
        <x-dropdown-select wire:model.live="priority" :placeholder="__('All priorities')" :options="$priorityOpts" icon="funnel" />

        @if ($this->isClientAdmin)
            <x-dropdown-select wire:model.live="assignedTo" :placeholder="__('All users')" :options="$assignedToOpts" icon="user" />
        @endif

        <x-date-picker wire:model.live="fromDate" :label="__('From Date')" :placeholder="__('From Date')" :max="$toDate ?: now()->toDateString()" />
        <x-date-picker wire:model.live="toDate" :label="__('To Date')" :placeholder="__('To Date')" :min="$fromDate" :max="now()->toDateString()" />

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
            <div class="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-zinc-200 p-8 text-center dark:border-zinc-700">
                <flux:icon.inbox class="size-6 text-zinc-400" />
                <flux:text variant="subtle">{{ __('No leads yet.') }}</flux:text>
            </div>
        @endforelse
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
                    <flux:table.row wire:key="lead-{{ $lead->id }}">
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
                    <flux:table.row>
                        <flux:table.cell colspan="{{ count($this->fieldColumns) + 6 }}">{{ __('No leads yet.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    {{ $this->leads->links() }}
</div>
