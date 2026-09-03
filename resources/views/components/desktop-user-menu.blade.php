@php
    $userTypeLabel = auth()->user()->isClientAdmin() ? __('Client Admin') : auth()->user()->role->name;
@endphp

<flux:dropdown position="bottom" align="start">
    {{-- Not flux:sidebar.profile: that component only renders a single-line
    name span, which is too narrow to show name and role stacked without
    one of them getting cut off. This replicates its markup/classes with a
    two-line text block instead. --}}
    <button type="button" data-flux-sidebar-profile data-test="sidebar-menu-button" class="group flex items-center rounded-lg p-1 hover:bg-zinc-800/5 dark:hover:bg-white/10 [ui-dropdown>&]:w-full">
        <div class="shrink-0">
            <flux:avatar :name="auth()->user()->name" :initials="auth()->user()->initials()" size="sm" />
        </div>

        <div class="in-data-flux-sidebar-collapsed-desktop:hidden mx-2 min-w-0 flex-1 text-start leading-tight">
            <span class="block truncate text-sm font-medium text-zinc-500 dark:text-white/80 group-hover:text-zinc-800 dark:group-hover:text-white">
                {{ auth()->user()->name }}
            </span>
            <span class="block truncate text-xs text-zinc-400 dark:text-white/60 group-hover:text-zinc-600 dark:group-hover:text-white/80">
                {{ $userTypeLabel }}
            </span>
        </div>

        <div class="in-data-flux-sidebar-collapsed-desktop:hidden shrink-0 ms-auto size-8 flex justify-center items-center">
            <flux:icon icon="chevrons-up-down" variant="micro" class="text-zinc-400 dark:text-white/80 group-hover:text-zinc-800 dark:group-hover:text-white" />
        </div>
    </button>

    <flux:menu>
        <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
            <flux:avatar
                :name="auth()->user()->name"
                :initials="auth()->user()->initials()"
            />
            <div class="grid flex-1 text-start text-sm leading-tight">
                <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                <flux:text class="truncate text-xs text-zinc-400 dark:text-white/60">{{ $userTypeLabel }}</flux:text>
            </div>
        </div>
        <flux:menu.separator />
        <flux:menu.radio.group>
            <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                {{ __('Settings') }}
            </flux:menu.item>
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
        </flux:menu.radio.group>
    </flux:menu>
</flux:dropdown>
