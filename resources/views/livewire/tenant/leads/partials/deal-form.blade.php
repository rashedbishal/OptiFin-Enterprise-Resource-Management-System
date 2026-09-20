@php
    // canEdit defaults false so the Kanban flyout's existing call site
    // (which never passes it) never renders the Edit/Cancel link — its
    // form is simply always shown whenever canUpdate is true, with no
    // read-only state of its own to toggle back to.
    $canEdit = $canEdit ?? false;
@endphp

<flux:card>
    <div class="mb-4 flex items-center justify-between gap-2">
        <flux:heading size="lg">{{ __('Deal') }}</flux:heading>
        @if ($canEdit && ! $editable)
            <flux:link as="button" type="button" variant="subtle" class="text-xs" wire:click="startEditingDeal">{{ __('Edit') }}</flux:link>
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

            <div class="flex flex-wrap items-center gap-2">
                <flux:button type="submit" variant="primary" size="sm" class="flex-1 sm:flex-none">{{ __('Save') }}</flux:button>
                @if ($canEdit)
                    <flux:button type="button" size="sm" wire:click="cancelEditingDeal" class="flex-1 sm:flex-none">{{ __('Cancel') }}</flux:button>
                @endif
            </div>
        </form>
    @else
        @php $hasDealDetails = collect([$lead->service_interest, $isManual ? $lead->lead_source : null, $lead->estimated_budget, $lead->deal_value, $lead->win_probability, $lead->next_follow_up_at, $lead->meeting_date])->filter(fn ($value) => $value !== null && $value !== '')->isNotEmpty(); @endphp
        @if ($hasDealDetails)
            <div class="flex flex-col gap-3">
                @if ($lead->service_interest)
                    <div>
                        <flux:text size="sm" variant="subtle">{{ __('Service Interested') }}</flux:text>
                        <flux:text class="block break-words">{{ $lead->service_interest }}</flux:text>
                    </div>
                @endif
                @if ($isManual && $lead->lead_source)
                    <div>
                        <flux:text size="sm" variant="subtle">{{ __('Lead Source') }}</flux:text>
                        <flux:text class="block break-words">{{ $lead->lead_source }}</flux:text>
                    </div>
                @endif
                @if ($lead->estimated_budget !== null)
                    <div>
                        <flux:text size="sm" variant="subtle">{{ __('Est. Budget') }}</flux:text>
                        <flux:text class="block">{{ $lead->estimatedBudgetCurrency?->code }} {{ number_format((float) $lead->estimated_budget, 2) }}</flux:text>
                    </div>
                @endif
                @if ($lead->deal_value !== null)
                    <div>
                        <flux:text size="sm" variant="subtle">{{ __('Deal Value') }}</flux:text>
                        <flux:text class="block">{{ $lead->dealValueCurrency?->code }} {{ number_format((float) $lead->deal_value, 2) }}</flux:text>
                    </div>
                @endif
                @if ($lead->win_probability !== null)
                    <div>
                        <flux:text size="sm" variant="subtle">{{ __('Win Probability') }}</flux:text>
                        <flux:text class="block">{{ $lead->win_probability }}%</flux:text>
                    </div>
                @endif
                @if ($lead->next_follow_up_at)
                    <div>
                        <flux:text size="sm" variant="subtle">{{ __('Next Follow-up') }}</flux:text>
                        <div class="flex items-center gap-1">
                            <flux:text class="block">{{ $lead->next_follow_up_at->format('M j, Y') }}</flux:text>
                            {{-- Previously only reachable after clicking Edit
                            (this whole block only renders when !$editable),
                            unlike pipeline-attributes-card.blade.php's
                            equivalent for non-manual leads, which always
                            offered these — restores that parity. --}}
                            @if ($canEdit)
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
                    </div>
                @endif
                @if ($lead->meeting_date)
                    <div>
                        <flux:text size="sm" variant="subtle">{{ __('Meeting Date') }}</flux:text>
                        <div class="flex items-center gap-1">
                            <flux:text class="block">
                                {{ $lead->meeting_date->format('M j, Y') }}{{ $lead->meetingTimeFormatted() ? ' at '.$lead->meetingTimeFormatted() : '' }}
                            </flux:text>
                            @if ($canEdit)
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
                    </div>
                @endif
            </div>
        @else
            <flux:text variant="subtle">{{ __('No deal details yet.') }}</flux:text>
        @endif
    @endif
</flux:card>
