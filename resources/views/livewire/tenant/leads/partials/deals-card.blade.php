@php
    // Rendered as part of App\Livewire\Tenant\Leads\Show's own view tree
    // (included from both show.blade.php and show-panel.blade.php), so
    // $this is the Show component instance — same "call $this->x directly
    // in a shared partial" pattern lead-tags-manager.blade.php/score-
    // breakdown.blade.php already use.
    $canUpdate = $this->canUpdate;
    $canDelete = $this->canDelete;
@endphp

<flux:card class="h-full flex flex-col gap-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <flux:heading size="lg">{{ __('Deals') }}</flux:heading>
        @if ($canUpdate)
            <flux:button size="sm" variant="primary" icon="plus" wire:click="createDeal">{{ __('New Deal') }}</flux:button>
        @endif
    </div>

    <div data-test="lead-deals-list" class="min-h-0 flex flex-1 flex-col gap-3.5 overflow-y-auto overscroll-contain pe-1">
        @forelse ($this->deals as $deal)
            <div wire:key="deal-row-{{ $deal->id }}" class="flex flex-col gap-3.5 rounded-xl border border-zinc-200/90 bg-zinc-50 p-3.5 sm:p-4 shadow-2xs dark:border-zinc-700/80 dark:bg-zinc-900/80">
            <div class="flex min-w-0 flex-col gap-1.5">
                <div class="flex flex-wrap items-center gap-1.5">
                    <flux:text variant="strong" class="text-base font-semibold text-zinc-900 dark:text-white">{{ $deal->name }}</flux:text>
                    <flux:badge size="sm" :color="$deal->status->color()">{{ $deal->status->label() }}</flux:badge>
                    {{-- Display-only marker — see Lead::currentDeal()'s
                    docblock: this never hides or deactivates any other
                    Deal, it's purely "which one to show first if you
                    only look at one". --}}
                    @if ($deal->id === $this->currentDealId)
                        <flux:badge size="sm" variant="pill" color="zinc">{{ __('Current') }}</flux:badge>
                    @endif
                </div>

                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mt-1 text-xs sm:text-sm font-semibold text-zinc-800 dark:text-zinc-200">
                    @if ($deal->value !== null)
                        <span class="font-bold text-emerald-700 dark:text-emerald-400">{{ $deal->currency?->code }} {{ number_format((float) $deal->value, 2) }}</span>
                    @endif
                    @if ($deal->probability !== null)
                        <span class="font-semibold text-zinc-800 dark:text-zinc-200">{{ $deal->probability }}% {{ __('win prob.') }}</span>
                    @endif
                    @if ($deal->expected_close_date)
                        <span class="font-semibold text-zinc-800 dark:text-zinc-200">{{ __('Exp. close: :date', ['date' => $deal->expected_close_date->format('M j, Y')]) }}</span>
                    @endif
                    @if ($deal->actual_close_date)
                        <span class="font-semibold text-zinc-800 dark:text-zinc-200">{{ __('Closed: :date', ['date' => $deal->actual_close_date->format('M j, Y')]) }}</span>
                    @endif
                    <span class="font-semibold text-zinc-800 dark:text-zinc-200">{{ __('Owner: :name', ['name' => $deal->owner?->name ?? __('Unassigned')]) }}</span>
                </div>

                @if ($deal->notes)
                    <div class="mt-1 block rounded-lg bg-white/95 p-2.5 text-xs sm:text-sm font-semibold text-zinc-900 dark:bg-zinc-800 dark:text-zinc-100 border border-zinc-200 dark:border-zinc-700 shadow-2xs">
                        {{ $deal->notes }}
                    </div>
                @endif
            </div>

            @if ($canUpdate || $canDelete)
                <div class="flex flex-col gap-2 border-t border-zinc-200/80 pt-3 dark:border-zinc-700/80">
                    {{-- Top Row: Status transition actions with subtle identity color grading --}}
                    @if ($canUpdate && $deal->status->value === 'open')
                        <div class="grid grid-cols-2 gap-2">
                            <flux:button size="sm" icon="check" wire:click="changeDealStatus('{{ $deal->id }}', 'won')" class="w-full font-semibold !bg-emerald-50 hover:!bg-emerald-100/90 !text-emerald-800 dark:!bg-emerald-950/50 dark:hover:!bg-emerald-900/70 dark:!text-emerald-300 !border !border-emerald-200/90 dark:!border-emerald-800/60 shadow-2xs transition-colors cursor-pointer">
                                {{ __('Mark Won') }}
                            </flux:button>
                            <flux:button size="sm" icon="x-mark" wire:click="changeDealStatus('{{ $deal->id }}', 'lost')" class="w-full font-semibold !bg-rose-50 hover:!bg-rose-100/90 !text-rose-800 dark:!bg-rose-950/50 dark:hover:!bg-rose-900/70 dark:!text-rose-300 !border !border-rose-200/90 dark:!border-rose-800/60 shadow-2xs transition-colors cursor-pointer">
                                {{ __('Mark Lost') }}
                            </flux:button>
                        </div>
                    @elseif ($canUpdate)
                        <div>
                            <flux:button size="sm" icon="arrow-path" wire:click="changeDealStatus('{{ $deal->id }}', 'open')" class="w-full font-semibold !bg-sky-50 hover:!bg-sky-100/90 !text-sky-800 dark:!bg-sky-950/50 dark:hover:!bg-sky-900/70 dark:!text-sky-300 !border !border-sky-200/90 dark:!border-sky-800/60 shadow-2xs transition-colors cursor-pointer">
                                {{ __('Reopen Deal') }}
                            </flux:button>
                        </div>
                    @endif

                    {{-- Bottom Row: Edit and Delete buttons with subtle identity color grading --}}
                    @if ($canUpdate || $canDelete)
                        <div @class([
                            'grid gap-2',
                            'grid-cols-2' => $canUpdate && $canDelete,
                            'grid-cols-1' => ! ($canUpdate && $canDelete),
                        ])>
                            @if ($canUpdate)
                                <flux:button size="sm" icon="pencil-square" wire:click="editDeal('{{ $deal->id }}')" class="w-full font-semibold !bg-indigo-50 hover:!bg-indigo-100/90 !text-indigo-800 dark:!bg-indigo-950/50 dark:hover:!bg-indigo-900/70 dark:!text-indigo-300 !border !border-indigo-200/90 dark:!border-indigo-800/60 shadow-2xs transition-colors cursor-pointer">
                                    {{ __('Edit') }}
                                </flux:button>
                            @endif

                            @if ($canDelete)
                                <flux:button size="sm" icon="trash" aria-label="{{ __('Delete deal') }}" wire:click="confirmDeleteDeal('{{ $deal->id }}')" class="w-full font-semibold !bg-red-50 hover:!bg-red-100/90 !text-red-800 dark:!bg-red-950/50 dark:hover:!bg-red-900/70 dark:!text-red-300 !border !border-red-200/90 dark:!border-red-800/60 shadow-2xs transition-colors cursor-pointer">
                                    {{ __('Delete') }}
                                </flux:button>
                            @endif
                        </div>
                    @endif
                </div>
            @endif
            </div>
        @empty
            <flux:text class="font-medium text-zinc-600 dark:text-zinc-400">{{ __('No deals yet for this lead.') }}</flux:text>
        @endforelse
    </div>

    <flux:modal wire:model.self="showDealModal" :dismissible="false" class="md:w-[32rem]">
        <form wire:submit="saveDeal" class="flex flex-col gap-4">
            <flux:heading size="lg">{{ $editingDealId ? __('Edit Deal') : __('New Deal') }}</flux:heading>

            <flux:input wire:model="dealName" :label="__('Name')" placeholder="{{ __('e.g. Initial Package') }}" required />

            <div class="flex flex-col gap-4 sm:flex-row">
                <flux:input wire:model="dealValue" type="number" step="0.01" min="0" :label="__('Value')" placeholder="0.00" class="sm:flex-1" />
                @php
                    $dealCurrencyOptions = collect($this->tenantCurrencies)->map(fn ($c) => ['value' => (string) $c->id, 'label' => $c->symbolLabel().' — '.$c->name, 'short' => $c->symbolLabel()])->toArray();
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
