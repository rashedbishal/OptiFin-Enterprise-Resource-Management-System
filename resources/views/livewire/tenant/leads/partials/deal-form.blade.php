<flux:card>
    <flux:heading size="lg" class="mb-4">{{ __('Deal') }}</flux:heading>
    @if ($editable)
        <form wire:submit="updateDeal" class="flex flex-col gap-3">
            <div class="grid grid-cols-2 gap-3">
                <flux:input wire:model="leadServiceInterest" :label="__('Service Interested')" class="{{ $isManual ? '' : 'col-span-2' }}" />

                @if ($isManual)
                    <flux:input wire:model="leadSource" :label="__('Lead Source')" placeholder="{{ __('e.g. Referral, Facebook Ad, Trade Show') }}" />
                @endif
            </div>

            {{-- Paired two-per-row instead of one long stack: cuts this
            card's height roughly in half so it stops being the tallest card
            in the panel by a wide margin. --}}
            <div class="grid grid-cols-2 gap-3">
                <flux:input.group :label="__('Est. Budget')">
                    <flux:input.group.prefix class="!px-2">
                        <select wire:model="leadEstimatedBudgetCurrencyId" class="cursor-pointer border-0 bg-transparent p-0 pe-5 text-sm font-medium focus:ring-0">
                            @foreach ($this->tenantCurrencies as $currency)
                                <option value="{{ $currency->id }}">{{ $currency->code }}</option>
                            @endforeach
                        </select>
                    </flux:input.group.prefix>
                    <flux:input wire:model="leadEstimatedBudget" type="number" step="0.01" min="0" placeholder="0.00" />
                </flux:input.group>

                <flux:input.group :label="__('Deal Value')">
                    <flux:input.group.prefix class="!px-2">
                        <select wire:model="leadDealValueCurrencyId" class="cursor-pointer border-0 bg-transparent p-0 pe-5 text-sm font-medium focus:ring-0">
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

                <flux:input wire:model="leadNextFollowUpAt" type="date" min="{{ now()->toDateString() }}" :label="__('Next Follow-up Date')" />

                <flux:input wire:model="leadMeetingDate" type="date" min="{{ now()->toDateString() }}" :label="__('Meeting Date')" />
            </div>

            <div>
                <flux:button type="submit" variant="primary" size="sm">{{ __('Save') }}</flux:button>
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
                        <flux:text class="block">{{ $lead->next_follow_up_at->format('M j, Y') }}</flux:text>
                    </div>
                @endif
                @if ($lead->meeting_date)
                    <div>
                        <flux:text size="sm" variant="subtle">{{ __('Meeting Date') }}</flux:text>
                        <flux:text class="block">{{ $lead->meeting_date->format('M j, Y') }}</flux:text>
                    </div>
                @endif
            </div>
        @else
            <flux:text variant="subtle">{{ __('No deal details yet.') }}</flux:text>
        @endif
    @endif
</flux:card>
