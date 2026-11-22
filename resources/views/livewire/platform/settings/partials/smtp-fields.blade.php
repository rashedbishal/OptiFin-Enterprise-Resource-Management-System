{{-- Shared SMTP fields for one Email settings account. $field = 'user' | 'alerts'; $saved = whether a password is already stored. --}}
@php
    $encryptionOptions = [
        ['value' => 'tls', 'label' => __('TLS / STARTTLS (port 587 — Gmail)')],
        ['value' => 'ssl', 'label' => __('SSL (port 465)')],
        ['value' => 'none', 'label' => __('None')],
    ];
@endphp

<div class="grid gap-4 sm:grid-cols-2">
    <flux:input wire:model="{{ $field }}.from_address" type="email" :label="__('Sender address')" :placeholder="__('e.g. optifin.alerts@gmail.com')" />
    <flux:input wire:model="{{ $field }}.from_name" :label="__('Sender name')" />
    <flux:input wire:model="{{ $field }}.username" :label="__('Username')" :description="__('For Gmail: the full Gmail address.')" autocomplete="off" />
    <flux:input
        wire:model="{{ $field }}.password"
        type="password"
        :label="__('Password')"
        :description="$saved ? __('Saved. Leave empty to keep the current password.') : __('For Gmail: the 16-letter app password.')"
        :placeholder="$saved ? '••••••••••••••••' : ''"
        autocomplete="new-password"
        viewable
    />
</div>

<details class="text-sm" @if ($errors->hasAny([$field.'.host', $field.'.port', $field.'.encryption'])) open @endif>
    <summary class="cursor-pointer text-zinc-500">{{ __('Server settings (already set for Gmail)') }}</summary>
    <div class="mt-3 grid gap-4 sm:grid-cols-3">
        <div class="sm:col-span-2">
            <flux:input wire:model="{{ $field }}.host" :label="__('SMTP host')" />
        </div>
        <flux:input wire:model="{{ $field }}.port" type="number" :label="__('Port')" />
        <div class="sm:col-span-3">
            <x-dropdown-select wire:model="{{ $field }}.encryption" :label="__('Encryption')" :options="$encryptionOptions" />
        </div>
    </div>
</details>
