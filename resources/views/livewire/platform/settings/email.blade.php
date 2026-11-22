@php
    use App\Services\MailSettings;

    $userSaved = $this->summaries[MailSettings::USER_EMAILS];
    $alertsSaved = $this->summaries[MailSettings::MONITORING_ALERTS];
    // What the server's own (.env) settings do while an account isn't saved.
    $serverMailIsLog = in_array(config('mail.default'), ['log', 'array'], true);
    $serverAlertAddress = filter_var(config('ops.alert_email'), FILTER_VALIDATE_EMAIL) ? config('ops.alert_email') : null;
@endphp

<div class="flex flex-1 flex-col gap-6 md:max-w-3xl">
    <x-breadcrumbs :items="[
        ['label' => __('Dashboard'), 'href' => route('admin.dashboard')],
        ['label' => __('Settings')],
        ['label' => __('Email settings')],
    ]" />

    <div>
        <flux:heading size="xl" class="hidden lg:block">{{ __('Email settings') }}</flux:heading>
        <flux:text class="mt-1">
            {{ __('The email accounts OptiFin sends from. Each account only takes effect once saved — until then, that kind of email follows the server\'s default setting. Passwords are stored encrypted and never shown again.') }}
        </flux:text>
    </div>

    {{-- 1. User emails --}}
    <form wire:submit="saveUser" class="flex flex-col gap-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" data-test="user-emails">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <flux:heading size="lg">{{ __('User emails') }}</flux:heading>
                <flux:text class="mt-1 text-sm">{{ __('Password reset emails, sent to the user who asked for them.') }}</flux:text>
            </div>
            <flux:badge size="sm" :color="$userSaved ? 'green' : 'zinc'">{{ $userSaved ? __('Active') : __('Not set up') }}</flux:badge>
        </div>

        @if ($userSaved)
            <flux:text class="text-xs">
                {{ __('Last changed :time', ['time' => $userSaved['updated_at']?->diffForHumans()]) }}@if ($userSaved['updated_by']) {{ __('by :name', ['name' => $userSaved['updated_by']]) }}@endif
            </flux:text>
        @else
            <flux:callout variant="warning" icon="exclamation-triangle" :heading="$serverMailIsLog ? __('Not set up — password reset emails are not delivered') : __('Not set up')">
                <flux:callout.text>
                    {{ $serverMailIsLog
                        ? __('Until this is saved, password reset emails follow the server\'s default setting, which currently writes them to a log file instead of sending them.')
                        : __('Until this is saved, password reset emails are sent with the server\'s default mail setting.') }}
                </flux:callout.text>
            </flux:callout>
        @endif

        @include('livewire.platform.settings.partials.smtp-fields', ['field' => 'user', 'saved' => (bool) $userSaved])

        <div class="flex flex-wrap items-center gap-3">
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveUser">{{ __('Save') }}</flux:button>
            @if ($userSaved)
                <flux:button type="button" variant="danger" wire:click="confirmRemove('{{ MailSettings::USER_EMAILS }}')">{{ __('Remove') }}</flux:button>
            @endif
        </div>

        @if ($userSaved)
            <flux:separator />
            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-64 flex-1">
                    <flux:input wire:model="userTestTo" type="email" :label="__('Send a test email to')" />
                </div>
                <flux:button type="button" icon="paper-airplane" wire:click="sendUserTest" wire:loading.attr="disabled" wire:target="sendUserTest">{{ __('Send test') }}</flux:button>
            </div>
        @endif
    </form>

    {{-- 2. Monitoring alerts --}}
    <form wire:submit="saveAlerts" class="flex flex-col gap-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" data-test="monitoring-alerts">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <flux:heading size="lg">{{ __('Monitoring alerts') }}</flux:heading>
                <flux:text class="mt-1 text-sm">{{ __('Alerts about failed scheduled tasks, email-inbox outages and failing health checks.') }}</flux:text>
            </div>
            <flux:badge size="sm" :color="$alertsSaved ? 'green' : 'zinc'">{{ $alertsSaved ? __('Active') : __('Not set up') }}</flux:badge>
        </div>

        @if ($alertsSaved)
            <flux:text class="text-xs">
                {{ __('Last changed :time', ['time' => $alertsSaved['updated_at']?->diffForHumans()]) }}@if ($alertsSaved['updated_by']) {{ __('by :name', ['name' => $alertsSaved['updated_by']]) }}@endif
            </flux:text>
        @else
            <flux:callout variant="warning" icon="exclamation-triangle" :heading="$serverAlertAddress ? __('Not set up') : __('Not set up — no monitoring alerts are emailed')">
                <flux:callout.text>
                    {{ $serverAlertAddress
                        ? __('Until this is saved, alerts go to :address using the server\'s default mail setting.', ['address' => $serverAlertAddress])
                        : __('Until this is saved, alerts follow the server\'s default setting, which currently sends none. Problems are still recorded in the logs and on the Health page.') }}
                </flux:callout.text>
            </flux:callout>
        @endif

        <flux:textarea
            wire:model="alerts.recipients"
            rows="3"
            :label="__('Send alerts to')"
            :description="__('One email address per line (or separated by commas). Up to 20.')"
            :placeholder="__('you@example.com')"
        />

        @include('livewire.platform.settings.partials.smtp-fields', ['field' => 'alerts', 'saved' => (bool) $alertsSaved])

        <div class="flex flex-wrap items-center gap-3">
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveAlerts">{{ __('Save') }}</flux:button>
            @if ($alertsSaved)
                <flux:button type="button" icon="paper-airplane" wire:click="sendAlertsTest" wire:loading.attr="disabled" wire:target="sendAlertsTest">{{ __('Send test to the alert addresses') }}</flux:button>
                <flux:button type="button" variant="danger" wire:click="confirmRemove('{{ MailSettings::MONITORING_ALERTS }}')">{{ __('Remove') }}</flux:button>
            @endif
        </div>
    </form>

    <x-confirm-modal
        wire:model.self="showRemoveModal"
        :title="__('Remove this email account?')"
        :description="$confirmingRemove === MailSettings::USER_EMAILS
            ? __('Password reset emails will go back to the server\'s default mail setting.')
            : __('Monitoring alerts will go back to the server\'s default setting.')"
        confirm-action="remove"
        :confirm-label="__('Remove')"
    />
</div>
