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
            $canDeleteTask = $this->canDeleteTask($task);
        @endphp
        <div wire:key="task-row-{{ $task->id }}" class="flex flex-col gap-3.5 rounded-xl border border-zinc-200/90 bg-zinc-50 p-3.5 sm:p-4 shadow-2xs dark:border-zinc-700/80 dark:bg-zinc-900/80">
            <div class="flex min-w-0 flex-col gap-1.5">
                <div class="flex flex-wrap items-center gap-1.5">
                    <flux:text variant="strong" class="text-base font-semibold text-zinc-900 dark:text-white">{{ $task->title }}</flux:text>
                    <flux:badge size="sm" :color="$task->status->color()">{{ $task->status->label() }}</flux:badge>
                    <flux:badge size="sm" :color="$task->priority->color()">{{ $task->priority->label() }}</flux:badge>
                    @if ($isOverdue)
                        <flux:badge size="sm" variant="pill" color="red">{{ __('Overdue') }}</flux:badge>
                    @endif
                    @if ($task->deal)
                        <flux:badge size="sm" variant="pill" color="zinc">{{ __('Deal: :name', ['name' => $task->deal->name]) }}</flux:badge>
                    @endif
                </div>

                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mt-1 text-xs sm:text-sm font-semibold text-zinc-800 dark:text-zinc-200">
                    @if ($task->due_date)
                        <span class="font-bold text-zinc-900 dark:text-white">
                            {{ __('Due :date', ['date' => $task->due_date->format('M j, Y')]) }}{{ $task->dueTimeFormatted() ? ' '.__('at :time', ['time' => $task->dueTimeFormatted()]) : '' }}
                        </span>
                    @endif
                    @if ($task->closed_at)
                        <span class="font-semibold text-zinc-800 dark:text-zinc-200">{{ __('Closed: :date', ['date' => $task->closed_at->format('M j, Y')]) }}</span>
                    @endif
                    <span class="font-semibold text-zinc-800 dark:text-zinc-200">{{ __('Assignee: :name', ['name' => $task->assignee?->name ?? __('Unassigned')]) }}</span>
                </div>

                @if ($task->description)
                    <div class="mt-1 block rounded-lg bg-white/95 p-2.5 text-xs sm:text-sm font-semibold text-zinc-900 dark:bg-zinc-800 dark:text-zinc-100 border border-zinc-200 dark:border-zinc-700 shadow-2xs">
                        {{ $task->description }}
                    </div>
                @endif
            </div>

            @if ($canUpdate || $canDeleteTask)
                <div class="flex flex-col gap-2 border-t border-zinc-200/80 pt-3 dark:border-zinc-700/80">
                    {{-- Top Row: Status transition actions with subtle identity color grading --}}
                    @if ($canUpdate && $task->status->value === 'pending')
                        <div class="grid grid-cols-2 gap-2">
                            <flux:button size="sm" icon="check" wire:click="changeTaskStatus('{{ $task->id }}', 'completed')" class="w-full font-semibold !bg-emerald-50 hover:!bg-emerald-100/90 !text-emerald-800 dark:!bg-emerald-950/50 dark:hover:!bg-emerald-900/70 dark:!text-emerald-300 !border !border-emerald-200/90 dark:!border-emerald-800/60 shadow-2xs transition-colors cursor-pointer">
                                {{ __('Complete') }}
                            </flux:button>
                            <flux:button size="sm" icon="x-mark" wire:click="changeTaskStatus('{{ $task->id }}', 'cancelled')" class="w-full font-semibold !bg-rose-50 hover:!bg-rose-100/90 !text-rose-800 dark:!bg-rose-950/50 dark:hover:!bg-rose-900/70 dark:!text-rose-300 !border !border-rose-200/90 dark:!border-rose-800/60 shadow-2xs transition-colors cursor-pointer">
                                {{ __('Cancel') }}
                            </flux:button>
                        </div>
                    @elseif ($canUpdate)
                        <div>
                            <flux:button size="sm" icon="arrow-path" wire:click="changeTaskStatus('{{ $task->id }}', 'pending')" class="w-full font-semibold !bg-sky-50 hover:!bg-sky-100/90 !text-sky-800 dark:!bg-sky-950/50 dark:hover:!bg-sky-900/70 dark:!text-sky-300 !border !border-sky-200/90 dark:!border-sky-800/60 shadow-2xs transition-colors cursor-pointer">
                                {{ __('Reopen Task') }}
                            </flux:button>
                        </div>
                    @endif

                    {{-- Bottom Row: Edit and Delete buttons with subtle identity color grading --}}
                    @if ($canUpdate || $canDeleteTask)
                        <div @class([
                            'grid gap-2',
                            'grid-cols-2' => $canUpdate && $canDeleteTask,
                            'grid-cols-1' => ! ($canUpdate && $canDeleteTask),
                        ])>
                            @if ($canUpdate)
                                <flux:button size="sm" icon="pencil-square" wire:click="editTask('{{ $task->id }}')" class="w-full font-semibold !bg-indigo-50 hover:!bg-indigo-100/90 !text-indigo-800 dark:!bg-indigo-950/50 dark:hover:!bg-indigo-900/70 dark:!text-indigo-300 !border !border-indigo-200/90 dark:!border-indigo-800/60 shadow-2xs transition-colors cursor-pointer">
                                    {{ __('Edit') }}
                                </flux:button>
                            @endif

                            @if ($canDeleteTask)
                                <flux:button size="sm" icon="trash" aria-label="{{ __('Delete task') }}" wire:click="confirmDeleteTask('{{ $task->id }}')" class="w-full font-semibold !bg-red-50 hover:!bg-red-100/90 !text-red-800 dark:!bg-red-950/50 dark:hover:!bg-red-900/70 dark:!text-red-300 !border !border-red-200/90 dark:!border-red-800/60 shadow-2xs transition-colors cursor-pointer">
                                    {{ __('Delete') }}
                                </flux:button>
                            @endif
                        </div>
                    @endif
                </div>
            @endif
        </div>
    @empty
        <flux:text class="font-medium text-zinc-600 dark:text-zinc-400">{{ __('No tasks yet for this lead.') }}</flux:text>
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
