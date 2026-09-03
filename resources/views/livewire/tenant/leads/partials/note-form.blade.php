@php $compact ??= false; @endphp

<flux:card>
    <flux:heading size="{{ $compact ? 'sm' : 'lg' }}" class="{{ $compact ? 'mb-2' : 'mb-4' }}">{{ __('Add Note') }}</flux:heading>
    <form wire:submit="addNote" class="flex flex-col gap-3">
        <flux:textarea wire:model="newNote" :label="__('Note')" :rows="$compact ? 1 : 2" />
        <div>
            <flux:button type="submit" variant="primary" size="sm">{{ __('Add Note') }}</flux:button>
        </div>
    </form>
</flux:card>
