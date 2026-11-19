<div class="flex flex-1 flex-col gap-6 md:max-w-2xl">
    <x-breadcrumbs :items="[
        ['label' => __('Dashboard'), 'href' => route('admin.dashboard')],
        ['label' => __('Calling app')],
    ]" />

    <div>
        <flux:heading size="xl" class="hidden lg:block">{{ __('Calling app') }}</flux:heading>
        <flux:text class="mt-1">
            {{ __('The OptiFin Calling app rings agents\' phones for incoming WhatsApp calls, even when the app is closed. Upload its Firebase key once — it applies to every company. Each company\'s own WhatsApp number and calling settings stay on their lead sources.') }}
        </flux:text>
    </div>

    @if ($this->summary)
        <flux:callout variant="success" icon="check-circle" :heading="__('Firebase key configured')">
            <flux:callout.text>
                {{ __('Project') }}: <strong>{{ $this->summary['project_id'] }}</strong><br>
                {{ __('Service account') }}: {{ $this->summary['client_email'] }}<br>
                {{ __('Uploaded') }} {{ $this->summary['uploaded_at']?->diffForHumans() }}@if ($this->summary['uploaded_by']) {{ __('by :name', ['name' => $this->summary['uploaded_by']]) }}@endif
            </flux:callout.text>
        </flux:callout>
    @else
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('Not configured')">
            <flux:callout.text>
                {{ __('Until a key is uploaded, incoming calls ring in the browser only and the calling app cannot be woken.') }}
            </flux:callout.text>
        </flux:callout>
    @endif

    <form wire:submit="save" class="flex flex-col gap-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
        <flux:heading size="lg">{{ $this->summary ? __('Replace the Firebase key') : __('Upload the Firebase key') }}</flux:heading>
        <flux:text class="text-sm">
            {{ __('In the Firebase console for project ":project": Project settings → Service accounts → Generate new private key. Upload the downloaded .json file here. It is stored encrypted and never shown again.', ['project' => config('calling.calling_app.firebase_project_id')]) }}
        </flux:text>

        <flux:input type="file" wire:model="keyFile" accept=".json,application/json" :label="__('Service account key (.json)')" />

        <div class="flex items-center gap-3">
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="keyFile,save">{{ __('Save key') }}</flux:button>
            @if ($this->summary)
                <flux:button type="button" variant="danger" wire:click="confirmRemove">{{ __('Remove key') }}</flux:button>
            @endif
        </div>
    </form>

    @if ($this->summary)
        <div class="flex flex-col gap-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="lg">{{ __('Send a test notification') }}</flux:heading>
            <flux:text class="text-sm">{{ __('Sends a visible test notification to one paired phone — confirms the key and that phone\'s setup without waiting for a real call.') }}</flux:text>

            @if ($this->testDevices->isEmpty())
                <flux:text class="text-sm">{{ __('No paired phone has registered for notifications yet. Open the OptiFin Calling app on a paired phone first.') }}</flux:text>
            @else
                @php
                    $deviceOptions = array_merge(
                        [['value' => '', 'label' => __('Choose a phone')]],
                        $this->testDevices->all()
                    );
                @endphp
                <x-dropdown-select wire:model="testDeviceId" :label="__('Phone')" :placeholder="__('Choose a phone')" :options="$deviceOptions" />
                <div>
                    <flux:button wire:click="sendTest" icon="paper-airplane">{{ __('Send test') }}</flux:button>
                </div>
            @endif
        </div>
    @endif

    {{-- App download: the APK that eligible tenant users download from
    Settings → Calling app (latest only). Uploaded in small chunks by
    resources/js/calling-app-apk-upload.js, so no server upload limit applies. --}}
    <div class="flex flex-col gap-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" data-test="calling-app-apk">
        <div>
            <flux:heading size="lg">{{ __('App download') }}</flux:heading>
            <flux:text class="mt-1 text-sm">
                {{ __('Upload the OptiFin Calling .apk here. Users of companies whose WhatsApp number has calling switched on can then download it from Settings → Calling app, and the app tells them when a newer version is available.') }}
            </flux:text>
        </div>

        @if ($this->release)
            <div class="flex flex-wrap items-start justify-between gap-3 rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800/60">
                <div class="text-sm">
                    <div class="font-medium">
                        {{ __('Current version') }} {{ $this->release['version'] }}
                        @if ($this->release['version_code'])
                            <span class="font-normal text-zinc-500">({{ __('build :code', ['code' => $this->release['version_code']]) }})</span>
                        @endif
                    </div>
                    <div class="text-zinc-500">
                        {{ number_format($this->release['size'] / 1048576, 1) }} MB
                        · {{ __('uploaded') }} {{ $this->release['uploaded_at']?->diffForHumans() }}@if ($this->release['uploaded_by']) {{ __('by :name', ['name' => $this->release['uploaded_by']]) }}@endif
                    </div>
                    @if ($this->release['notes'])
                        <div class="mt-1 whitespace-pre-line text-zinc-600 dark:text-zinc-300">{{ $this->release['notes'] }}</div>
                    @endif
                </div>
                <flux:button size="sm" variant="danger" wire:click="confirmRemoveApk">{{ __('Remove') }}</flux:button>
            </div>
        @else
            <flux:text class="text-sm">{{ __('No app uploaded yet.') }}</flux:text>
        @endif

        <div
            wire:ignore
            x-data="callingAppApkUploader(@js([
                'urls' => [
                    'start' => route('admin.settings.calling-app.apk.start'),
                    'chunk' => str_replace('/chunks/0', '/chunks/__INDEX__', route('admin.settings.calling-app.apk.chunk', ['upload' => '__UPLOAD__', 'index' => 0])),
                    'complete' => route('admin.settings.calling-app.apk.complete', ['upload' => '__UPLOAD__']),
                ],
                'texts' => ['missing' => __('Choose the .apk file.')],
            ]))"
            class="flex flex-col gap-3"
        >
            <flux:heading size="sm">{{ $this->release ? __('Upload a new version') : __('Upload the app') }}</flux:heading>

            <input
                type="file"
                accept=".apk,application/vnd.android.package-archive"
                x-ref="apkFile"
                x-on:change="pick($event)"
                x-bind:disabled="busy"
                class="block w-full text-sm file:mr-3 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-2 file:text-sm file:font-medium dark:file:bg-zinc-700"
            />

            <flux:input x-model="notes" :label="__('What\'s new (optional)')" x-bind:disabled="busy" />

            <flux:text class="text-xs">
                {{ __('The version is read from the file itself. A build older than the current one is refused.') }}
            </flux:text>

            <div x-show="busy" x-cloak class="flex items-center gap-3">
                <div class="h-2 flex-1 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                    <div class="h-full bg-green-600 transition-all" x-bind:style="`width: ${progress}%`"></div>
                </div>
                <span class="w-12 text-right text-sm tabular-nums" x-text="`${progress}%`"></span>
            </div>

            <flux:text x-show="error" x-cloak class="text-sm !text-red-600" x-text="error"></flux:text>

            <div>
                <flux:button variant="primary" icon="arrow-up-tray" x-on:click="upload()" x-bind:disabled="busy">
                    <span x-show="!busy">{{ __('Upload') }}</span>
                    <span x-show="busy" x-cloak>{{ __('Uploading…') }}</span>
                </flux:button>
            </div>
        </div>
    </div>

    <x-confirm-modal
        wire:model.self="showRemoveApkModal"
        :title="__('Remove the app download?')"
        :description="__('Users will no longer be able to download the app from Settings → Calling app. Phones that already have it keep working.')"
        confirm-action="removeApk"
        :confirm-label="__('Remove')"
    />

    <x-confirm-modal
        wire:model.self="showRemoveModal"
        :title="__('Remove the Firebase key?')"
        :description="__('The calling app will stop ringing for incoming calls on every company\'s phones. Calls keep ringing in the browser.')"
        confirm-action="remove"
        :confirm-label="__('Remove')"
    />
</div>
