@push('breadcrumbs')
    <x-breadcrumbs :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard')],
        ['label' => __('Leads'), 'href' => route('tenant-leads.index')],
        ['label' => __('Import Leads'), 'href' => route('tenant-lead-imports.index')],
        ['label' => $leadImport->original_filename],
    ]" />
@endpush

<div
    class="flex flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8 overflow-y-auto min-h-0"
    @if (in_array($leadImport->status->value, ['pending', 'processing'], true))
        {{--
            Reverb (see Show::getListeners()) is the sole live-update
            transport — no recurring poll. This is a single, one-shot
            reconciliation read 5s after mount, plain Alpine setTimeout (not
            Livewire's wire:poll, so it's unaffected by the background-tab
            poll throttle that caused QA-06) guarding against two narrow
            races: the broadcast landing before this page's private-channel
            subscription finishes, or a brief Reverb disconnect exactly
            during finalization. Fires once, never re-arms — after it
            fires, Reverb is the only remaining mechanism for this import.
        --}}
        x-data
        x-init="setTimeout(() => $wire.refreshStatus(), 5000)"
    @endif
>
    <div class="flex items-center justify-between gap-2">
        <div>
            <flux:heading size="xl" class="hidden lg:block">{{ $leadImport->original_filename }}</flux:heading>
            <flux:text variant="subtle">{{ __('Uploaded :time', ['time' => $leadImport->created_at?->diffForHumans()]) }}</flux:text>
        </div>
        <flux:badge size="lg" :color="$leadImport->status->color()">{{ $leadImport->status->label() }}</flux:badge>
    </div>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text size="sm" variant="subtle">{{ __('Total Rows') }}</flux:text>
            <flux:heading size="lg">{{ $leadImport->total_rows ?? '—' }}</flux:heading>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text size="sm" variant="subtle">{{ __('Imported') }}</flux:text>
            <flux:heading size="lg" class="text-emerald-600 dark:text-emerald-400">{{ $leadImport->imported_count }}</flux:heading>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text size="sm" variant="subtle">{{ __('Duplicate') }}</flux:text>
            <flux:heading size="lg" class="text-amber-600 dark:text-amber-400">{{ $leadImport->duplicate_count }}</flux:heading>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text size="sm" variant="subtle">{{ __('Failed') }}</flux:text>
            <flux:heading size="lg" class="text-red-600 dark:text-red-400">{{ $leadImport->failed_count }}</flux:heading>
        </div>
    </div>

    @if ($leadImport->status->value === 'failed' && $leadImport->error_message)
        <flux:callout variant="danger" icon="exclamation-triangle" :heading="__('Import failed')">
            <flux:callout.text>{{ $leadImport->error_message }}</flux:callout.text>
        </flux:callout>
    @endif

    <div class="flex flex-col gap-3">
        <flux:heading size="lg">{{ __('Row Results') }}</flux:heading>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Row') }}</flux:table.column>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Details') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($rows as $row)
                    <flux:table.row wire:key="lead-import-row-{{ $row->id }}">
                        <flux:table.cell>{{ $row->row_number }}</flux:table.cell>
                        <flux:table.cell>{{ $row->raw_data['name'] ?? '—' }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm" :color="$row->status->color()">{{ $row->status->label() }}</flux:badge></flux:table.cell>
                        <flux:table.cell>
                            @if ($row->lead)
                                <flux:link :href="route('tenant-leads.show', $row->lead)" wire:navigate>{{ __('View lead') }}</flux:link>
                            @elseif ($row->duplicateOfLead)
                                {{ __('Duplicate of') }}
                                <flux:link :href="route('tenant-leads.show', $row->duplicateOfLead)" wire:navigate>{{ $row->duplicateOfLead->name }}</flux:link>
                            @elseif ($row->errors)
                                <flux:text size="sm" class="text-red-600 dark:text-red-400">{{ implode(' ', $row->errors) }}</flux:text>
                            @else
                                —
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="4">
                            <flux:text variant="subtle">{{ __('No rows processed yet.') }}</flux:text>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        {{ $rows->links() }}
    </div>
</div>
