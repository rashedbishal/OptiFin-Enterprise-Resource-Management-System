@include('partials.lead-management-breadcrumbs', ['leadManagementTrail' => [['label' => __('Reports & Analytics')]]])

<div class="flex flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8 overflow-y-auto min-h-0">
    <div>
        <flux:heading size="xl" class="hidden lg:block">{{ __('Reports & Analytics') }}</flux:heading>
        <flux:text variant="subtle">{{ __('Tenant-wide lead reporting for the selected date range.') }}</flux:text>
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 max-w-xl">
        <flux:field>
            <flux:label>{{ __('From') }}</flux:label>
            <x-date-picker wire:model.live="fromDate" :placeholder="__('From Date')" :max="$toDate ?: now()->toDateString()" />
        </flux:field>
        <flux:field>
            <flux:label>{{ __('To') }}</flux:label>
            <x-date-picker wire:model.live="toDate" :placeholder="__('To Date')" :min="$fromDate" :max="now()->toDateString()" />
        </flux:field>
    </div>

    @if ($this->rangeError)
        <flux:callout variant="danger" icon="exclamation-triangle" :heading="__('Invalid date range')">
            <flux:callout.text>{{ $this->rangeError }}</flux:callout.text>
        </flux:callout>
    @else
        <div class="grid auto-rows-min gap-4 md:grid-cols-3">
            <flux:card>
                <flux:text>{{ __('Lead Volume') }}</flux:text>
                <flux:heading size="xl">{{ $this->volumeCount }}</flux:heading>
            </flux:card>
        </div>

        <div>
            <div class="grid auto-rows-min gap-4 md:grid-cols-4">
                <flux:card>
                    <flux:text>{{ __('Responded') }}</flux:text>
                    <flux:heading size="xl">{{ $this->firstResponseSummary['responded_count'] }}</flux:heading>
                </flux:card>
                <flux:card>
                    <flux:text>{{ __('Not Responded') }}</flux:text>
                    <flux:heading size="xl" class="text-amber-600 dark:text-amber-400">{{ $this->firstResponseSummary['not_responded_count'] }}</flux:heading>
                </flux:card>
                <flux:card>
                    <flux:text>{{ __('Average First Response Time') }}</flux:text>
                    <flux:heading size="xl">
                        {{ $this->firstResponseSummary['average_hours'] !== null ? __(':hours hours', ['hours' => $this->firstResponseSummary['average_hours']]) : __('No data') }}
                    </flux:heading>
                </flux:card>
                <flux:card>
                    <flux:text>{{ __('Median First Response Time') }}</flux:text>
                    <flux:heading size="xl">
                        {{ $this->firstResponseSummary['median_hours'] !== null ? __(':hours hours', ['hours' => $this->firstResponseSummary['median_hours']]) : __('No data') }}
                    </flux:heading>
                </flux:card>
            </div>
            <flux:text variant="subtle" class="mt-2">
                {{ __('This reporting window is based on when each lead was created, not when it was responded to — a lead created inside the window still counts with its full response time even if that response happened after the window ended.') }}
            </flux:text>
        </div>

        <flux:card>
            <flux:heading size="lg" class="mb-4">{{ __('Daily Lead Volume') }}</flux:heading>

            {{-- Mobile Card Stack (sm:hidden) with vertical scroll & fixed max height --}}
            <div class="sm:hidden mb-4 max-h-72 overflow-y-auto rounded-xl border border-zinc-200/90 bg-zinc-100/80 p-2.5 shadow-inner dark:border-zinc-800 dark:bg-zinc-950/80 pr-1.5">
                <div class="flex flex-col gap-2">
                    @forelse ($this->dailyBreakdown as $day)
                        <div wire:key="daily-card-{{ $day['date'] }}" class="flex items-center justify-between gap-2 rounded-lg border border-zinc-200/90 bg-white p-3 shadow-2xs dark:border-zinc-800 dark:bg-zinc-900">
                            <div class="flex items-center gap-2.5 min-w-0">
                                <div class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-indigo-50 text-indigo-600 dark:bg-indigo-950/60 dark:text-indigo-400">
                                    <flux:icon.calendar variant="micro" class="size-4" />
                                </div>
                                <span class="text-xs sm:text-sm font-semibold text-zinc-900 dark:text-white">
                                    {{ \Illuminate\Support\Carbon::parse($day['date'])->toFormattedDateString() }}
                                </span>
                            </div>
                            <flux:badge size="sm" color="indigo" class="font-bold">
                                {{ trans_choice(':count lead|:count leads', $day['count'], ['count' => $day['count']]) }}
                            </flux:badge>
                        </div>
                    @empty
                        <div class="flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-zinc-300 bg-white p-6 text-center dark:border-zinc-700 dark:bg-zinc-900">
                            <flux:icon.inbox class="size-6 text-zinc-400" />
                            <flux:text variant="subtle" class="text-xs">{{ __('No leads created in this date range.') }}</flux:text>
                        </div>
                    @endforelse
                </div>
            </div>

            {{-- Desktop / Tablet Table View with vertical scrolling & distinct contrast background --}}
            <div class="hidden sm:block mb-4">
                <div class="max-h-80 overflow-y-auto rounded-xl border border-zinc-200/90 bg-zinc-100/80 p-1.5 shadow-inner dark:border-zinc-800/90 dark:bg-zinc-950/80">
                    <flux:table container:class="!bg-transparent !border-0 !shadow-none">
                        <flux:table.columns class="bg-zinc-200/70 dark:bg-zinc-800/70 border-b border-zinc-200 dark:border-zinc-700">
                            <flux:table.column class="font-bold text-zinc-700 dark:text-zinc-200">{{ __('Date') }}</flux:table.column>
                            <flux:table.column class="font-bold text-zinc-700 dark:text-zinc-200">{{ __('Leads Created') }}</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @forelse ($this->dailyBreakdown as $index => $day)
                                <flux:table.row
                                    wire:key="daily-{{ $day['date'] }}"
                                    @class([
                                        'border-b border-zinc-200/60 dark:border-zinc-800/60 transition-colors',
                                        'bg-white dark:bg-zinc-900' => $loop->even,
                                        'bg-zinc-50/90 dark:bg-zinc-950/60' => $loop->odd,
                                    ])
                                >
                                    <flux:table.cell class="font-semibold text-zinc-900 dark:text-white">{{ \Illuminate\Support\Carbon::parse($day['date'])->toFormattedDateString() }}</flux:table.cell>
                                    <flux:table.cell><flux:badge size="sm" color="indigo" class="font-bold">{{ $day['count'] }}</flux:badge></flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row class="bg-white dark:bg-zinc-900">
                                    <flux:table.cell colspan="2">
                                        <flux:text variant="subtle">{{ __('No leads created in this date range.') }}</flux:text>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                </div>
            </div>

            {{ $this->dailyBreakdown->links() }}
        </flux:card>

        <flux:card>
            <flux:heading size="lg" class="mb-4">{{ __('Status Distribution') }}</flux:heading>

            {{-- Mobile Card Stack (sm:hidden) --}}
            <div class="sm:hidden rounded-xl border border-zinc-200/90 bg-zinc-100/80 p-2.5 shadow-inner dark:border-zinc-800 dark:bg-zinc-950/80">
                <div class="flex flex-col gap-2">
                    @forelse ($this->statusBreakdown as $row)
                        @php $status = $this->tenantStatusesById[$row['status']] ?? null @endphp
                        <div wire:key="status-card-{{ $row['status'] }}" class="flex items-center justify-between gap-2 rounded-lg border border-zinc-200/90 bg-white p-3 shadow-2xs dark:border-zinc-800 dark:bg-zinc-900">
                            <div class="flex items-center gap-2 min-w-0">
                                @if ($status)
                                    <flux:badge size="sm" :color="$status->color">{{ $status->name }}</flux:badge>
                                @else
                                    <span class="text-xs sm:text-sm font-semibold text-zinc-900 dark:text-white">{{ $row['status'] }}</span>
                                @endif
                            </div>
                            <flux:badge size="sm" color="zinc" class="font-bold">
                                {{ trans_choice(':count lead|:count leads', $row['count'], ['count' => $row['count']]) }}
                            </flux:badge>
                        </div>
                    @empty
                        <div class="flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-zinc-300 bg-white p-6 text-center dark:border-zinc-700 dark:bg-zinc-900">
                            <flux:icon.inbox class="size-6 text-zinc-400" />
                            <flux:text variant="subtle" class="text-xs">{{ __('No leads created in this date range.') }}</flux:text>
                        </div>
                    @endforelse
                </div>
            </div>

            {{-- Desktop / Tablet Table View with distinct background --}}
            <div class="hidden sm:block">
                <div class="rounded-xl border border-zinc-200/90 bg-zinc-100/80 p-1.5 shadow-inner dark:border-zinc-800/90 dark:bg-zinc-950/80">
                    <flux:table container:class="!bg-transparent !border-0 !shadow-none">
                        <flux:table.columns class="bg-zinc-200/70 dark:bg-zinc-800/70 border-b border-zinc-200 dark:border-zinc-700">
                            <flux:table.column class="font-bold text-zinc-700 dark:text-zinc-200">{{ __('Status') }}</flux:table.column>
                            <flux:table.column class="font-bold text-zinc-700 dark:text-zinc-200">{{ __('Leads') }}</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @forelse ($this->statusBreakdown as $row)
                                @php $status = $this->tenantStatusesById[$row['status']] ?? null @endphp
                                <flux:table.row
                                    wire:key="status-{{ $row['status'] }}"
                                    @class([
                                        'border-b border-zinc-200/60 dark:border-zinc-800/60 transition-colors',
                                        'bg-white dark:bg-zinc-900' => $loop->even,
                                        'bg-zinc-50/90 dark:bg-zinc-950/60' => $loop->odd,
                                    ])
                                >
                                    <flux:table.cell>
                                        @if ($status)
                                            <flux:badge size="sm" :color="$status->color">{{ $status->name }}</flux:badge>
                                        @else
                                            <span class="font-semibold text-zinc-900 dark:text-white">{{ $row['status'] }}</span>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell><flux:badge size="sm" color="zinc" class="font-bold">{{ $row['count'] }}</flux:badge></flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row class="bg-white dark:bg-zinc-900">
                                    <flux:table.cell colspan="2">
                                        <flux:text variant="subtle">{{ __('No leads created in this date range.') }}</flux:text>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                </div>
            </div>
        </flux:card>

        <flux:card>
            <flux:heading size="lg" class="mb-4">{{ __('Source Performance') }}</flux:heading>

            {{-- Mobile Card Stack (sm:hidden) --}}
            <div class="sm:hidden rounded-xl border border-zinc-200/90 bg-zinc-100/80 p-2.5 shadow-inner dark:border-zinc-800 dark:bg-zinc-950/80">
                <div class="flex flex-col gap-2">
                    @forelse ($this->sourceBreakdown as $row)
                        <div wire:key="source-card-{{ $row['source']->id }}" class="flex items-center justify-between gap-2 rounded-lg border border-zinc-200/90 bg-white p-3 shadow-2xs dark:border-zinc-800 dark:bg-zinc-900">
                            <div class="flex items-center gap-2 min-w-0">
                                <span class="text-xs sm:text-sm font-semibold text-zinc-900 dark:text-white">{{ $row['source']->name }}</span>
                                @unless ($row['source']->is_active)
                                    <flux:badge size="sm" color="zinc">{{ __('Inactive') }}</flux:badge>
                                @endunless
                            </div>
                            <flux:badge size="sm" color="zinc" class="font-bold">
                                {{ trans_choice(':count lead|:count leads', $row['count'], ['count' => $row['count']]) }}
                            </flux:badge>
                        </div>
                    @empty
                        <div class="flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-zinc-300 bg-white p-6 text-center dark:border-zinc-700 dark:bg-zinc-900">
                            <flux:icon.inbox class="size-6 text-zinc-400" />
                            <flux:text variant="subtle" class="text-xs">{{ __('No leads created in this date range.') }}</flux:text>
                        </div>
                    @endforelse
                </div>
            </div>

            {{-- Desktop / Tablet Table View with distinct background --}}
            <div class="hidden sm:block">
                <div class="rounded-xl border border-zinc-200/90 bg-zinc-100/80 p-1.5 shadow-inner dark:border-zinc-800/90 dark:bg-zinc-950/80">
                    <flux:table container:class="!bg-transparent !border-0 !shadow-none">
                        <flux:table.columns class="bg-zinc-200/70 dark:bg-zinc-800/70 border-b border-zinc-200 dark:border-zinc-700">
                            <flux:table.column class="font-bold text-zinc-700 dark:text-zinc-200">{{ __('Source') }}</flux:table.column>
                            <flux:table.column class="font-bold text-zinc-700 dark:text-zinc-200">{{ __('Leads') }}</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @forelse ($this->sourceBreakdown as $row)
                                <flux:table.row
                                    wire:key="source-{{ $row['source']->id }}"
                                    @class([
                                        'border-b border-zinc-200/60 dark:border-zinc-800/60 transition-colors',
                                        'bg-white dark:bg-zinc-900' => $loop->even,
                                        'bg-zinc-50/90 dark:bg-zinc-950/60' => $loop->odd,
                                    ])
                                >
                                    <flux:table.cell>
                                        <span class="font-semibold text-zinc-900 dark:text-white">{{ $row['source']->name }}</span>
                                        @unless ($row['source']->is_active)
                                            <flux:badge size="sm" color="zinc" class="ms-1.5">{{ __('Inactive') }}</flux:badge>
                                        @endunless
                                    </flux:table.cell>
                                    <flux:table.cell><flux:badge size="sm" color="zinc" class="font-bold">{{ $row['count'] }}</flux:badge></flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row class="bg-white dark:bg-zinc-900">
                                    <flux:table.cell colspan="2">
                                        <flux:text variant="subtle">{{ __('No leads created in this date range.') }}</flux:text>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                </div>
            </div>
        </flux:card>

        <flux:card>
            <div class="mb-4 flex items-start justify-between gap-3">
                <flux:heading size="lg">{{ __('Cost per Lead') }}</flux:heading>
                {{-- The Source Costs page is a sibling page of this one inside
                the Lead Management module (not a Leads sub-page), so the
                button reads as navigation between module pages. --}}
                <flux:button size="sm" variant="ghost" icon-trailing="arrow-right" :href="route('tenant-leads.source-costs')" wire:navigate>
                    {{ __('Source Costs') }}
                </flux:button>
            </div>

            @if ($this->sourceCostSummary['excluded_months'] !== [])
                <flux:callout variant="warning" icon="information-circle" class="mb-4">
                    <flux:callout.text>
                        {{ __('Only fully-covered calendar months are included below — no partial-month spend is prorated. :months :was outside your selected range and :is excluded.', [
                            'months' => implode(', ', $this->sourceCostSummary['excluded_months']),
                            'was' => count($this->sourceCostSummary['excluded_months']) === 1 ? 'was partially' : 'were partially',
                            'is' => count($this->sourceCostSummary['excluded_months']) === 1 ? 'is' : 'are',
                        ]) }}
                    </flux:callout.text>
                </flux:callout>
            @endif

            @if ($this->sourceCostSummary['rows']->isEmpty())
                <flux:text variant="subtle">
                    {{ __('No cost data for any fully-covered calendar month in this range. Select a range spanning at least one full calendar month, or record a cost entry for it.') }}
                </flux:text>
            @else
                {{-- Mobile Card Stack (sm:hidden) --}}
                <div class="sm:hidden rounded-xl border border-zinc-200/90 bg-zinc-100/80 p-2.5 shadow-inner dark:border-zinc-800 dark:bg-zinc-950/80">
                    <div class="flex flex-col gap-2">
                        @foreach ($this->sourceCostSummary['rows'] as $row)
                            <div wire:key="source-cost-card-{{ $row['source']->id }}" class="flex flex-col gap-2 rounded-lg border border-zinc-200/90 bg-white p-3 shadow-2xs dark:border-zinc-800 dark:bg-zinc-900">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="font-semibold text-zinc-900 dark:text-white">{{ $row['source']->name }}</span>
                                    <flux:badge size="sm" color="zinc">{{ trans_choice(':count lead|:count leads', $row['lead_count'], ['count' => $row['lead_count']]) }}</flux:badge>
                                </div>
                                <div class="flex items-center justify-between gap-2 pt-2 border-t border-zinc-200/80 dark:border-zinc-800 text-xs">
                                    <span class="text-zinc-500 dark:text-zinc-400">{{ __('Total Cost:') }} <strong class="text-zinc-900 dark:text-white">{{ $row['currency']->code }} {{ number_format((float) $row['total_cost'], 2) }}</strong></span>
                                    <span class="text-zinc-500 dark:text-zinc-400">{{ __('Cost / Lead:') }} <strong class="text-zinc-900 dark:text-white">{{ $row['cost_per_lead'] !== null ? $row['currency']->code.' '.number_format($row['cost_per_lead'], 2) : __('N/A') }}</strong></span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Desktop / Tablet Table View with distinct background --}}
                <div class="hidden sm:block">
                    <div class="rounded-xl border border-zinc-200/90 bg-zinc-100/80 p-1.5 shadow-inner dark:border-zinc-800/90 dark:bg-zinc-950/80">
                        <flux:table container:class="!bg-transparent !border-0 !shadow-none">
                            <flux:table.columns class="bg-zinc-200/70 dark:bg-zinc-800/70 border-b border-zinc-200 dark:border-zinc-700">
                                <flux:table.column class="font-bold text-zinc-700 dark:text-zinc-200">{{ __('Source') }}</flux:table.column>
                                <flux:table.column class="font-bold text-zinc-700 dark:text-zinc-200">{{ __('Total Cost') }}</flux:table.column>
                                <flux:table.column class="font-bold text-zinc-700 dark:text-zinc-200">{{ __('Leads') }}</flux:table.column>
                                <flux:table.column class="font-bold text-zinc-700 dark:text-zinc-200">{{ __('Cost per Lead') }}</flux:table.column>
                            </flux:table.columns>
                            <flux:table.rows>
                                @foreach ($this->sourceCostSummary['rows'] as $row)
                                    <flux:table.row
                                        wire:key="source-cost-{{ $row['source']->id }}"
                                        @class([
                                            'border-b border-zinc-200/60 dark:border-zinc-800/60 transition-colors',
                                            'bg-white dark:bg-zinc-900' => $loop->even,
                                            'bg-zinc-50/90 dark:bg-zinc-950/60' => $loop->odd,
                                        ])
                                    >
                                        <flux:table.cell class="font-semibold text-zinc-900 dark:text-white">{{ $row['source']->name }}</flux:table.cell>
                                        <flux:table.cell class="font-medium text-zinc-800 dark:text-zinc-200">{{ $row['currency']->code }} {{ number_format((float) $row['total_cost'], 2) }}</flux:table.cell>
                                        <flux:table.cell><flux:badge size="sm" color="zinc" class="font-bold">{{ $row['lead_count'] }}</flux:badge></flux:table.cell>
                                        <flux:table.cell class="font-semibold text-zinc-900 dark:text-white">
                                            {{ $row['cost_per_lead'] !== null ? $row['currency']->code.' '.number_format($row['cost_per_lead'], 2) : __('No leads in this period') }}
                                        </flux:table.cell>
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    </div>
                </div>

                @if ($this->sourceCostSummary['totals_by_currency']->count() > 1)
                    <flux:text variant="subtle" class="mt-3 block">
                        {{ __('Total (never blended across currencies): :totals', [
                            'totals' => $this->sourceCostSummary['totals_by_currency']->map(fn ($t) => $t['currency']->code.' '.number_format((float) $t['total'], 2))->implode(' · '),
                        ]) }}
                    </flux:text>
                @endif
            @endif
        </flux:card>

        <flux:card>
            <flux:heading size="lg" class="mb-4">{{ __('Assignment / Team Performance') }}</flux:heading>

            {{-- Mobile Card Stack (sm:hidden) --}}
            <div class="sm:hidden rounded-xl border border-zinc-200/90 bg-zinc-100/80 p-2.5 shadow-inner dark:border-zinc-800 dark:bg-zinc-950/80">
                <div class="flex flex-col gap-2">
                    @forelse ($this->assignmentBreakdown as $row)
                        <div wire:key="assignee-card-{{ $row['assignee']?->id ?? 'unassigned' }}" class="flex items-center justify-between gap-2 rounded-lg border border-zinc-200/90 bg-white p-3 shadow-2xs dark:border-zinc-800 dark:bg-zinc-900">
                            <span class="text-xs sm:text-sm font-semibold text-zinc-900 dark:text-white">
                                {{ $row['assignee']?->name ?? __('Unassigned') }}
                            </span>
                            <flux:badge size="sm" color="zinc" class="font-bold">
                                {{ trans_choice(':count lead|:count leads', $row['count'], ['count' => $row['count']]) }}
                            </flux:badge>
                        </div>
                    @empty
                        <div class="flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-zinc-300 bg-white p-6 text-center dark:border-zinc-700 dark:bg-zinc-900">
                            <flux:icon.inbox class="size-6 text-zinc-400" />
                            <flux:text variant="subtle" class="text-xs">{{ __('No leads created in this date range.') }}</flux:text>
                        </div>
                    @endforelse
                </div>
            </div>

            {{-- Desktop / Tablet Table View with distinct background --}}
            <div class="hidden sm:block">
                <div class="rounded-xl border border-zinc-200/90 bg-zinc-100/80 p-1.5 shadow-inner dark:border-zinc-800/90 dark:bg-zinc-950/80">
                    <flux:table container:class="!bg-transparent !border-0 !shadow-none">
                        <flux:table.columns class="bg-zinc-200/70 dark:bg-zinc-800/70 border-b border-zinc-200 dark:border-zinc-700">
                            <flux:table.column class="font-bold text-zinc-700 dark:text-zinc-200">{{ __('Assignee') }}</flux:table.column>
                            <flux:table.column class="font-bold text-zinc-700 dark:text-zinc-200">{{ __('Leads') }}</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @forelse ($this->assignmentBreakdown as $row)
                                <flux:table.row
                                    wire:key="assignee-{{ $row['assignee']?->id ?? 'unassigned' }}"
                                    @class([
                                        'border-b border-zinc-200/60 dark:border-zinc-800/60 transition-colors',
                                        'bg-white dark:bg-zinc-900' => $loop->even,
                                        'bg-zinc-50/90 dark:bg-zinc-950/60' => $loop->odd,
                                    ])
                                >
                                    <flux:table.cell class="font-semibold text-zinc-900 dark:text-white">{{ $row['assignee']?->name ?? __('Unassigned') }}</flux:table.cell>
                                    <flux:table.cell><flux:badge size="sm" color="zinc" class="font-bold">{{ $row['count'] }}</flux:badge></flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row class="bg-white dark:bg-zinc-900">
                                    <flux:table.cell colspan="2">
                                        <flux:text variant="subtle">{{ __('No leads created in this date range.') }}</flux:text>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                </div>
            </div>
        </flux:card>
    @endif
</div>



