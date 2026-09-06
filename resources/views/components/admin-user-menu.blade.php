@php
    $userTypeLabel = auth('platform')->user()->isMotherAdmin() ? __('Mother Admin') : (auth('platform')->user()->role->name ?? __('Platform Admin'));
@endphp

<flux:dropdown position="bottom" align="start">
    {{-- Not flux:sidebar.profile: that component only renders a single-line
    name span, which is too narrow to show name and role stacked without
    one of them getting cut off. This replicates its markup/classes with a
    two-line text block instead. --}}
    <button type="button" data-flux-sidebar-profile data-test="sidebar-menu-button" class="group flex items-center rounded-lg p-1 hover:bg-zinc-800/5 dark:hover:bg-white/10 [ui-dropdown>&]:w-full">
        <div class="shrink-0">
            <flux:avatar :name="auth('platform')->user()->name" :initials="auth('platform')->user()->initials()" size="sm" />
        </div>

        {{-- zinc-700/zinc-600, not the vendor-pattern zinc-500/zinc-400:
        against this app's warm parchment sidebar background (light theme),
        those read as muted/washed out. Same fix already applied to
        flux:sidebar.item/group. Dark mode text was already fine. --}}
        <div class="in-data-flux-sidebar-collapsed-desktop:hidden mx-2 min-w-0 flex-1 text-start leading-tight">
            <span class="block truncate text-sm font-medium text-zinc-700 dark:text-white/80 group-hover:text-zinc-800 dark:group-hover:text-white">
                {{ auth('platform')->user()->name }}
            </span>
            <span class="block truncate text-xs text-zinc-600 dark:text-white/60 group-hover:text-zinc-700 dark:group-hover:text-white/80">
                {{ $userTypeLabel }}
            </span>
        </div>

        <div class="in-data-flux-sidebar-collapsed-desktop:hidden shrink-0 ms-auto size-8 flex justify-center items-center">
            <flux:icon icon="chevrons-up-down" variant="micro" class="text-zinc-500 dark:text-white/80 group-hover:text-zinc-800 dark:group-hover:text-white" />
        </div>
    </button>

    <flux:menu>
        <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
            <flux:avatar
                :name="auth('platform')->user()->name"
                :initials="auth('platform')->user()->initials()"
            />
            <div class="grid flex-1 text-start text-sm leading-tight">
                <flux:heading class="truncate">{{ auth('platform')->user()->name }}</flux:heading>
                <flux:text class="truncate">{{ auth('platform')->user()->email }}</flux:text>
                <flux:text class="truncate text-xs text-zinc-400 dark:text-white/60">{{ $userTypeLabel }}</flux:text>
            </div>
        </div>
        <flux:menu.separator />
        <div class="px-1 py-1.5" x-data>
            <flux:radio.group variant="segmented" size="sm" x-model="$flux.appearance" class="w-full">
                <flux:radio value="light" icon="sun" :aria-label="__('Light')" />
                <flux:radio value="dark" icon="moon" :aria-label="__('Dark')" />
                <flux:radio value="system" icon="computer-desktop" :aria-label="__('System')" />
            </flux:radio.group>
        </div>
        <flux:menu.separator />
        <flux:menu.radio.group>
            <flux:menu.item :href="route('admin.settings.password')" icon="cog" wire:navigate>
                {{ __('Change password') }}
            </flux:menu.item>
            <form method="POST" action="{{ route('admin.logout') }}" class="w-full">
                @csrf
                <flux:menu.item
                    as="button"
                    type="submit"
                    icon="arrow-right-start-on-rectangle"
                    class="w-full cursor-pointer"
                    data-test="admin-logout-button"
                >
                    {{ __('Log out') }}
                </flux:menu.item>
            </form>
        </flux:menu.radio.group>
    </flux:menu>
</flux:dropdown>
