<flux:card>
    <flux:heading size="lg" class="mb-4">{{ __('Assignment') }}</flux:heading>
    <form wire:submit="assign" class="flex flex-col gap-3">
        @php
            $assigneeOpts = array_merge(
                [['value' => '', 'label' => __('Unassigned')]],
                collect($assignableUsers)->map(fn($u) => ['value' => (string) $u->id, 'label' => $u->name])->toArray()
            );
        @endphp
        <x-dropdown-select wire:model="assigneeId" :label="__('Assigned To')" :placeholder="__('Unassigned')" :options="$assigneeOpts" />
        <div>
            <flux:button type="submit" variant="primary" size="sm">{{ __('Assign') }}</flux:button>
        </div>
    </form>
</flux:card>
