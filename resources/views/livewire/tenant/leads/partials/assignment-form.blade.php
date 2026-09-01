<flux:card>
    <flux:heading size="lg" class="mb-4">{{ __('Assignment') }}</flux:heading>
    <form wire:submit="assign" class="flex flex-col gap-3">
        <flux:select wire:model="assigneeId" :label="__('Assigned To')" placeholder="{{ __('Unassigned') }}">
            @foreach ($assignableUsers as $assignableUser)
                <flux:select.option value="{{ $assignableUser->id }}">{{ $assignableUser->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:button type="submit" variant="primary" size="sm">{{ __('Assign') }}</flux:button>
    </form>
</flux:card>
