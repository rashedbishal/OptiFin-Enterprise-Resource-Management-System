@include('partials.lead-management-breadcrumbs', ['leadManagementTrail' => [
    ['label' => __('Leads'), 'href' => route('tenant-leads.index')],
    ['label' => __('Import Leads')],
]])

<div class="flex flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8 overflow-y-auto min-h-0">
    <div>
        <flux:heading size="xl" class="hidden lg:block">{{ __('Import Leads') }}</flux:heading>
        <flux:text variant="subtle">{{ __('Upload a CSV file to create multiple leads at once. Only "name" is required — every other column is optional.') }}</flux:text>
    </div>

    <div class="rounded-xl border border-zinc-200 bg-white p-4 sm:p-6 dark:border-zinc-700 dark:bg-zinc-800">
        <form wire:submit="import" class="flex flex-col gap-4">
            <flux:input type="file" wire:model="csvFile" accept=".csv,text/csv" :label="__('CSV File')" />
            @error('csvFile') <flux:error>{{ $message }}</flux:error> @enderror

            <flux:text size="sm" variant="subtle">
                {{ __('Maximum 10 MB, maximum 10,000 data rows. Allowed columns: name (required), email, phone, company, industry, location, service_interest, budget, budget_currency, deal_value, deal_value_currency, status, priority, assignee_email, tags.') }}
            </flux:text>

            <div>
                <flux:button type="submit" variant="primary" icon="arrow-up-tray" wire:loading.attr="disabled" wire:target="import">
                    {{ __('Upload & Import') }}
                </flux:button>
            </div>
        </form>
    </div>

    <x-confirm-modal
        wire:model.self="showDuplicateUploadModal"
        :title="__('This file was already imported')"
        :description="__(':filename was imported on :date with identical content. Importing it again may create duplicate leads for rows that have no email or phone. Rows whose email or phone match existing leads will still be skipped as duplicates.', ['filename' => $pendingDuplicateFilename, 'date' => $pendingDuplicateDate])"
        confirm-action="confirmDuplicateUpload"
        :confirm-label="__('Import again')"
        :cancel-label="__('Cancel')"
        variant="primary"
    />

    <div class="flex flex-col gap-3">
        <flux:heading size="lg">{{ __('Import History') }}</flux:heading>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('File') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Imported') }}</flux:table.column>
                <flux:table.column>{{ __('Duplicate') }}</flux:table.column>
                <flux:table.column>{{ __('Failed') }}</flux:table.column>
                <flux:table.column>{{ __('Uploaded') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->imports as $import)
                    <flux:table.row wire:key="lead-import-{{ $import->id }}">
                        <flux:table.cell>{{ $import->original_filename }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm" :color="$import->status->color()">{{ $import->status->label() }}</flux:badge></flux:table.cell>
                        <flux:table.cell>{{ $import->imported_count }}</flux:table.cell>
                        <flux:table.cell>{{ $import->duplicate_count }}</flux:table.cell>
                        <flux:table.cell>{{ $import->failed_count }}</flux:table.cell>
                        <flux:table.cell>{{ $import->created_at?->diffForHumans() }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:button size="sm" :href="route('tenant-lead-imports.show', $import)" wire:navigate>{{ __('View') }}</flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="7">
                            <flux:text variant="subtle">{{ __('No imports yet.') }}</flux:text>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        {{ $this->imports->links() }}
    </div>
</div>
