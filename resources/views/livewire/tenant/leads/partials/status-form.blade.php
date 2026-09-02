<flux:card>
    <flux:heading size="lg" class="mb-4">{{ __('Status') }}</flux:heading>
    <form wire:submit="updateStatus" class="flex flex-col gap-3">
        <flux:select wire:model="status" :label="__('Status')">
            @foreach ($statusOptions as $option)
                <flux:select.option value="{{ $option->id }}">{{ $option->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model="priority" :label="__('Priority')">
            @foreach ($priorityOptions as $option)
                <flux:select.option value="{{ $option }}">{{ ucfirst($option) }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:button type="submit" variant="primary" size="sm">{{ __('Update') }}</flux:button>
    </form>
</flux:card>
