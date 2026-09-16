@include('partials.lead-management-breadcrumbs', ['leadManagementTrail' => [['label' => __('Tags')]]])

<div class="flex flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8 overflow-y-auto min-h-0">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl" class="hidden lg:block">{{ __('Lead Tags') }}</flux:heading>
            <flux:text variant="subtle">{{ __('Manage the tags your team can attach to leads from the lead detail page.') }}</flux:text>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="create">{{ __('New Tag') }}</flux:button>
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Color') }}</flux:table.column>
            <flux:table.column>{{ __('Used By') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->tags as $tag)
                <flux:table.row wire:key="lead-tag-{{ $tag->id }}">
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$tag->color">{{ $tag->name }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>{{ ucfirst($tag->color) }}</flux:table.cell>
                    <flux:table.cell>
                        {{ trans_choice(':count lead|:count leads', $tag->leads_count, ['count' => $tag->leads_count]) }}
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="flex justify-end gap-2">
                            <flux:button size="sm" wire:click="edit('{{ $tag->id }}')">{{ __('Edit') }}</flux:button>
                            <flux:button size="sm" variant="danger" wire:click="confirmDelete('{{ $tag->id }}')">
                                {{ __('Delete') }}
                            </flux:button>
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="4">
                        {{ __('No tags yet — create one to start categorizing leads.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal wire:model.self="showModal" :dismissible="false" class="md:w-96">
        <form wire:submit="save" class="flex flex-col gap-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit Tag') : __('New Tag') }}</flux:heading>

            <flux:input wire:model="name" :label="__('Name')" placeholder="{{ __('e.g. VIP') }}" required />

            @php
                $colorOpts = collect($this->colorOptions())->map(fn($c) => ['value' => $c, 'label' => ucfirst($c)])->toArray();
            @endphp
            <x-dropdown-select wire:model="color" :label="__('Color')" :options="$colorOpts" />

            <div class="flex justify-end gap-2">
                <flux:button type="button" wire:click="$set('showModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <x-confirm-modal
        wire:model.self="showDeleteModal"
        :title="__('Delete this tag?')"
        :description="$this->confirmingDeleteUsageCount > 0
            ? trans_choice('This tag is currently used by :count lead. Deleting it will remove the tag from that lead. Continue?|This tag is currently used by :count leads. Deleting it will remove the tag from all :count leads. Continue?', $this->confirmingDeleteUsageCount, ['count' => $this->confirmingDeleteUsageCount])
            : __('This tag is not currently used by any leads.')"
        confirm-action="deleteConfirmed"
        :confirm-label="__('Delete')"
    />
</div>
