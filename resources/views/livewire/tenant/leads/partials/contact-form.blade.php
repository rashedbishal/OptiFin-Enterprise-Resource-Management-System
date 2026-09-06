@php
    // Defaults preserve the Kanban flyout's existing unconditional-form
    // behavior (show-panel.blade.php includes this with no props at all):
    // editable stays true and no Edit/Cancel link ever renders there.
    $editable = $editable ?? true;
    $canEdit = $canEdit ?? false;
@endphp

<flux:card>
    <div class="mb-4 flex items-center justify-between gap-2">
        <flux:heading size="lg">{{ __('Contact') }}</flux:heading>
        @if ($canEdit && ! $editable)
            <flux:link as="button" type="button" variant="subtle" class="text-xs" wire:click="startEditingContact">{{ __('Edit') }}</flux:link>
        @endif
    </div>

    @if ($editable)
        <form wire:submit="updateContact" class="flex flex-col gap-3">
            <flux:input wire:model="leadName" :label="__('Contact Person')" required />

            <div class="grid grid-cols-2 gap-3">
                <flux:input wire:model="leadPhone" type="tel" :label="__('Phone / WhatsApp')" />
                <flux:input wire:model="leadEmail" type="email" :label="__('Email Address')" />
            </div>
            <div class="flex gap-2">
                <flux:button type="submit" variant="primary" size="sm">{{ __('Save') }}</flux:button>
                @if ($canEdit)
                    <flux:button type="button" size="sm" wire:click="cancelEditingContact">{{ __('Cancel') }}</flux:button>
                @endif
            </div>
        </form>
    @else
        <div class="flex flex-col gap-3">
            <div>
                <flux:text size="sm" variant="subtle">{{ __('Contact Person') }}</flux:text>
                <flux:text class="block break-words">{{ $lead->name }}</flux:text>
            </div>
            @if ($lead->phone)
                <div>
                    <flux:text size="sm" variant="subtle">{{ __('Phone / WhatsApp') }}</flux:text>
                    <flux:text class="block">{{ $lead->phone }}</flux:text>
                </div>
            @endif
            @if ($lead->email)
                <div>
                    <flux:text size="sm" variant="subtle">{{ __('Email Address') }}</flux:text>
                    <flux:text class="block break-all">{{ $lead->email }}</flux:text>
                </div>
            @endif
        </div>
    @endif
</flux:card>
