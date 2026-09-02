<div class="flex flex-1 flex-col gap-6 md:max-w-md">
        <x-breadcrumbs :items="[
            ['label' => __('Dashboard'), 'href' => route('admin.dashboard')],
            ['label' => __('Change Password')],
        ]" />

        <flux:heading size="xl" class="hidden lg:block">{{ __('Change Password') }}</flux:heading>

        <form wire:submit="updatePassword" class="flex flex-col gap-6">
            <flux:input wire:model="current_password" :label="__('Current password')" type="password" viewable />
            <flux:input wire:model="password" :label="__('New password')" type="password" viewable />
            <flux:input wire:model="password_confirmation" :label="__('Confirm new password')" type="password" viewable />

            <div>
                <flux:button type="submit" variant="primary">{{ __('Update password') }}</flux:button>
            </div>
        </form>
    </div>
