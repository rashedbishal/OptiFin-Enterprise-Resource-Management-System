<flux:card class="flex flex-col gap-4">
    <flux:heading size="lg">{{ __('Notes') }}</flux:heading>

    @forelse ($this->leadNotes as $note)
        <div wire:key="note-row-{{ $note->id }}" class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
            <flux:text class="whitespace-pre-wrap">{{ $note->note }}</flux:text>

            <flux:text size="sm" variant="subtle" class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5">
                @if ($note->tenantUser)
                    <span>{{ $note->tenantUser->name }}</span>
                @endif
                <span>{{ $note->created_at->format('M j, Y g:i A') }}</span>
            </flux:text>
        </div>
    @empty
        <flux:text variant="subtle">{{ __('No notes yet.') }}</flux:text>
    @endforelse
</flux:card>
