@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-zinc-50 dark:bg-[#0e1826]">
        <flux:sidebar sticky stashable class="border-e border-zinc-300/70 bg-[#E7E1D2] shadow-[4px_0_16px_-8px_rgba(0,0,0,0.12)] dark:border-zinc-700 dark:bg-gradient-to-b dark:from-[#141414] dark:to-[#0a0a0a] dark:shadow-none">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('admin.dashboard') }}" wire:navigate />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Platform')" class="grid">
                    <flux:sidebar.item icon="home" :href="route('admin.dashboard')" :current="request()->routeIs('admin.dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="users" :href="route('admin.admins.index')" :current="request()->routeIs('admin.admins.*')" wire:navigate>
                        {{ __('Admins') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="shield-check" :href="route('admin.roles.index')" :current="request()->routeIs('admin.roles.*')" wire:navigate>
                        {{ __('Roles') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="building-office-2" :href="route('admin.clients.index')" :current="request()->routeIs('admin.clients.*')" wire:navigate>
                        {{ __('Clients') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="squares-2x2" :href="route('admin.modules.index')" :current="request()->routeIs('admin.modules.*')" wire:navigate>
                        {{ __('Modules') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>
            </flux:sidebar.nav>

            <flux:spacer />

            <x-admin-user-menu class="hidden lg:block" />
        </flux:sidebar>

        <flux:header class="lg:hidden border-b border-zinc-300/70 bg-[#E7E1D2]/90 backdrop-blur-sm dark:border-zinc-700/70 dark:bg-[#0a0a0a]/90">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <div class="flex-1 px-2 min-w-0">
                <span class="block truncate text-base font-semibold text-zinc-900 dark:text-white">
                    {{ $title ?? __('Platform Admin') }}
                </span>
            </div>

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth('platform')->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <div class="px-1 py-1.5" x-data>
                        <flux:radio.group variant="segmented" size="sm" x-model="$flux.appearance" class="w-full">
                            <flux:radio value="light" icon="sun" :aria-label="__('Light')" />
                            <flux:radio value="dark" icon="moon" :aria-label="__('Dark')" />
                            <flux:radio value="system" icon="computer-desktop" :aria-label="__('System')" />
                        </flux:radio.group>
                    </div>
                    <flux:menu.separator />
                    <flux:menu.item :href="route('admin.settings.password')" icon="cog" wire:navigate>
                        {{ __('Change password') }}
                    </flux:menu.item>
                    <flux:menu.separator />
                    <form method="POST" action="{{ route('admin.logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full cursor-pointer">
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
