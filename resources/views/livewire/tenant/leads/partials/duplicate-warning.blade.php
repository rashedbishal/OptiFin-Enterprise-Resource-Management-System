{{-- Possible-duplicate banner + merge conflict-resolution modal for the
Lead detail page. Deliberately shown here only (the full page and the
Kanban flyout), never on the leads table/Kanban card — see
App\Livewire\Tenant\Leads\Show::possibleDuplicates()'s docblock. Visible to
any viewer who can see this lead at all (informational); the Merge/Dismiss
buttons themselves stay gated by $this->canMerge. --}}
@if ($this->possibleDuplicates->isNotEmpty())
    <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('Possible duplicate lead(s) found')">
        <flux:callout.text>
            {{ __('This contact may already exist as another lead, captured through a different channel.') }}
        </flux:callout.text>

        <div class="mt-3 flex flex-col gap-2">
            @foreach ($this->possibleDuplicates as $duplicate)
                <div wire:key="duplicate-{{ $duplicate['link']->id }}" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-300 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-950/40">
                    <div>
                        <flux:link href="{{ route('tenant-leads.show', $duplicate['other']) }}" wire:navigate class="font-medium">
                            {{ $duplicate['other']->identifier }} — {{ $duplicate['other']->name }}
                        </flux:link>
                        <flux:text size="sm" variant="subtle" class="block">
                            {{ __('Matched on :fields', ['fields' => collect($duplicate['link']->matched_fields ?? [])->map(fn (string $f) => ucfirst($f))->implode(', ')]) }}
                        </flux:text>
                    </div>

                    @if ($this->canMerge)
                        <div class="flex gap-2">
                            <flux:button type="button" size="sm" variant="ghost" wire:click="dismissDuplicate('{{ $duplicate['link']->id }}')">
                                {{ __('Not a duplicate') }}
                            </flux:button>
                            <flux:button type="button" size="sm" variant="filled" wire:click="openMergeModal('{{ $duplicate['link']->id }}')">
                                {{ __('Merge') }}
                            </flux:button>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </flux:callout>
@endif

@if ($this->canMerge)
    <x-confirm-modal
        wire:model.self="showMergeModal"
        :title="__('Merge these leads?')"
        :description="__('This lead keeps its own notes, attachments, and history, plus everything from the other lead. The other lead is retired, not deleted. This cannot be undone.')"
        confirm-action="confirmMerge"
        :confirm-label="__('Merge')"
        cancel-label="{{ __('Cancel') }}"
        variant="filled"
    >
        @if ($this->mergeConflicts !== [])
            <div class="flex flex-col gap-4">
                <flux:text size="sm" variant="subtle">
                    {{ __('These fields differ between the two leads. Choose which value to keep — anything not chosen below stays as it is on this lead.') }}
                </flux:text>

                @foreach ($this->mergeConflicts as $field => $values)
                    <div wire:key="merge-conflict-{{ $field }}">
                        <flux:text class="mb-1 font-medium">{{ $this->mergeFieldLabel($field) }}</flux:text>
                        <flux:radio.group wire:model="mergeFieldResolutions.{{ $field }}">
                            <flux:radio value="survivor" :label="__('Keep: :value', ['value' => $this->mergeFieldValueLabel($field, $values['survivor'])])" />
                            <flux:radio value="loser" :label="__('Use other lead: :value', ['value' => $this->mergeFieldValueLabel($field, $values['loser'])])" />
                        </flux:radio.group>
                    </div>
                @endforeach
            </div>
        @else
            <flux:text variant="subtle">{{ __("No conflicting details — this lead's own values will be kept as-is.") }}</flux:text>
        @endif
    </x-confirm-modal>
@endif
