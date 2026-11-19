<section
    class="w-full p-4 sm:p-6 lg:p-8 overflow-y-auto min-h-0"
    {{-- On "Connect": remember that THIS browser paired the phone (the key
    must match BROWSER_MARKER_STORAGE_KEY in resources/js/softphone/
    calling-app.js), then open the app. A method, not inline statements —
    an x-on attribute is evaluated as a single expression, so a bare
    try/catch there is a syntax error that silently does nothing. --}}
    x-data="{
        rememberPairingBrowser(marker) {
            try {
                localStorage.setItem('optifin.callingApp.browserMarker', marker);
            } catch (error) {
                // Private mode / storage blocked: the hand-off just won't
                // recognise this browser; pairing itself still works.
            }
        },
        openApp(detail) {
            this.rememberPairingBrowser(detail.marker);
            window.location.href = detail.link;
        },
    }"
    x-on:calling-app-open.window="openApp($event.detail)"
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

            {{-- Diagnostic: what THIS browser can see — the same two checks the
            softphone's Call button uses (resources/js/softphone/calling-app.js)
            to decide whether to hand a call to the app. Display only; reload
            the page after connecting to see the new state. --}}
            <div
                wire:ignore
                x-data="{
                    marker: 'checking',
                    chrome: 'checking',
                    found: [],
                    async init() {
                        const markerHash = @js($this->device?->browser_marker_hash);

                        try {
                            const stored = localStorage.getItem('optifin.callingApp.browserMarker');

                            if (! markerHash || ! stored) {
                                this.marker = 'no';
                            } else {
                                const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(stored));
                                const hex = [...new Uint8Array(digest)].map((b) => b.toString(16).padStart(2, '0')).join('');
                                this.marker = hex === markerHash ? 'yes' : 'no';
                            }
                        } catch (error) {
                            this.marker = 'no';
                        }

                        if (typeof navigator.getInstalledRelatedApps !== 'function') {
                            this.chrome = 'unsupported';

                            return;
                        }

                        try {
                            const apps = await navigator.getInstalledRelatedApps();
                            this.found = (apps || []).map((app) => `${app.platform}:${app.id}`);
                            this.chrome = (apps || []).some((app) => app.id === @js(config('calling.calling_app.android_package'))) ? 'yes' : 'no';
                        } catch (error) {
                            this.chrome = 'error';
                            this.found = [String(error)];
                        }
                    },
                    get willHandOff() {
                        return @js((bool) $this->device) && (this.marker === 'yes' || this.chrome === 'yes');
                    },
                }"
                class="rounded-lg border border-zinc-200 p-4 text-sm dark:border-zinc-700"
                data-test="calling-app-detection"
            >
                <flux:heading size="sm">{{ __('This browser') }}</flux:heading>
                <ul class="mt-2 space-y-1">
                    <li>
                        {{ __('Paired phone on your account:') }}
                        <strong>{{ $this->device ? __('Yes') : __('No') }}</strong>
                    </li>
                    <li>
                        {{ __('This browser connected that phone:') }}
                        <strong x-show="marker === 'checking'">{{ __('Checking…') }}</strong>
                        <strong x-show="marker === 'yes'" class="text-green-600 dark:text-green-400">{{ __('Yes') }}</strong>
                        <strong x-show="marker === 'no'">{{ __('No') }}</strong>
                    </li>
                    <li>
                        {{ __('Chrome detects the app:') }}
                        <strong x-show="chrome === 'checking'">{{ __('Checking…') }}</strong>
                        <strong x-show="chrome === 'yes'" class="text-green-600 dark:text-green-400">{{ __('Yes') }}</strong>
                        <strong x-show="chrome === 'no'">{{ __('No') }}</strong>
                        <strong x-show="chrome === 'unsupported'">{{ __('Not supported in this browser') }}</strong>
                        <strong x-show="chrome === 'error'">{{ __('Check failed') }}</strong>
                    </li>
                    <li x-show="found.length > 0" class="text-zinc-500">
                        {{ __('Browser reported:') }} <span x-text="found.join(', ')"></span>
                    </li>
                    <li class="pt-2">
                        {{ __('Call buttons in this browser open the app:') }}
                        <strong x-show="willHandOff" class="text-green-600 dark:text-green-400">{{ __('Yes') }}</strong>
                        <strong x-show="!willHandOff" class="text-red-600 dark:text-red-400">{{ __('No — calls stay in the browser') }}</strong>
                    </li>
                </ul>
                <flux:text class="mt-3 text-xs">
                    {{ __('If you uninstall the app from this phone, tap Unpair too — otherwise Call buttons here keep trying to open it.') }}
                </flux:text>
            </div>

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
