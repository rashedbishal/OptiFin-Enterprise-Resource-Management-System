<flux:dropdown position="bottom" align="start">
    <flux:sidebar.profile
        :name="auth('platform')->user()->name"
        :initials="auth('platform')->user()->initials()"
        icon:trailing="chevrons-up-down"
        data-test="sidebar-menu-button"
    />

    <flux:menu>
        <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
            <flux:avatar
                :name="auth('platform')->user()->name"
                :initials="auth('platform')->user()->initials()"
            />
            <div class="grid flex-1 text-start text-sm leading-tight">
                <flux:heading class="truncate">{{ auth('platform')->user()->name }}</flux:heading>
                <flux:text class="truncate">{{ auth('platform')->user()->email }}</flux:text>
            </div>
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
