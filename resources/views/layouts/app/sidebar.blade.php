@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    {{-- Bounded to the viewport (not just min-height) so the body's grid
    row for `main` is a real, capped size rather than growing with page
    content — that's what lets `main` become its own scroll region below
    instead of the whole document (sidebar included) scrolling. --}}
    <body class="h-dvh overflow-hidden bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Workspace')" class="grid">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>

                    @auth
                        @foreach (auth()->user()->tenant?->activeModules ?? [] as $module)
                            @if (auth()->user()->hasModulePermission($module->slug, 'view'))
                                @if ($module->slug === 'lead-management')
                                    <flux:sidebar.item icon="chat-bubble-left-right" :href="route('tenant-leads.index')" :current="request()->routeIs('tenant-leads.*', 'tenant-lead-sources.*')" wire:navigate>
                                        {{ $module->name }}
                                    </flux:sidebar.item>
                                @else
                                    @php
                                        $moduleIcon = match ($module->slug) {
                                            'finance-accounting' => 'banknotes',
                                            'sales-crm' => 'chart-bar',
                                            'procurement' => 'shopping-cart',
                                            'inventory-management' => 'archive-box',
                                            'human-resource-management' => 'user-group',
                                            'payroll-management' => 'credit-card',
                                            'fixed-assets-management' => 'building-office-2',
                                            default => 'squares-2x2',
                                        };
                                    @endphp
                                    <flux:sidebar.item :icon="$moduleIcon" :href="route('modules.show', $module)" :current="request()->routeIs('modules.show') && request()->route('module')?->id === $module->id" wire:navigate>
                                        {{ $module->name }}
                                    </flux:sidebar.item>
                                @endif
                            @endif
                        @endforeach

                        @if (auth()->user()->isClientAdmin())
                            <flux:sidebar.item icon="users" :href="route('tenant-users.index')" :current="request()->routeIs('tenant-users.index')" wire:navigate>
                                {{ __('Users') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="shield-check" :href="route('tenant-roles.index')" :current="request()->routeIs('tenant-roles.index')" wire:navigate>
                                {{ __('Roles') }}
                            </flux:sidebar.item>
                            @if (auth()->user()->tenant?->hasModule('lead-management'))
                                <flux:sidebar.item icon="signal" :href="route('tenant-lead-sources.index')" :current="request()->routeIs('tenant-lead-sources.index')" wire:navigate>
                                    {{ __('Lead Sources') }}
                                </flux:sidebar.item>
                            @endif
                        @endif
                    @endauth
                </flux:sidebar.group>
            </flux:sidebar.nav>

            <flux:spacer />

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Header -->
        <flux:header class="lg:hidden border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <div class="flex-1 px-2 min-w-0">
                <span class="block truncate text-base font-semibold text-zinc-900 dark:text-white">
                    {{ $title ?? (auth()->user()?->tenant?->name ?? config('app.name')) }}
                </span>
            </div>

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                    <flux:text class="truncate text-xs text-zinc-400 dark:text-white/60">
                                        {{ auth()->user()->isClientAdmin() ? __('Client Admin') : auth()->user()->role->name }}
                                    </flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
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
