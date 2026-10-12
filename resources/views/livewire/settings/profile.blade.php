<section class="w-full p-4 sm:p-6 lg:p-8 overflow-y-auto min-h-0">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Profile settings') }}</flux:heading>

    <x-settings.layout :heading="__('Profile')" :subheading="__('Update your name and email address')">
        @if ($this->isLockedClientAdmin)
            <flux:callout variant="subtle" icon="lock-closed" class="my-6" :heading="__('Managed by Platform Admin')">
                <flux:callout.text>
                    {{ __('Your account was provisioned by Platform Admin — your name and email can only be changed by them.') }}
                </flux:callout.text>
            </flux:callout>
        @endif

        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" :disabled="$this->isLockedClientAdmin" />

            <div>
                <flux:input wire:model="email" :label="__('Email')" type="email" required autocomplete="email" :disabled="$this->isLockedClientAdmin" />

                @if ($this->hasUnverifiedEmail)
                    <div>
                        <flux:text class="mt-4">
                            {{ __('Your email address is unverified.') }}

                            <flux:link class="text-sm cursor-pointer" wire:click.prevent="resendVerificationNotification">
                                {{ __('Click here to re-send the verification email.') }}
                            </flux:link>
                        </flux:text>

                    </div>
                @endif
            </div>

            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            </div>
        </form>

        @if ($this->showDeleteUser)
            <livewire:settings.delete-user-form />
        @endif
    </x-settings.layout>
</section>
