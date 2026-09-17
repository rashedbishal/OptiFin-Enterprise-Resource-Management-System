@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand :name="config('app.name', 'Laravel')" {{ $attributes }}>
        <x-slot name="logo" class="flex h-6 items-center justify-center">
            <img src="{{ asset('img/logo-mark.png') }}" alt="{{ config('app.name', 'Laravel') }} logo" class="h-6 w-auto" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="config('app.name', 'Laravel')" {{ $attributes }}>
        <x-slot name="logo" class="flex h-6 items-center justify-center">
            <img src="{{ asset('img/logo-mark.png') }}" alt="{{ config('app.name', 'Laravel') }} logo" class="h-6 w-auto" />
        </x-slot>
    </flux:brand>
@endif
