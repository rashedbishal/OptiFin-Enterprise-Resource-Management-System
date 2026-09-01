@php
    $title = __('Access Denied');

    $homeRoute = match (true) {
        auth('platform')->check() && Route::has('admin.dashboard') => route('admin.dashboard'),
        auth('web')->check() && Route::has('dashboard') => route('dashboard'),
        default => url('/'),
    };
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-linear-to-b dark:from-neutral-950 dark:to-neutral-900">
        <div class="flex min-h-svh flex-col items-center justify-center gap-6 p-6 text-center md:p-10">
            <div class="flex size-16 items-center justify-center rounded-full bg-red-50 dark:bg-red-500/10">
                <flux:icon.lock-closed class="size-8 text-red-500" />
            </div>

            <div class="flex max-w-sm flex-col gap-2">
                <flux:heading size="xl">{{ __('Access Denied') }}</flux:heading>
                <flux:text>
                    {{ __("You don't have permission to do that. If you think this is a mistake, reach out to your account admin.") }}
                </flux:text>
            </div>

            <flux:button variant="primary" :href="$homeRoute" wire:navigate>
                {{ __('Back to Dashboard') }}
            </flux:button>
        </div>

        @fluxScripts
    </body>
</html>
