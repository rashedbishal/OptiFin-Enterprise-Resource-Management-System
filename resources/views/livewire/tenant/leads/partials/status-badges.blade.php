<div class="flex items-center gap-2">
    <flux:badge size="sm" :color="$lead->status->color()">{{ $lead->status->label() }}</flux:badge>
    <flux:badge size="sm" :color="$lead->priority->color()">{{ $lead->priority->label() }}</flux:badge>
</div>
