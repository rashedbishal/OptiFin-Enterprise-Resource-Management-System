<div class="relative mb-6 w-full">
    <x-breadcrumbs :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard')],
        ['label' => __('Settings'), 'href' => route('profile.edit')],
        ['label' => match (true) {
            request()->routeIs('security.edit') => __('Security'),
            request()->routeIs('appearance.edit') => __('Appearance'),
            default => __('Profile'),
        }],
    ]" />

    <flux:heading size="xl" level="1" class="hidden lg:block">{{ __('Settings') }}</flux:heading>
    <flux:subheading size="lg" class="mb-6">{{ __('Manage your profile and account settings') }}</flux:subheading>
    <flux:separator variant="subtle" />
</div>
