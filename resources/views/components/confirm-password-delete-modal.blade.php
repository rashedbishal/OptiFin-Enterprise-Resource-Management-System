@props([
    'title' => null,
    'description' => null,
    'confirmAction' => 'deleteConfirmed',
    'closeAction' => 'closeDeleteModal',
])

@php
    $title ??= __('Delete this lead?');
    $description ??= __('This permanently deletes the lead and its history. Enter your password to confirm.');
@endphp

<flux:modal {{ $attributes->whereStartsWith('wire:model') }} class="md:w-96">
    <form wire:submit="{{ $confirmAction }}" class="flex flex-col gap-4">
        <div>
            <flux:heading size="lg">{{ $title }}</flux:heading>
            <flux:text class="mt-2">{{ $description }}</flux:text>
        </div>

        <flux:input
            wire:model="deletePassword"
            type="password"
            :label="__('Your password')"
            autocomplete="current-password"
            viewable
            autofocus
        />

        <div class="flex justify-end gap-2">
            <flux:button type="button" wire:click="{{ $closeAction }}">{{ __('Cancel') }}</flux:button>
            <flux:button type="submit" variant="danger">{{ __('Delete') }}</flux:button>
        </div>
    </form>
</flux:modal>
