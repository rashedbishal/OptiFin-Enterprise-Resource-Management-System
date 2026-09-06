@php
    // canEdit defaults false so the Kanban flyout's existing call site
    // (which never passes it) never renders the Edit/Cancel link — its
    // form is simply always shown whenever canUpdate is true, with no
    // read-only state of its own to toggle back to.
    $canEdit = $canEdit ?? false;
@endphp

<flux:card>
    <div class="mb-4 flex items-center justify-between gap-2">
        <flux:heading size="lg">{{ __('Company') }}</flux:heading>
        @if ($canEdit && ! $editable)
            <flux:link as="button" type="button" variant="subtle" class="text-xs" wire:click="startEditingCompany">{{ __('Edit') }}</flux:link>
        @endif
    </div>
    @if ($editable)
        <form wire:submit="updateCompany" class="flex flex-col gap-3">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <flux:input wire:model="leadCompany" :label="__('Company / Organization')" />
                <flux:input wire:model="leadWebsite" type="url" :label="__('Website URL')" />
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <flux:input wire:model="leadIndustry" :label="__('Industry Sector')" />
                <flux:input wire:model="leadLocation" :label="__('Location / City')" />
            </div>
            <div class="flex gap-2">
                <flux:button type="submit" variant="primary" size="sm">{{ __('Save') }}</flux:button>
                @if ($canEdit)
                    <flux:button type="button" size="sm" wire:click="cancelEditingCompany">{{ __('Cancel') }}</flux:button>
                @endif
            </div>
        </form>
    @else
        @php $hasCompanyDetails = collect([$lead->company, $lead->website, $lead->industry, $lead->location])->filter()->isNotEmpty(); @endphp
        @if ($hasCompanyDetails)
            <div class="flex flex-col gap-3">
                @if ($lead->company)
                    <div>
                        <flux:text size="sm" variant="subtle">{{ __('Company') }}</flux:text>
                        <flux:text class="block break-words">{{ $lead->company }}</flux:text>
                    </div>
                @endif
                @if ($lead->website)
                    <div>
                        <flux:text size="sm" variant="subtle">{{ __('Website') }}</flux:text>
                        <a href="{{ $lead->website }}" target="_blank" rel="noopener noreferrer" class="block w-fit max-w-full break-all underline">{{ $lead->website }}</a>
                    </div>
                @endif
                @if ($lead->industry)
                    <div>
                        <flux:text size="sm" variant="subtle">{{ __('Industry') }}</flux:text>
                        <flux:text class="block break-words">{{ $lead->industry }}</flux:text>
                    </div>
                @endif
                @if ($lead->location)
                    <div>
                        <flux:text size="sm" variant="subtle">{{ __('Location') }}</flux:text>
                        <flux:text class="block break-words">{{ $lead->location }}</flux:text>
                    </div>
                @endif
            </div>
        @else
            <flux:text variant="subtle">{{ __('No company details yet.') }}</flux:text>
        @endif
    @endif
</flux:card>
