{{-- Full-page-only merged card: partials/status-form.blade.php and
partials/assignment-form.blade.php stay separate (their own cards, their
own buttons) on the Kanban flyout — this combines both into one form with
one submit button, wired to Show::updateStatusAndAssignment(). --}}
<flux:card class="flex flex-col gap-4">
    <flux:heading size="lg">{{ __('Status & Assignment') }}</flux:heading>

    <form wire:submit="updateStatusAndAssignment" class="flex flex-col gap-3">
        @if ($this->canUpdate)
            @php
                $statusOpts = collect($statusOptions)->map(fn ($o) => ['value' => (string) $o->id, 'label' => $o->label()])->toArray();
                $priorityOpts = collect($priorityOptions)->map(fn ($o) => ['value' => $o, 'label' => ucfirst($o)])->toArray();
            @endphp
            <x-dropdown-select wire:model="status" :label="__('Status')" :options="$statusOpts" />
            <x-dropdown-select wire:model="priority" :label="__('Priority')" :options="$priorityOpts" />
        @endif

        @if ($this->canAssign)
            @php
                $assigneeOpts = array_merge(
                    [['value' => '', 'label' => __('Unassigned')]],
                    collect($assignableUsers)->map(fn ($u) => ['value' => (string) $u->id, 'label' => $u->name])->toArray()
                );
            @endphp
            <x-dropdown-select wire:model="assigneeId" :label="__('Assigned To')" :placeholder="__('Unassigned')" :options="$assigneeOpts" />
        @endif

        <div>
            <flux:button type="submit" variant="primary" size="sm">{{ __('Update') }}</flux:button>
        </div>
    </form>
</flux:card>
