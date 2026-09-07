@php
    $compact ??= false;

    // See lead-hero.blade.php's identical pair for why this is purely a
    // visual reveal-together toggle — this card is a form, nothing here is
    // ever actually fetched async, it just shimmers alongside the rest of
    // the page's cards on first paint.
    $listLoadingTargets = 'completeInitialLoad';
    $showSkeletonByDefault = ! $initialLoadComplete;
@endphp

<flux:card>
    <flux:heading size="{{ $compact ? 'sm' : 'lg' }}" class="{{ $compact ? 'mb-2' : 'mb-4' }}">{{ __('Add Note') }}</flux:heading>

    {{-- Real content: hidden by default before initialLoadComplete, toggled
    by wire:loading otherwise — never a bare wire:loading/.remove, see
    leads.blade.php's long comment for why. --}}
    <div wire:loading.class="!hidden" wire:target="{{ $listLoadingTargets }}" class="{{ $showSkeletonByDefault ? '!hidden' : '' }} contents">
        <form wire:submit="addNote" class="flex flex-col gap-3">
            <flux:textarea wire:model="newNote" :label="__('Note')" :rows="$compact ? 1 : 2" />
            <div>
                <flux:button type="submit" variant="primary" size="sm">{{ __('Add Note') }}</flux:button>
            </div>
        </form>
    </div>

    {{-- Skeleton: hidden by default, revealed only while
    initialLoadComplete's request is in flight. --}}
    <div class="{{ $showSkeletonByDefault ? '' : '!hidden' }} flex flex-col gap-3" wire:loading.remove.class="!hidden" wire:target="{{ $listLoadingTargets }}">
        <div class="h-3 w-12 skeleton-shimmer rounded"></div>
        <div class="h-16 w-full skeleton-shimmer rounded"></div>
        <div class="h-8 w-24 skeleton-shimmer rounded"></div>
    </div>
</flux:card>
