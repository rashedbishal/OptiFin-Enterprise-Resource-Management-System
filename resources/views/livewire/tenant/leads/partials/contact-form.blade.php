<flux:card class="h-full">
    <flux:heading size="lg" class="mb-4">{{ __('Contact') }}</flux:heading>
    <form wire:submit="updateContact" class="flex h-full flex-col gap-3">
        <flux:input wire:model="leadName" :label="__('Contact Person')" required />

        <div class="grid grid-cols-2 gap-3">
            <flux:input wire:model="leadPhone" type="tel" :label="__('Phone / WhatsApp')" />
            <flux:input wire:model="leadEmail" type="email" :label="__('Email Address')" />
        </div>
        <div class="mt-auto pt-3">
            <flux:button type="submit" variant="primary" size="sm">{{ __('Save') }}</flux:button>
        </div>
    </form>
</flux:card>
