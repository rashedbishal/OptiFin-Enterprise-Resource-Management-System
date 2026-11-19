<section
    class="w-full p-4 sm:p-6 lg:p-8 overflow-y-auto min-h-0"
    x-data
    x-on:calling-app-open.window="window.location.href = $event.detail.link"
>
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Calling app settings') }}</flux:heading>

    <x-settings.layout :heading="__('Calling app')" :subheading="__('Take WhatsApp calls in the OptiFin Calling app on your phone, with earpiece and loudspeaker support')">
        <div class="my-6 w-full space-y-6">
            @if ($this->device)
                <flux:callout variant="success" icon="device-phone-mobile" :heading="__('Phone connected')">
                    <flux:callout.text>
                        {{ $this->device->device_name ?? __('Unnamed phone') }}
                        · {{ __('connected :when', ['when' => $this->device->created_at?->diffForHumans()]) }}
                        @if ($this->device->last_seen_at)
                            · {{ __('last active :when', ['when' => $this->device->last_seen_at->diffForHumans()]) }}
                        @endif
                    </flux:callout.text>
                </flux:callout>

                <div class="flex flex-wrap items-center gap-3">
                    <flux:button wire:click="connect" icon="arrow-path">{{ __('Reconnect this phone') }}</flux:button>
                    <flux:button variant="danger" wire:click="confirmUnpair">{{ __('Unpair') }}</flux:button>
                </div>

                <flux:text class="text-sm">
                    {{ __('Only one phone can be connected. Connecting another phone automatically unpairs this one.') }}
                </flux:text>
            @else
                <flux:text>
                    {{ __('Open this page on the phone where the OptiFin Calling app is installed, then tap the button below. The app will open and connect itself — no password needed.') }}
                </flux:text>

                <flux:button variant="primary" wire:click="connect" icon="device-phone-mobile">{{ __('Connect calling app') }}</flux:button>
            @endif

            @if ($pairingLink)
                <flux:callout variant="subtle" icon="information-circle">
                    <flux:callout.text>
                        {{ __('If the app did not open,') }}
                        <flux:link href="{{ $pairingLink }}">{{ __('tap here to open it') }}</flux:link>.
                        {{ __('This link works once and expires in :minutes minutes. If nothing happens, the app is not installed on this phone.', ['minutes' => max(1, intdiv((int) config('calling.calling_app.pairing_code_ttl_seconds'), 60))]) }}
                    </flux:callout.text>
                </flux:callout>
            @endif
        </div>
    </x-settings.layout>

    <x-confirm-modal
        wire:model.self="showUnpairModal"
        :title="__('Unpair calling app?')"
        :description="__('The app on this phone will stop receiving and placing calls until it is connected again. Calls will ring in the browser instead.')"
        confirm-action="unpair"
        :confirm-label="__('Unpair')"
    />
</section>
