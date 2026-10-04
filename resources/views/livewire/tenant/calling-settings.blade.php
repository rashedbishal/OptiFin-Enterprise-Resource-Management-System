@include('partials.lead-management-breadcrumbs', ['leadManagementTrail' => [['label' => __('Calling Settings')]]])

<div class="flex flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8 overflow-y-auto min-h-0">
    <div>
        <flux:heading size="xl" class="hidden lg:block">{{ __('Calling Settings') }}</flux:heading>
        <flux:text variant="subtle">{{ __('Customize the ringtone your team hears for an incoming WhatsApp call.') }}</flux:text>
    </div>

    <flux:card class="max-w-xl">
        <flux:heading size="lg">{{ __('Ringtone') }}</flux:heading>
        <flux:text variant="subtle" class="mb-4">{{ __('MP3 only, 1MB maximum. Replacing the ringtone removes the previous file.') }}</flux:text>

        @if (auth()->user()->tenant->whatsapp_call_ringtone_path)
            <div class="mb-4 flex items-center justify-between gap-3 rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-800">
                <div class="min-w-0">
                    <flux:text variant="strong" class="block truncate">{{ auth()->user()->tenant->whatsapp_call_ringtone_original_name }}</flux:text>
                    <flux:text size="sm" variant="subtle">{{ $this->humanSize(auth()->user()->tenant->whatsapp_call_ringtone_size) }}</flux:text>
                </div>
                <flux:button size="sm" variant="danger" wire:click="remove" wire:confirm="{{ __('Remove the custom ringtone and revert to the default tone?') }}">
                    {{ __('Remove') }}
                </flux:button>
            </div>
        @else
            <flux:text size="sm" variant="subtle" class="mb-4">{{ __('No custom ringtone set — calls currently use the default tone.') }}</flux:text>
        @endif

        <form wire:submit="save" class="flex flex-col gap-4">
            <flux:input type="file" wire:model="ringtoneFile" accept=".mp3,audio/mpeg" :label="__('New Ringtone (MP3)')" />

            <div wire:loading wire:target="ringtoneFile">
                <flux:text size="sm" variant="subtle">{{ __('Uploading…') }}</flux:text>
            </div>

            <div>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">
                    {{ __('Save Ringtone') }}
                </flux:button>
            </div>
        </form>
    </flux:card>
</div>
