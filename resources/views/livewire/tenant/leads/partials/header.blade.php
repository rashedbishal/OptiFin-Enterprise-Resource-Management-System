@php $compact ??= false; @endphp

<div class="flex items-start justify-between gap-3">
    <div class="flex items-center gap-3">
        @if ($lead->avatarUrl())
            <img src="{{ $lead->avatarUrl() }}" alt="" class="{{ $compact ? 'size-10' : 'size-12' }} shrink-0 rounded-full object-cover">
        @endif
        <div>
            <flux:text size="sm" variant="subtle" class="font-mono">{{ $lead->identifier }}</flux:text>
            <flux:heading size="{{ $compact ? 'lg' : 'xl' }}">{{ $lead->name }}</flux:heading>
            @if ($lead->company)
                <flux:text variant="strong">{{ $lead->company }}</flux:text>
            @endif
            <flux:text>{{ $lead->email }} {{ $lead->phone ? '· '.$lead->phone : '' }}</flux:text>
        </div>
    </div>
    @if ($canDelete)
        <flux:button variant="danger" size="sm" wire:click="confirmDelete">
            {{ __('Delete') }}
        </flux:button>
    @endif
</div>
