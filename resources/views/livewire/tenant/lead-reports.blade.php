@push('breadcrumbs')
    <x-breadcrumbs :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard')],
        ['label' => __('Leads'), 'href' => route('tenant-leads.index')],
        ['label' => __('Reports & Analytics')],
    ]" />
@endpush

<div class="flex flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8 overflow-y-auto min-h-0">
    <div>
        <flux:heading size="xl" class="hidden lg:block">{{ __('Reports & Analytics') }}</flux:heading>
        <flux:text variant="subtle">{{ __('Tenant-wide lead reporting for the selected date range.') }}</flux:text>
    </div>

    <div class="flex flex-wrap items-end gap-3">
        <x-date-picker wire:model.live="fromDate" :label="__('From Date')" :placeholder="__('From Date')" :max="$toDate ?: now()->toDateString()" />
        <x-date-picker wire:model.live="toDate" :label="__('To Date')" :placeholder="__('To Date')" :min="$fromDate" :max="now()->toDateString()" />
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

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Date') }}</flux:table.column>
                    <flux:table.column>{{ __('Leads Created') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($this->dailyBreakdown as $day)
                        <flux:table.row wire:key="daily-{{ $day['date'] }}">
                            <flux:table.cell>{{ \Illuminate\Support\Carbon::parse($day['date'])->toFormattedDateString() }}</flux:table.cell>
                            <flux:table.cell>{{ $day['count'] }}</flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="2">
                                <flux:text variant="subtle">{{ __('No leads created in this date range.') }}</flux:text>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>

            {{ $this->dailyBreakdown->links() }}
        </flux:card>

        <flux:card>
            <flux:heading size="lg" class="mb-4">{{ __('Status Distribution') }}</flux:heading>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column>{{ __('Leads') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($this->statusBreakdown as $row)
                        @php $status = $this->tenantStatusesById[$row['status']] ?? null @endphp
                        <flux:table.row wire:key="status-{{ $row['status'] }}">
                            <flux:table.cell>
                                @if ($status)
                                    <flux:badge size="sm" :color="$status->color">{{ $status->name }}</flux:badge>
                                @else
                                    {{ $row['status'] }}
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>{{ $row['count'] }}</flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="2">
                                <flux:text variant="subtle">{{ __('No leads created in this date range.') }}</flux:text>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </flux:card>

        <flux:card>
            <flux:heading size="lg" class="mb-4">{{ __('Source Performance') }}</flux:heading>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Source') }}</flux:table.column>
                    <flux:table.column>{{ __('Leads') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($this->sourceBreakdown as $row)
                        <flux:table.row wire:key="source-{{ $row['source']->id }}">
                            <flux:table.cell>
                                {{ $row['source']->name }}
                                @unless ($row['source']->is_active)
                                    <flux:badge size="sm" color="zinc">{{ __('Inactive') }}</flux:badge>
                                @endunless
                            </flux:table.cell>
                            <flux:table.cell>{{ $row['count'] }}</flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="2">
                                <flux:text variant="subtle">{{ __('No leads created in this date range.') }}</flux:text>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </flux:card>

        <flux:card>
            <div class="mb-4 flex items-start justify-between gap-3">
                <flux:heading size="lg">{{ __('Cost per Lead') }}</flux:heading>
                <flux:button size="sm" variant="ghost" :href="route('tenant-leads.source-costs')" wire:navigate>
                    {{ __('Manage Source Costs') }}
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
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Source') }}</flux:table.column>
                        <flux:table.column>{{ __('Total Cost') }}</flux:table.column>
                        <flux:table.column>{{ __('Leads') }}</flux:table.column>
                        <flux:table.column>{{ __('Cost per Lead') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($this->sourceCostSummary['rows'] as $row)
                            <flux:table.row wire:key="source-cost-{{ $row['source']->id }}">
                                <flux:table.cell>{{ $row['source']->name }}</flux:table.cell>
                                <flux:table.cell>{{ $row['currency']->code }} {{ number_format((float) $row['total_cost'], 2) }}</flux:table.cell>
                                <flux:table.cell>{{ $row['lead_count'] }}</flux:table.cell>
                                <flux:table.cell>
                                    {{ $row['cost_per_lead'] !== null ? $row['currency']->code.' '.number_format($row['cost_per_lead'], 2) : __('No leads in this period') }}
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>

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

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Assignee') }}</flux:table.column>
                    <flux:table.column>{{ __('Leads') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($this->assignmentBreakdown as $row)
                        <flux:table.row wire:key="assignee-{{ $row['assignee']?->id ?? 'unassigned' }}">
                            <flux:table.cell>{{ $row['assignee']?->name ?? __('Unassigned') }}</flux:table.cell>
                            <flux:table.cell>{{ $row['count'] }}</flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="2">
                                <flux:text variant="subtle">{{ __('No leads created in this date range.') }}</flux:text>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </flux:card>
    @endif
</div>
