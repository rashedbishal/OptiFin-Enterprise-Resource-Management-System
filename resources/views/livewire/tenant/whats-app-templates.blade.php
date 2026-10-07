@include('partials.lead-management-breadcrumbs', ['leadManagementTrail' => [['label' => __('WhatsApp Templates')]]])

<div class="flex flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8 overflow-y-auto min-h-0">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl" class="hidden lg:block">{{ __('WhatsApp Templates') }}</flux:heading>
            <flux:text variant="subtle">{{ __('Register a WhatsApp template you\'ve already created and had approved in Meta\'s WhatsApp Manager, so your team can send it to re-engage a lead once the 24-hour reply window has closed.') }}</flux:text>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="create">{{ __('New Template') }}</flux:button>
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Language') }}</flux:table.column>
            <flux:table.column>{{ __('Variables') }}</flux:table.column>
            <flux:table.column>{{ __('Source') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->templates as $template)
                <flux:table.row wire:key="whatsapp-template-{{ $template->id }}">
                    <flux:table.cell class="font-mono text-xs sm:text-sm">{{ $template->name }}</flux:table.cell>
                    <flux:table.cell>{{ $template->language }}</flux:table.cell>
                    <flux:table.cell>{{ $template->variable_count }}</flux:table.cell>
                    <flux:table.cell>{{ $template->leadSource->name }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$template->is_active ? 'emerald' : 'zinc'">
                            {{ $template->is_active ? __('Active') : __('Inactive') }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="flex justify-end gap-2">
                            <flux:button size="sm" wire:click="toggleActive('{{ $template->id }}')">
                                {{ $template->is_active ? __('Deactivate') : __('Activate') }}
                            </flux:button>
                            <flux:button size="sm" wire:click="edit('{{ $template->id }}')">{{ __('Edit') }}</flux:button>
                            <flux:button size="sm" variant="danger" wire:click="confirmDelete('{{ $template->id }}')">
                                {{ __('Delete') }}
                            </flux:button>
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6">
                        {{ __('No templates registered yet — add one to be able to re-engage a lead whose reply window has closed.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal wire:model.self="showModal" :dismissible="false" class="md:w-96">
        <form wire:submit="save" class="flex flex-col gap-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit Template') : __('New Template') }}</flux:heading>

            @if ($this->eligibleSources->count() > 1)
                <x-dropdown-select
                    wire:model="sourceId"
                    :label="__('WhatsApp Source')"
                    :options="$this->eligibleSources->map(fn ($source) => ['value' => $source->id, 'label' => $source->name])->toArray()"
                />
            @endif

            <flux:input
                wire:model="name"
                :label="__('Template Name')"
                :description="__('Must match exactly what Meta approved — lowercase letters, numbers, underscores only.')"
                placeholder="order_update"
                required
            />

            <flux:input wire:model="language" :label="__('Language Code')" :description="__('Meta\'s language code, e.g. en_US.')" placeholder="en_US" required />

            <flux:input wire:model="variableCount" type="number" min="0" max="10" :label="__('Body Variables')" :description="__('How many numbered placeholders (1, 2, 3, ...) this template\'s body has.')" required />

            <div class="flex justify-end gap-2">
                <flux:button type="button" wire:click="$set('showModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <x-confirm-modal
        wire:model.self="showDeleteModal"
        :title="__('Delete this template?')"
        :description="__('This only removes it from your catalog here — it does not delete the template from Meta\'s WhatsApp Manager, and any already-sent message using it is unaffected.')"
        confirm-action="deleteConfirmed"
        :confirm-label="__('Delete')"
    />
</div>
