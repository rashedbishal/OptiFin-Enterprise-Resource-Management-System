{{-- Feature #6 (Bulk Actions, Phase 1) — shared between leads.blade.php
(Client Admin table) and leads-kanban.blade.php (Client User board), since
both need the same selection toolbar + action modals. Every action
re-derives its real eligible Lead set server-side
(Index::eligibleSelectedLeads()) rather than trusting $selectedLeadIds
directly.

The WHOLE partial (toolbar AND every modal) is gated behind
$this->selectedCount > 0, not just the toolbar strip — a Flux modal's
content stays in the DOM once rendered regardless of its own open/closed
state, so a modal rendered unconditionally would permanently duplicate its
x-dropdown-select's embedded option list (e.g. the tag catalog also shown
by the page's own Tags filter dropdown) even while closed. Confirmed via a
real regression: LeadTagFilterAndDisplayTest's "Gamma appears exactly
once" assertion broke the first time this was rendered unconditionally.
Every openBulk*Modal() method already requires a non-empty selection
before it opens anyway, so nothing is lost by not pre-rendering these
before there's a selection to act on. --}}
@if ($this->selectedCount > 0)
    @php
        $isKanban = $isKanban ?? false;
        $bulkStatusOptionsFormatted = collect($this->statusOptions())->map(fn($opt) => ['value' => (string) $opt->id, 'label' => $opt->label()])->toArray();
        $bulkPriorityOptionsFormatted = collect($this->priorityOptions())->map(fn($opt) => ['value' => $opt, 'label' => ucfirst($opt)])->toArray();
        $bulkAssigneeOptionsFormatted = collect($this->assignableUsers)->map(fn($u) => ['value' => (string) $u->id, 'label' => $u->name])->toArray();
        $bulkTagOptionsFormatted = collect($this->tagOptions())->map(fn($opt) => ['value' => (string) $opt->id, 'label' => $opt->name])->toArray();
    @endphp

    <div @class([
        'sticky bottom-0 z-20 flex flex-wrap items-center gap-2 border-t border-zinc-200 bg-white px-4 py-2.5 shadow-[0_-2px_8px_rgba(0,0,0,0.04)] sm:px-6 lg:px-8 dark:border-zinc-700 dark:bg-zinc-800',
        'absolute inset-x-0 bottom-0 w-full' => $isKanban,
    ])>
        <flux:badge size="sm" color="blue">{{ trans_choice(':count selected', $this->selectedCount, ['count' => $this->selectedCount]) }}</flux:badge>

        @if ($this->canUpdateLeads)
            <flux:button size="sm" wire:click="openBulkStatusModal">{{ __('Change Status') }}</flux:button>
            <flux:button size="sm" wire:click="openBulkPriorityModal">{{ __('Change Priority') }}</flux:button>
        @endif

        @if ($this->isClientAdmin && $this->canUpdateLeads)
            <flux:button size="sm" wire:click="openBulkAssignModal">{{ __('Assign') }}</flux:button>
            <flux:button size="sm" wire:click="bulkUnassign" wire:confirm="{{ __('Unassign all selected leads?') }}">{{ __('Unassign') }}</flux:button>
        @endif

        @if ($this->canUpdateLeads)
            <flux:button size="sm" wire:click="openBulkTagModal('attach')">{{ __('Add Tag') }}</flux:button>
            <flux:button size="sm" wire:click="openBulkTagModal('remove')">{{ __('Remove Tag') }}</flux:button>
        @endif

        @if ($this->canDeleteLeads)
            <flux:button size="sm" variant="danger" wire:click="confirmBulkDelete">{{ __('Delete') }}</flux:button>
        @endif

        <flux:button size="sm" variant="ghost" wire:click="clearSelection" class="ms-auto">{{ __('Clear selection') }}</flux:button>
    </div>

    @if ($this->canUpdateLeads)
        <flux:modal wire:model.self="showBulkStatusModal" class="md:w-96">
            <form wire:submit="applyBulkStatusChange" class="flex flex-col gap-4">
                <div>
                    <flux:heading size="lg">{{ __('Change Status') }}</flux:heading>
                    <flux:text class="mt-1">{{ trans_choice('Move :count selected lead to a new pipeline stage.|Move :count selected leads to a new pipeline stage.', $this->selectedCount, ['count' => $this->selectedCount]) }}</flux:text>
                </div>
                <x-dropdown-select wire:model="bulkStatusId" :label="__('New Status')" :placeholder="__('Select a status')" :options="$bulkStatusOptionsFormatted" />
                @error('bulkStatusId') <flux:error>{{ $message }}</flux:error> @enderror
                <div class="flex justify-end gap-2">
                    <flux:button type="button" wire:click="$set('showBulkStatusModal', false)">{{ __('Cancel') }}</flux:button>
                    <flux:button type="submit" variant="primary">{{ __('Apply') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal wire:model.self="showBulkPriorityModal" class="md:w-96">
            <form wire:submit="applyBulkPriorityChange" class="flex flex-col gap-4">
                <div>
                    <flux:heading size="lg">{{ __('Change Priority') }}</flux:heading>
                    <flux:text class="mt-1">{{ trans_choice('Set priority for :count selected lead.|Set priority for :count selected leads.', $this->selectedCount, ['count' => $this->selectedCount]) }}</flux:text>
                </div>
                <x-dropdown-select wire:model="bulkPriority" :label="__('New Priority')" :placeholder="__('Select a priority')" :options="$bulkPriorityOptionsFormatted" />
                @error('bulkPriority') <flux:error>{{ $message }}</flux:error> @enderror
                <div class="flex justify-end gap-2">
                    <flux:button type="button" wire:click="$set('showBulkPriorityModal', false)">{{ __('Cancel') }}</flux:button>
                    <flux:button type="submit" variant="primary">{{ __('Apply') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal wire:model.self="showBulkTagModal" class="md:w-96">
            <form wire:submit="applyBulkTagAction" class="flex flex-col gap-4">
                <div>
                    <flux:heading size="lg">{{ $bulkTagAction === 'attach' ? __('Add Tag') : __('Remove Tag') }}</flux:heading>
                    <flux:text class="mt-1">
                        {{ $bulkTagAction === 'attach'
                            ? trans_choice('Add a tag to :count selected lead.|Add a tag to :count selected leads.', $this->selectedCount, ['count' => $this->selectedCount])
                            : trans_choice('Remove a tag from :count selected lead.|Remove a tag from :count selected leads.', $this->selectedCount, ['count' => $this->selectedCount]) }}
                    </flux:text>
                </div>
                <x-dropdown-select wire:model="bulkTagId" :label="__('Tag')" :placeholder="__('Select a tag')" :options="$bulkTagOptionsFormatted" />
                @error('bulkTagId') <flux:error>{{ $message }}</flux:error> @enderror
                <div class="flex justify-end gap-2">
                    <flux:button type="button" wire:click="$set('showBulkTagModal', false)">{{ __('Cancel') }}</flux:button>
                    <flux:button type="submit" variant="primary">{{ __('Apply') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    @if ($this->isClientAdmin && $this->canUpdateLeads)
        <flux:modal wire:model.self="showBulkAssignModal" class="md:w-96">
            <form wire:submit="applyBulkAssign" class="flex flex-col gap-4">
                <div>
                    <flux:heading size="lg">{{ __('Assign Leads') }}</flux:heading>
                    <flux:text class="mt-1">{{ trans_choice('Assign :count selected lead to a sales executive.|Assign :count selected leads to a sales executive.', $this->selectedCount, ['count' => $this->selectedCount]) }}</flux:text>
                </div>
                <x-dropdown-select wire:model="bulkAssigneeId" :label="__('Assign To')" :placeholder="__('Select a user')" :options="$bulkAssigneeOptionsFormatted" />
                @error('bulkAssigneeId') <flux:error>{{ $message }}</flux:error> @enderror
                <div class="flex justify-end gap-2">
                    <flux:button type="button" wire:click="$set('showBulkAssignModal', false)">{{ __('Cancel') }}</flux:button>
                    <flux:button type="submit" variant="primary">{{ __('Apply') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    {{-- Bulk delete reuses the page's own single-Lead password-confirmation
    modal (see Index::confirmBulkDelete()/$bulkDeleteMode) rather than a
    second one — no separate modal markup needed here. --}}
@endif
