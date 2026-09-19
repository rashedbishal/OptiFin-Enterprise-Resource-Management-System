@props([
    'title',
    'description' => null,
    'confirmAction',
    'confirmLabel' => null,
    'cancelLabel' => null,
    'variant' => 'danger',
])

@php
    $wireModel = $attributes->wire('model.self')->value();
    $confirmLabel ??= __('Confirm');
    $cancelLabel ??= __('Cancel');
@endphp

<flux:modal {{ $attributes->whereStartsWith('wire:model') }} class="md:w-96">
    <div class="flex flex-col gap-4">
        <div>
            <flux:heading size="lg">{{ $title }}</flux:heading>
            @if ($description)
                <flux:text class="mt-2">{{ $description }}</flux:text>
            @endif
        </div>

        {{ $slot ?? '' }}

        <div class="flex justify-end gap-2">
            <flux:button type="button" wire:click="$set('{{ $wireModel }}', false)">{{ $cancelLabel }}</flux:button>
            <flux:button type="button" variant="{{ $variant }}" wire:click="{{ $confirmAction }}" wire:loading.attr="disabled">{{ $confirmLabel }}</flux:button>
        </div>
    </div>
</flux:modal>
