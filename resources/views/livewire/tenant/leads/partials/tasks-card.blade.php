@php
    // Rendered as part of App\Livewire\Tenant\Leads\Show's own view tree
    // (included from both show.blade.php and show-panel.blade.php), so
    // $this is the Show component instance — same "call $this->x directly
    // in a shared partial" pattern deals-card.blade.php already uses.
    $canUpdate = $this->canUpdate;
@endphp

<flux:card class="flex flex-col gap-4">
    <div class="flex items-center justify-between">
        <flux:heading size="lg">{{ __('Tasks') }}</flux:heading>
        @if ($canUpdate)
            <flux:button size="sm" variant="primary" icon="plus" wire:click="createTask">{{ __('New Task') }}</flux:button>
        @endif
    </div>

    @forelse ($this->tasks as $task)
        @php
            // "Overdue" (this row's own red visual cue) is deliberately a
            // simpler, stricter check than Task::alertActive() (the tenant
            // alert-window calculation the daily sweep/bell notification
            // use) — a task reads as overdue here the moment its due date
            // has actually passed, regardless of whether task_alert_enabled
            // is even on for this tenant.
            $isOverdue = $task->status->value === 'pending' && $task->due_date !== null && $task->due_date->isPast();
        @endphp
        <div wire:key="task-row-{{ $task->id }}" class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <flux:text variant="strong">{{ $task->title }}</flux:text>
                        <flux:badge size="sm" :color="$task->status->color()">{{ $task->status->label() }}</flux:badge>
                        <flux:badge size="sm" :color="$task->priority->color()">{{ $task->priority->label() }}</flux:badge>
                        @if ($isOverdue)
                            <flux:badge size="sm" variant="pill" color="red">{{ __('Overdue') }}</flux:badge>
                        @endif
                        @if ($task->deal)
                            <flux:badge size="sm" variant="pill" color="zinc">{{ __('Deal: :name', ['name' => $task->deal->name]) }}</flux:badge>
                        @endif
                    </div>

                    <flux:text size="sm" variant="subtle" class="flex flex-wrap items-center gap-x-3 gap-y-0.5 mt-1">
                        @if ($task->due_date)
                            <span>
                                {{ __('Due :date', ['date' => $task->due_date->format('M j, Y')]) }}{{ $task->dueTimeFormatted() ? ' '.__('at :time', ['time' => $task->dueTimeFormatted()]) : '' }}
                            </span>
                        @endif
                        @if ($task->closed_at)
                            <span>{{ __('Closed :date', ['date' => $task->closed_at->format('M j, Y')]) }}</span>
                        @endif
                        <span>{{ __('Assignee: :name', ['name' => $task->assignee?->name ?? __('Unassigned')]) }}</span>
                    </flux:text>

                    @if ($task->description)
                        <flux:text size="sm" class="mt-1 block">{{ $task->description }}</flux:text>
                    @endif
                </div>

                @if ($canUpdate || $this->canDeleteTask($task))
                    <div class="flex shrink-0 flex-wrap items-center gap-1.5">
                        @if ($canUpdate)
                            <flux:button size="xs" wire:click="editTask('{{ $task->id }}')">{{ __('Edit') }}</flux:button>

                            @if ($task->status->value === 'pending')
                                <flux:button size="xs" variant="primary" color="green" wire:click="changeTaskStatus('{{ $task->id }}', 'completed')">{{ __('Complete') }}</flux:button>
                                <flux:button size="xs" variant="danger" wire:click="changeTaskStatus('{{ $task->id }}', 'cancelled')">{{ __('Cancel') }}</flux:button>
                            @else
                                {{-- Completed and Cancelled can only ever
                                return to Pending here — never swap directly
                                into each other, see
                                App\Enums\TaskStatus::canTransitionTo(). --}}
                                <flux:button size="xs" wire:click="changeTaskStatus('{{ $task->id }}', 'pending')">{{ __('Reopen') }}</flux:button>
                            @endif
                        @endif

                        @if ($this->canDeleteTask($task))
                            <flux:button size="xs" variant="danger" icon="trash" aria-label="{{ __('Delete task') }}" wire:click="confirmDeleteTask('{{ $task->id }}')" />
                        @endif
                    </div>
                @endif
            </div>
        </div>
    @empty
        <flux:text variant="subtle">{{ __('No tasks yet for this lead.') }}</flux:text>
    @endforelse

    <flux:modal wire:model.self="showTaskModal" :dismissible="false" class="md:w-[32rem]">
        <form wire:submit="saveTask" class="flex flex-col gap-4">
            <flux:heading size="lg">{{ $editingTaskId ? __('Edit Task') : __('New Task') }}</flux:heading>

            <flux:input wire:model="taskTitle" :label="__('Title')" placeholder="{{ __('e.g. Send proposal') }}" required />

            <flux:textarea wire:model="taskDescription" :label="__('Description')" rows="2" />

            <div class="flex flex-col gap-4 sm:flex-row">
                <x-date-picker wire:model="taskDueDate" :label="__('Due Date')" :placeholder="__('Due Date')" class="sm:flex-1" />
                <flux:input wire:model="taskDueTime" type="time" :label="__('Due Time')" class="sm:flex-1" />
            </div>

            <x-dropdown-select wire:model="taskPriority" :label="__('Priority')" :options="collect(\App\Enums\LeadPriority::cases())->map(fn ($p) => ['value' => $p->value, 'label' => $p->label()])->toArray()" />

            <x-dropdown-select wire:model="taskAssignedTo" :label="__('Assignee')" :placeholder="__('Unassigned')" :options="$this->taskAssigneeOptions()" />

            <x-dropdown-select wire:model="taskDealId" :label="__('Link to Deal')" :placeholder="__('No Deal')" :options="$this->taskDealOptions()" />

            <div class="flex justify-end gap-2">
                <flux:button type="button" wire:click="$set('showTaskModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <x-confirm-modal
        wire:model.self="showDeleteTaskModal"
        :title="__('Delete this task?')"
        :description="__('This removes the task and its full status history. This cannot be undone.')"
        confirm-action="deleteTaskConfirmed"
        :confirm-label="__('Delete')"
    />
</flux:card>
