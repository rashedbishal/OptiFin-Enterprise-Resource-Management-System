{{-- Read-only progress visualization — the actual status/priority change
control is the separate Status card elsewhere on the page (status-form.blade.php
if editable, otherwise nothing further since the badges already show it in
the hero above). Clicking a stage here does nothing on purpose. --}}
@php $pipeline = $lead->tenant->leadStatuses; @endphp

<div class="flex flex-col gap-2">
    <flux:text size="sm" variant="strong" class="uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
        {{ __('Status Pipeline Progression') }}
    </flux:text>

    <div class="flex gap-2 overflow-x-auto pb-1">
        @foreach ($pipeline as $index => $statusCase)
            @php $isCurrent = $statusCase->id === $lead->status_id; @endphp
            <div
                class="flex min-w-32 shrink-0 flex-col gap-0.5 rounded-lg border px-3 py-2 {{ $isCurrent ? $statusCase->cardBackgroundClasses() : 'border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800/60' }}"
            >
                <flux:text size="xs" variant="subtle" class="{{ $isCurrent ? 'font-semibold' : '' }}">{{ __('Stage :n', ['n' => $index + 1]) }}</flux:text>
                <flux:text size="sm" class="truncate {{ $isCurrent ? 'font-semibold' : '' }}">{{ $statusCase->label() }}</flux:text>
            </div>
        @endforeach
    </div>
</div>
