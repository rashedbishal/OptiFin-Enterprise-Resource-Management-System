@php
    // canEdit defaults false so the Kanban flyout's existing call site
    // (which never passes it) never renders the Edit/Cancel link — its
    // form is simply always shown whenever canUpdate is true, with no
    // read-only state of its own to toggle back to.
    $canEdit = $canEdit ?? false;
@endphp

<flux:card class="flex flex-col gap-4">
    <div class="flex items-center justify-between gap-2 border-b border-zinc-100 pb-3 dark:border-zinc-800">
        <div class="flex items-center gap-2">
            <div class="flex size-7 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
                <flux:icon.banknotes variant="micro" class="size-4" />
            </div>
            <flux:heading size="lg" class="font-semibold">{{ __('Deal & Pipeline Information') }}</flux:heading>
        </div>
        @if ($canEdit && ! $editable)
            <flux:button size="xs" variant="subtle" icon="pencil-square" wire:click="startEditingDeal" class="cursor-pointer font-semibold">
                {{ __('Edit') }}
            </flux:button>
        @endif
    </div>

    @if ($editable)
        <form wire:submit="updateDeal" class="flex flex-col gap-3">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <flux:input wire:model="leadServiceInterest" :label="__('Service Interested')" class="{{ $isManual ? '' : 'sm:col-span-2' }}" />

                @if ($isManual)
                    <flux:input wire:model="leadSource" :label="__('Lead Source')" placeholder="{{ __('e.g. Referral, Facebook Ad, Trade Show') }}" />
                @endif
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
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

                <div class="grid grid-cols-1 gap-3 sm:col-span-2 sm:grid-cols-2">
                    <flux:field>
                        <flux:label>{{ __('Meeting Date') }}</flux:label>
                        <div class="flex items-center gap-1">
                            <div class="min-w-0 flex-1">
                                <x-datetime-picker wire:model="leadMeetingDate" :placeholder="__('Meeting Date')" :min="now()->toDateString()" />
                            </div>
                            @if ($lead->meeting_date)
                                <flux:tooltip content="{{ __('Cancel meeting') }}">
                                    <flux:button size="sm" variant="ghost" icon="x-mark" aria-label="{{ __('Cancel meeting') }}" wire:click="confirmCancelMeeting" />
                                </flux:tooltip>
                                @if (! today()->lt($lead->meeting_date))
                                    <flux:tooltip content="{{ __('Mark meeting as done') }}">
                                        <flux:button size="sm" variant="primary" icon="check" aria-label="{{ __('Mark meeting as done') }}" wire:click="markMeetingDone" />
                                    </flux:tooltip>
                                @endif
                            @endif
                        </div>
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Next Follow-up Date') }}</flux:label>
                        <div class="flex items-center gap-1">
                            <div class="min-w-0 flex-1">
                                <x-date-picker wire:model="leadNextFollowUpAt" :placeholder="__('Next Follow-up Date')" :min="now()->toDateString()" />
                            </div>
                            @if ($lead->next_follow_up_at)
                                <flux:tooltip content="{{ __('Cancel follow-up') }}">
                                    <flux:button size="sm" variant="ghost" icon="x-mark" aria-label="{{ __('Cancel follow-up') }}" wire:click="confirmCancelFollowUp" />
                                </flux:tooltip>
                                @if (! today()->lt($lead->next_follow_up_at))
                                    <flux:tooltip content="{{ __('Mark follow-up as done') }}">
                                        <flux:button size="sm" variant="primary" icon="check" aria-label="{{ __('Mark follow-up as done') }}" wire:click="markFollowUpDone" />
                                    </flux:tooltip>
                                @endif
                            @endif
                        </div>
                    </flux:field>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2 pt-1">
                <flux:button type="submit" variant="primary" size="sm" class="flex-1 sm:flex-none">{{ __('Save') }}</flux:button>
                @if ($canEdit)
                    <flux:button type="button" size="sm" wire:click="cancelEditingDeal" class="flex-1 sm:flex-none">{{ __('Cancel') }}</flux:button>
                @endif
            </div>
        </form>
    @else
        @php $hasDealDetails = collect([$lead->service_interest, $isManual ? $lead->lead_source : null, $lead->estimated_budget, $lead->deal_value, $lead->win_probability, $lead->next_follow_up_at, $lead->meeting_date])->filter(fn ($value) => $value !== null && $value !== '')->isNotEmpty(); @endphp
        @if ($hasDealDetails)
            <div class="flex flex-col gap-3.5">
                {{-- Service Interest & Lead Source Hero Row --}}
                @if ($lead->service_interest || ($isManual && $lead->lead_source))
                    <div class="flex flex-wrap items-center gap-2.5 rounded-xl border border-zinc-200/80 bg-zinc-50/60 p-3.5 shadow-2xs dark:border-zinc-800 dark:bg-zinc-900/60">
                        @if ($lead->service_interest)
                            <div class="flex items-center gap-2">
                                <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Service:') }}</span>
                                <span class="inline-flex items-center rounded-lg bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300 border border-emerald-200/80 dark:border-emerald-800/60">
                                    {{ $lead->service_interest }}
                                </span>
                            </div>
                        @endif
                        @if ($isManual && $lead->lead_source)
                            <div class="flex items-center gap-2 ms-auto">
                                <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Source:') }}</span>
                                <span class="inline-flex items-center rounded-lg bg-zinc-200/70 px-2.5 py-1 text-xs font-semibold text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200">
                                    {{ $lead->lead_source }}
                                </span>
                            </div>
                        @endif
                    </div>
                @endif

                {{-- Financial Metrics Grid --}}
                <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-3">
                    @if ($lead->estimated_budget !== null)
                        <div class="flex items-center gap-2.5 rounded-xl border border-[#e4d6be] bg-[#f7f5f0] p-3 dark:border-zinc-800 dark:bg-zinc-900">
                            <div class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-950/60 dark:text-blue-400">
                                <flux:icon.calculator variant="micro" class="size-4" />
                            </div>
                            <div class="min-w-0">
                                <span class="block text-[11px] font-medium text-zinc-500 dark:text-zinc-400">{{ __('Est. Budget') }}</span>
                                <span class="text-xs sm:text-sm font-semibold text-zinc-900 dark:text-white">
                                    {{ $lead->estimatedBudgetCurrency?->code }} {{ number_format((float) $lead->estimated_budget, 2) }}
                                </span>
                            </div>
                        </div>
                    @endif

                    @if ($lead->deal_value !== null)
                        <div class="flex items-center gap-2.5 rounded-xl border border-emerald-200/80 bg-emerald-50/40 p-3 dark:border-emerald-800/60 dark:bg-emerald-950/20">
                            <div class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-emerald-100 text-emerald-700 dark:bg-emerald-900/60 dark:text-emerald-300">
                                <flux:icon.currency-dollar variant="micro" class="size-4" />
                            </div>
                            <div class="min-w-0">
                                <span class="block text-[11px] font-medium text-emerald-700 dark:text-emerald-400">{{ __('Deal Value') }}</span>
                                <span class="text-xs sm:text-sm font-bold text-emerald-800 dark:text-emerald-300">
                                    {{ $lead->dealValueCurrency?->code }} {{ number_format((float) $lead->deal_value, 2) }}
                                </span>
                            </div>
                        </div>
                    @endif

                    @if ($lead->win_probability !== null)
                        <div class="flex items-center gap-2.5 rounded-xl border border-[#e4d6be] bg-[#f7f5f0] p-3 dark:border-zinc-800 dark:bg-zinc-900">
                            <div class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-sky-50 text-sky-600 dark:bg-sky-950/60 dark:text-sky-400">
                                <flux:icon.chart-bar variant="micro" class="size-4" />
                            </div>
                            <div class="min-w-0">
                                <span class="block text-[11px] font-medium text-zinc-500 dark:text-zinc-400">{{ __('Win Probability') }}</span>
                                <span class="text-xs sm:text-sm font-semibold text-sky-700 dark:text-sky-400">{{ $lead->win_probability }}%</span>
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Schedule & Next Actions --}}
                @if ($lead->next_follow_up_at || $lead->meeting_date)
                    <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
                        @if ($lead->next_follow_up_at)
                            <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-amber-200/80 bg-amber-50/30 p-3 dark:border-amber-800/50 dark:bg-amber-950/20">
                                <div class="flex items-center gap-2.5 min-w-0">
                                    <div class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-amber-700 dark:bg-amber-900/60 dark:text-amber-300">
                                        <flux:icon.clock variant="micro" class="size-4" />
                                    </div>
                                    <div class="min-w-0">
                                        <span class="block text-[11px] font-medium text-amber-700 dark:text-amber-400">{{ __('Next Follow-up') }}</span>
                                        <span class="text-xs sm:text-sm font-bold text-amber-900 dark:text-amber-200">{{ $lead->next_follow_up_at->format('M j, Y') }}</span>
                                    </div>
                                </div>
                                @if ($canEdit)
                                    <div class="flex items-center gap-1 ms-auto">
                                        <flux:tooltip content="{{ __('Cancel follow-up') }}">
                                            <flux:button size="xs" variant="ghost" icon="x-mark" aria-label="{{ __('Cancel follow-up') }}" wire:click="confirmCancelFollowUp" />
                                        </flux:tooltip>
                                        @if (! today()->lt($lead->next_follow_up_at))
                                            <flux:tooltip content="{{ __('Mark follow-up as done') }}">
                                                <flux:button size="xs" variant="primary" icon="check" aria-label="{{ __('Mark follow-up as done') }}" wire:click="markFollowUpDone" />
                                            </flux:tooltip>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @endif

                        @if ($lead->meeting_date)
                            <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-indigo-200/80 bg-indigo-50/30 p-3 dark:border-indigo-800/50 dark:bg-indigo-950/20">
                                <div class="flex items-center gap-2.5 min-w-0">
                                    <div class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-indigo-100 text-indigo-700 dark:bg-indigo-900/60 dark:text-indigo-300">
                                        <flux:icon.calendar-days variant="micro" class="size-4" />
                                    </div>
                                    <div class="min-w-0">
                                        <span class="block text-[11px] font-medium text-indigo-700 dark:text-indigo-400">{{ __('Meeting Date') }}</span>
                                        <span class="text-xs sm:text-sm font-bold text-indigo-900 dark:text-indigo-200">
                                            {{ $lead->meeting_date->format('M j, Y') }}{{ $lead->meetingTimeFormatted() ? ' at '.$lead->meetingTimeFormatted() : '' }}
                                        </span>
                                    </div>
                                </div>
                                @if ($canEdit)
                                    <div class="flex items-center gap-1 ms-auto">
                                        <flux:tooltip content="{{ __('Cancel meeting') }}">
                                            <flux:button size="xs" variant="ghost" icon="x-mark" aria-label="{{ __('Cancel meeting') }}" wire:click="confirmCancelMeeting" />
                                        </flux:tooltip>
                                        @if (! today()->lt($lead->meeting_date))
                                            <flux:tooltip content="{{ __('Mark meeting as done') }}">
                                                <flux:button size="xs" variant="primary" icon="check" aria-label="{{ __('Mark meeting as done') }}" wire:click="markMeetingDone" />
                                            </flux:tooltip>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        @else
            <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 italic">{{ __('No deal details recorded yet.') }}</p>
        @endif
    @endif
</flux:card>

