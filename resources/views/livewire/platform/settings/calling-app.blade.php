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

    <x-confirm-modal
        wire:model.self="showRemoveModal"
        :title="__('Remove the Firebase key?')"
        :description="__('The calling app will stop ringing for incoming calls on every company\'s phones. Calls keep ringing in the browser.')"
        confirm-action="remove"
        :confirm-label="__('Remove')"
    />
</div>
