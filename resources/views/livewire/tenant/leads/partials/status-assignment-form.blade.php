{{-- Full-page-only merged card: partials/status-form.blade.php and
partials/assignment-form.blade.php stay separate (their own cards, their
own buttons) on the Kanban flyout — this combines both into one form with
one submit button, wired to Show::updateStatusAndAssignment(). --}}
@php
    // See lead-hero.blade.php's identical pair for why this is purely a
    // visual reveal-together toggle.
    $listLoadingTargets = 'completeInitialLoad';
    $showSkeletonByDefault = ! $initialLoadComplete;
@endphp

<flux:card class="flex flex-col gap-4">
    <flux:heading size="lg">{{ __('Status & Assignment') }}</flux:heading>

    {{-- Real content: hidden by default before initialLoadComplete, toggled
    by wire:loading otherwise — never a bare wire:loading/.remove, see
    leads.blade.php's long comment for why. --}}
    <div wire:loading.class="!hidden" wire:target="{{ $listLoadingTargets }}" class="{{ $showSkeletonByDefault ? '!hidden' : '' }} contents">
    <form wire:submit="updateStatusAndAssignment" class="flex flex-col gap-3">
        @if ($this->canUpdate)
            @php
                $statusOpts = collect($statusOptions)->map(fn ($o) => ['value' => (string) $o->id, 'label' => $o->label()])->toArray();
                $priorityOpts = collect($priorityOptions)->map(fn ($o) => ['value' => $o, 'label' => ucfirst($o)])->toArray();
            @endphp
            <div class="flex flex-col gap-3 lg:flex-row">
                <div class="lg:flex-1">
                    <x-dropdown-select wire:model="status" :label="__('Status')" :options="$statusOpts" />
                </div>
                <div class="lg:flex-1">
                    <x-dropdown-select wire:model="priority" :label="__('Priority')" :options="$priorityOpts" />
                </div>
            </div>
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
    </div>

    {{-- Skeleton: hidden by default, revealed only while
    initialLoadComplete's request is in flight. --}}
    <div class="{{ $showSkeletonByDefault ? '' : '!hidden' }} flex flex-col gap-3" wire:loading.remove.class="!hidden" wire:target="{{ $listLoadingTargets }}">
        <div class="flex flex-col gap-3 lg:flex-row">
            <div class="flex-1 flex flex-col gap-1.5">
                <div class="h-3 w-12 skeleton-shimmer rounded"></div>
                <div class="h-9 w-full skeleton-shimmer rounded"></div>
            </div>
            <div class="flex-1 flex flex-col gap-1.5">
                <div class="h-3 w-14 skeleton-shimmer rounded"></div>
                <div class="h-9 w-full skeleton-shimmer rounded"></div>
            </div>
        </div>
        <div class="flex flex-col gap-1.5">
            <div class="h-3 w-20 skeleton-shimmer rounded"></div>
            <div class="h-9 w-full skeleton-shimmer rounded"></div>
        </div>
        <div class="h-8 w-20 skeleton-shimmer rounded"></div>
    </div>
</flux:card>
