<flux:card class="h-full">
    <flux:heading size="lg" class="mb-4">{{ __('Status') }}</flux:heading>
    <form wire:submit="updateStatus" class="flex h-full flex-col gap-3">
        @php
            $statusOpts = collect($statusOptions)->map(fn($o) => ['value' => (string) $o->id, 'label' => $o->label()])->toArray();
            $priorityOpts = collect($priorityOptions)->map(fn($o) => ['value' => $o, 'label' => ucfirst($o)])->toArray();
        @endphp
        <x-dropdown-select wire:model="status" :label="__('Status')" :options="$statusOpts" />
        <x-dropdown-select wire:model="priority" :label="__('Priority')" :options="$priorityOpts" />
        <div class="mt-auto pt-3">
            <flux:button type="submit" variant="primary" size="sm">{{ __('Update') }}</flux:button>
        </div>
    </form>
</flux:card>
