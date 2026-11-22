@php
    $summary = $this->summary;
    $listing = $this->listing;
    $backups = $listing['backups'];
    $newest = $backups->first();
    $healthy = $newest !== null && $newest['date']->gt(now()->subHours(26));
    $megabytes = fn (int|float $bytes): string => number_format($bytes / 1048576, 1).' MB';
@endphp

<div class="flex flex-1 flex-col gap-6 md:max-w-3xl" @if ($this->watching) wire:poll.15s="refreshListing" @endif>
    <x-breadcrumbs :items="[
        ['label' => __('Dashboard'), 'href' => route('admin.dashboard')],
        ['label' => __('Settings')],
        ['label' => __('Backups')],
    ]" />

    <div>
        <flux:heading size="xl" class="hidden lg:block">{{ __('Backups') }}</flux:heading>
        <flux:text class="mt-1">
            {{ __('Every night at 02:00 Bangladesh time the database and uploaded files are backed up to Backblaze B2, password-protected. Kept: every backup for 7 days, then one per day for 30 days, one per week for 3 months, one per month for a year.') }}
        </flux:text>
    </div>

    {{-- Status --}}
    @if (! $summary)
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('Backups are switched off')">
            <flux:callout.text>{{ __('Nothing is backed up until Backblaze B2 is connected below. If this server is lost, all client data is lost with it.') }}</flux:callout.text>
        </flux:callout>
    @elseif ($listing['error'])
        <flux:callout variant="danger" icon="x-circle" :heading="__('Can\'t reach Backblaze B2')">
            <flux:callout.text>{{ $listing['error'] }}</flux:callout.text>
        </flux:callout>
    @elseif ($healthy)
        <flux:callout variant="success" icon="check-circle" :heading="__('Backups are healthy')">
            <flux:callout.text>{{ __('Latest backup :time · :count backups · :size in total.', ['time' => $newest['date']->diffForHumans(), 'count' => $backups->count(), 'size' => $megabytes($backups->sum('size'))]) }}</flux:callout.text>
        </flux:callout>
    @else
        <flux:callout variant="danger" icon="x-circle" :heading="$newest ? __('The latest backup is too old') : __('No backup yet')">
            <flux:callout.text>
                {{ $newest ? __('The newest backup is from :time. A backup should run every night — check the alert emails or press "Back up now".', ['time' => $newest['date']->diffForHumans()]) : __('Press "Back up now" to make the first one, or wait for tonight\'s run.') }}
            </flux:callout.text>
        </flux:callout>
    @endif

    {{-- Storage settings --}}
    <form wire:submit="save" class="flex flex-col gap-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <flux:heading size="lg">{{ __('Backblaze B2 storage') }}</flux:heading>
                <flux:text class="mt-1 text-sm">{{ __('From your Backblaze account: the bucket page (name + endpoint) and Application Keys (key ID + key).') }}</flux:text>
            </div>
            <flux:badge size="sm" :color="$summary ? 'green' : 'zinc'">{{ $summary ? __('Connected') : __('Not set up') }}</flux:badge>
        </div>

        @if ($summary)
            <flux:text class="text-xs">
                {{ __('Last changed :time', ['time' => $summary['updated_at']?->diffForHumans()]) }}@if ($summary['updated_by']) {{ __('by :name', ['name' => $summary['updated_by']]) }}@endif
            </flux:text>
        @endif

        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input wire:model="bucket" :label="__('Bucket name')" :placeholder="__('e.g. optifin-backups')" />
            <flux:input wire:model="endpoint" :label="__('Endpoint')" :placeholder="__('e.g. s3.us-west-004.backblazeb2.com')" />
            <flux:input wire:model="keyId" :label="__('Key ID')" autocomplete="off" />
            <flux:input
                wire:model="applicationKey"
                type="password"
                :label="__('Application key')"
                :description="$summary ? __('Saved. Leave empty to keep it.') : __('Shown only once when you create the key in Backblaze.')"
                :placeholder="$summary ? '••••••••••••••••' : ''"
                autocomplete="new-password"
                viewable
            />
        </div>

        <flux:separator />

        <div>
            <flux:heading size="sm">{{ __('Backup password') }}</flux:heading>
            <flux:text class="mt-1 text-sm">{{ __('Every backup file is encrypted with this password. At least 12 characters.') }}</flux:text>
        </div>

        <flux:callout variant="warning" icon="key">
            <flux:callout.text>
                <strong>{{ __('Save this password in a password manager now.') }}</strong>
                {{ __('Without it, no backup can ever be opened — not even by you. If you change it later, older backups still need the old password, so keep that one too.') }}
            </flux:callout.text>
        </flux:callout>

        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input
                wire:model="password"
                type="password"
                :label="$summary ? __('New backup password') : __('Backup password')"
                :description="$summary ? __('Saved. Leave empty to keep it.') : null"
                autocomplete="new-password"
                viewable
            />
            <flux:input wire:model="passwordConfirmation" type="password" :label="__('Repeat the password')" autocomplete="new-password" viewable />
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">{{ __('Save') }}</flux:button>
            @if ($summary)
                <flux:button type="button" icon="signal" wire:click="testConnection" wire:loading.attr="disabled" wire:target="testConnection">{{ __('Test connection') }}</flux:button>
                <flux:button type="button" variant="danger" wire:click="confirmRemove">{{ __('Remove') }}</flux:button>
            @endif
        </div>
    </form>

    {{-- Restoring needs APP_KEY too --}}
    <flux:callout icon="information-circle" :heading="__('Also keep the app key safe')">
        <flux:callout.text>
            {{ __('Some data inside the backups (Meta tokens, email passwords) is encrypted with the server\'s APP_KEY, which is not in the backups. Copy the APP_KEY line from the server\'s .env file into your password manager once. A restore needs both: the backup password and the APP_KEY.') }}
        </flux:callout.text>
    </flux:callout>

    {{-- Backups list --}}
    @if ($summary)
        <flux:card>
            <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                <div>
                    <flux:heading size="lg">{{ __('Backups on Backblaze B2') }}</flux:heading>
                    @if ($this->watching)
                        <flux:text class="mt-1 text-sm">{{ __('A backup is running — this list refreshes automatically.') }}</flux:text>
                    @endif
                </div>
                <flux:button variant="primary" icon="arrow-up-tray" wire:click="backUpNow" wire:loading.attr="disabled" wire:target="backUpNow" data-test="back-up-now">{{ __('Back up now') }}</flux:button>
            </div>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Made') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Size') }}</flux:table.column>
                    <flux:table.column align="end"></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($backups as $backup)
                        <flux:table.row wire:key="backup-{{ md5($backup['path']) }}" data-test="backup-row">
                            <flux:table.cell>
                                <div class="font-medium text-zinc-800 dark:text-white">{{ $backup['date']->toDayDateTimeString() }}</div>
                                <div class="text-xs text-zinc-500">{{ $backup['date']->diffForHumans() }}</div>
                            </flux:table.cell>
                            <flux:table.cell align="end" class="whitespace-nowrap">{{ $megabytes($backup['size']) }}</flux:table.cell>
                            <flux:table.cell align="end" class="whitespace-nowrap">
                                <flux:button size="sm" variant="ghost" icon="arrow-down-tray" wire:click="download(@js($backup['path']))">{{ __('Download') }}</flux:button>
                                <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDelete(@js($backup['path']))" :aria-label="__('Delete')" />
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="3">{{ $listing['error'] ? __('The list can\'t be shown while Backblaze B2 is unreachable.') : __('No backups yet.') }}</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </flux:card>
    @endif

    <x-confirm-modal
        wire:model.self="showDeleteModal"
        :title="__('Delete this backup?')"
        :description="__('It is removed from Backblaze B2 permanently. Other backups are not affected.')"
        confirm-action="deleteConfirmed"
        :confirm-label="__('Delete')"
    />

    <x-confirm-modal
        wire:model.self="showRemoveModal"
        :title="__('Remove the backup settings?')"
        :description="__('Nightly backups stop. Backups already on Backblaze B2 are kept there.')"
        confirm-action="remove"
        :confirm-label="__('Remove')"
    />
</div>
