@php
    // Rendered as part of App\Livewire\Tenant\Leads\Show's own view tree
    // (included from both show.blade.php and show-panel.blade.php), so
    // $this is the Show component instance — same "call $this->x directly
    // in a shared partial" pattern lead-tags-manager.blade.php/score-
    // breakdown.blade.php already use.
    $canUpdate = $this->canUpdate;
    $canDelete = $this->canDelete;
@endphp

<flux:card class="flex flex-col gap-4">
    <div class="flex items-center justify-between">
        <flux:heading size="lg">{{ __('Deals') }}</flux:heading>
        @if ($canUpdate)
            <flux:button size="sm" variant="primary" icon="plus" wire:click="createDeal">{{ __('New Deal') }}</flux:button>
        @endif
    </div>

    @forelse ($this->deals as $deal)
        <div wire:key="deal-row-{{ $deal->id }}" class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <flux:text variant="strong">{{ $deal->name }}</flux:text>
                        <flux:badge size="sm" :color="$deal->status->color()">{{ $deal->status->label() }}</flux:badge>
                        {{-- Display-only marker — see Lead::currentDeal()'s
                        docblock: this never hides or deactivates any other
                        Deal, it's purely "which one to show first if you
                        only look at one". --}}
                        @if ($deal->id === $this->currentDealId)
                            <flux:badge size="sm" variant="pill" color="zinc">{{ __('Current') }}</flux:badge>
                        @endif
                    </div>

                    <flux:text size="sm" variant="subtle" class="flex flex-wrap items-center gap-x-3 gap-y-0.5 mt-1">
                        @if ($deal->value !== null)
                            <span>{{ $deal->currency?->code }} {{ number_format((float) $deal->value, 2) }}</span>
                        @endif
                        @if ($deal->probability !== null)
                            <span>{{ $deal->probability }}%</span>
                        @endif
                        @if ($deal->expected_close_date)
                            <span>{{ __('Exp. close :date', ['date' => $deal->expected_close_date->format('M j, Y')]) }}</span>
                        @endif
                        @if ($deal->actual_close_date)
                            <span>{{ __('Closed :date', ['date' => $deal->actual_close_date->format('M j, Y')]) }}</span>
                        @endif
                        <span>{{ __('Owner: :name', ['name' => $deal->owner?->name ?? __('Unassigned')]) }}</span>
                    </flux:text>

                    @if ($deal->notes)
                        <flux:text size="sm" class="mt-1 block">{{ $deal->notes }}</flux:text>
                    @endif
                </div>

                @if ($canUpdate || $canDelete)
                    <div class="flex shrink-0 flex-wrap items-center gap-1.5">
                        @if ($canUpdate)
                            <flux:button size="xs" wire:click="editDeal('{{ $deal->id }}')">{{ __('Edit') }}</flux:button>

                            @if ($deal->status->value === 'open')
                                <flux:button size="xs" variant="primary" color="green" wire:click="changeDealStatus('{{ $deal->id }}', 'won')">{{ __('Mark Won') }}</flux:button>
                                <flux:button size="xs" variant="danger" wire:click="changeDealStatus('{{ $deal->id }}', 'lost')">{{ __('Mark Lost') }}</flux:button>
                            @else
                                {{-- Won and Lost can only ever return to Open
                                here — never swap directly into each other,
                                see App\Enums\DealStatus::canTransitionTo(). --}}
                                <flux:button size="xs" wire:click="changeDealStatus('{{ $deal->id }}', 'open')">{{ __('Reopen') }}</flux:button>
                            @endif
                        @endif

                        @if ($canDelete)
                            <flux:button size="xs" variant="danger" icon="trash" aria-label="{{ __('Delete deal') }}" wire:click="confirmDeleteDeal('{{ $deal->id }}')" />
                        @endif
                    </div>
                @endif
            </div>
        </div>
    @empty
        <flux:text variant="subtle">{{ __('No deals yet for this lead.') }}</flux:text>
    @endforelse

    <flux:modal wire:model.self="showDealModal" :dismissible="false" class="md:w-[32rem]">
        <form wire:submit="saveDeal" class="flex flex-col gap-4">
            <flux:heading size="lg">{{ $editingDealId ? __('Edit Deal') : __('New Deal') }}</flux:heading>

            <flux:input wire:model="dealName" :label="__('Name')" placeholder="{{ __('e.g. Initial Package') }}" required />

            <div class="flex flex-col gap-4 sm:flex-row">
                <flux:input wire:model="dealValue" type="number" step="0.01" min="0" :label="__('Value')" placeholder="0.00" class="sm:flex-1" />
                @php
                    $dealCurrencyOptions = collect($this->tenantCurrencies)->map(fn ($c) => ['value' => (string) $c->id, 'label' => $c->code])->toArray();
                @endphp
                <x-dropdown-select wire:model="dealCurrencyId" :label="__('Currency')" :options="$dealCurrencyOptions" class="sm:flex-1" />
            </div>

            <flux:input wire:model="dealProbability" type="number" min="0" max="100" :label="__('Probability (%)')" />

            <x-date-picker wire:model="dealExpectedCloseDate" :label="__('Expected Close Date')" :placeholder="__('Expected Close Date')" />

            <x-dropdown-select wire:model="dealOwnerId" :label="__('Owner')" :placeholder="__('Unassigned')" :options="$this->dealOwnerOptions()" />

            <flux:textarea wire:model="dealNotes" :label="__('Notes')" rows="2" />

            <div class="flex justify-end gap-2">
                <flux:button type="button" wire:click="$set('showDealModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <x-confirm-modal
        wire:model.self="showDeleteDealModal"
        :title="__('Delete this deal?')"
        :description="__('This removes the deal and its full status history. This cannot be undone.')"
        confirm-action="deleteDealConfirmed"
        :confirm-label="__('Delete')"
    />
</flux:card>
