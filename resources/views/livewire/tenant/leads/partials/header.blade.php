@php $compact ??= false; @endphp

<div class="flex items-start justify-between gap-3">
    <div class="flex items-center gap-3">
        @if ($lead->avatarUrl())
            <img src="{{ $lead->avatarUrl() }}" alt="" class="{{ $compact ? 'size-10' : 'size-12' }} shrink-0 rounded-full object-cover">
        @endif
        <div>
            <div class="mb-1 flex flex-wrap items-center gap-2">
                <flux:text size="sm" class="font-mono font-medium">{{ $lead->identifier }}</flux:text>
                <flux:badge size="sm" :color="$lead->status->color()">{{ $lead->status->label() }}</flux:badge>
                <flux:badge size="sm" :color="$lead->priority->color()">{{ $lead->priority->label() }}</flux:badge>
            </div>
            <flux:heading size="{{ $compact ? 'lg' : 'xl' }}">{{ $lead->name }}</flux:heading>
            @if ($lead->company)
                <flux:text variant="strong">{{ $lead->company }}</flux:text>
            @endif
            <flux:text>{{ $lead->email }} {{ $lead->phone ? '· '.$lead->phone : '' }}</flux:text>
            <div class="mt-2">
                @include('livewire.tenant.leads.partials.lead-tags-manager', ['lead' => $lead])
            </div>
        </div>
    </div>
</div>
