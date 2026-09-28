<flux:card class="flex flex-col gap-4">
    <div class="flex items-center justify-between gap-2 border-b border-zinc-100 pb-3 dark:border-zinc-800">
        <div class="flex items-center gap-2">
            <div class="flex size-7 items-center justify-center rounded-lg bg-amber-50 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400">
                <flux:icon.pencil-square variant="micro" class="size-4" />
            </div>
            <flux:heading size="lg" class="font-semibold">{{ __('Notes') }}</flux:heading>
        </div>
        @if (count($this->leadNotes) > 0)
            <span class="inline-flex items-center rounded-full bg-zinc-100 px-2.5 py-0.5 text-xs font-semibold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                {{ count($this->leadNotes) }}
            </span>
        @endif
    </div>

    <div data-test="lead-notes-list" class="flex max-h-96 flex-col gap-3 overflow-y-auto overscroll-contain pe-1">
        @forelse ($this->leadNotes as $note)
            <div wire:key="note-row-{{ $note->id }}" class="relative overflow-hidden rounded-xl border border-zinc-200/80 bg-zinc-50/70 p-4 shadow-2xs dark:border-zinc-800 dark:bg-zinc-900/60">
                <div class="mb-2 flex items-center justify-between gap-2 border-b border-zinc-200/60 pb-2 dark:border-zinc-800/60">
                    @if ($note->tenantUser)
                        <div class="flex items-center gap-2">
                            <div class="flex size-6 items-center justify-center rounded-full bg-accent/10 text-xs font-bold text-accent dark:bg-accent/20">
                                {{ strtoupper(substr($note->tenantUser->name, 0, 1)) }}
                            </div>
                            <span class="text-xs font-semibold text-zinc-900 dark:text-white">{{ $note->tenantUser->name }}</span>
                        </div>
                    @endif
                    <span class="text-[11px] font-medium text-zinc-400 dark:text-zinc-500 ms-auto">
                        {{ $note->created_at->format('M j, Y • g:i A') }}
                    </span>
                </div>

                <p class="whitespace-pre-wrap text-xs sm:text-sm font-medium leading-relaxed text-zinc-800 dark:text-zinc-200">{{ $note->note }}</p>
            </div>
        @empty
            <div class="flex flex-col items-center gap-2 py-8 text-center">
                <div class="flex size-10 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800 text-zinc-400">
                    <flux:icon.document-text class="size-5" />
                </div>
                <p class="text-xs sm:text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('No notes yet.') }}</p>
            </div>
        @endforelse
    </div>
</flux:card>
