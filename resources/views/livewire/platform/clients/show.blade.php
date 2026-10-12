<div class="flex flex-1 flex-col gap-6">
        <x-breadcrumbs :items="[
            ['label' => __('Dashboard'), 'href' => route('admin.dashboard')],
            ['label' => __('Clients'), 'href' => route('admin.clients.index')],
            ['label' => $tenant->name],
        ]" />

        <div class="flex items-center justify-between">
            <div>
                <flux:heading size="xl" class="hidden lg:block">{{ $tenant->name }}</flux:heading>
                <flux:text>{{ $tenant->tenant_code }}</flux:text>
            </div>

            <div class="flex items-center gap-2">
                @php
                    $tenantStatusOpts = collect(['active', 'inactive', 'suspended'])->map(fn($s) => ['value' => $s, 'label' => ucfirst($s)])->toArray();
                @endphp
                <div class="w-40">
                    <x-dropdown-select wire:model="status" :options="$tenantStatusOpts" />
                </div>
                <flux:button size="sm" wire:click="updateStatus">{{ __('Update') }}</flux:button>
            </div>
        </div>

        @if ($this->clientAdmins->isNotEmpty())
            <flux:card>
                <flux:heading size="lg" class="mb-4">{{ __('Client Admin') }}</flux:heading>
                <flux:text class="mb-4">
                    {{ __('Deactivating suspends the Client Admin and every other user in this tenant together; reactivating restores exactly the users this suspended.') }}
                </flux:text>
                <div class="flex flex-col gap-3">
                    @foreach ($this->clientAdmins as $admin)
                        <div class="flex items-center justify-between gap-3 rounded-lg border border-zinc-200/80 p-3 dark:border-zinc-700/80">
                            <div>
                                <flux:text class="font-medium">{{ $admin->name }}</flux:text>
                                <flux:text size="sm" variant="subtle">{{ $admin->email }}</flux:text>
                            </div>
                            <flux:badge :color="$admin->is_active ? 'green' : 'zinc'" size="sm">
                                {{ $admin->is_active ? __('Active') : __('Inactive') }}
                            </flux:badge>
                        </div>
                    @endforeach
                </div>
                <div class="mt-4 flex justify-end gap-2">
                    @if ($this->anyClientAdminActive)
                        <flux:button size="sm" variant="danger" wire:click="confirmDeactivateClientAdmin">{{ __('Deactivate') }}</flux:button>
                    @else
                        <flux:button size="sm" wire:click="activateClientAdmin">{{ __('Activate') }}</flux:button>

                        @if (auth()->guard('platform')->user()->isMotherAdmin())
                            <flux:button size="sm" variant="danger" wire:click="confirmDeleteTenant">{{ __('Delete Permanently') }}</flux:button>
                        @endif
                    @endif
                </div>
            </flux:card>
        @endif

        <flux:card>
            <flux:heading size="lg" class="mb-4">{{ __('Assigned Modules') }}</flux:heading>
            <div class="grid grid-cols-2 gap-3 md:grid-cols-3">
                @foreach ($this->modules as $module)
                    <flux:checkbox
                        wire:click="toggleModule('{{ $module->id }}')"
                        :checked="in_array($module->id, $this->assignedModuleIds, true)"
                        :label="$module->name"
                    />
                @endforeach
            </div>
        </flux:card>

        <flux:card>
            <flux:heading size="lg" class="mb-4">{{ __('Configure Modules') }}</flux:heading>
            <flux:text class="mb-4">{{ __('Only assigned modules can be configured for this client.') }}</flux:text>
            <div class="grid grid-cols-2 gap-3 md:grid-cols-3">
                @foreach ($this->modules as $module)
                    @php $configUrl = $this->moduleConfigUrl($module); @endphp
                    @if ($configUrl)
                        <flux:button :href="$configUrl" :disabled="! $this->isModuleAssigned($module)" wire:navigate>
                            {{ $module->name }}
                        </flux:button>
                    @else
                        <flux:button wire:click="moduleNotConfigurable" :disabled="! $this->isModuleAssigned($module)">
                            {{ $module->name }}
                        </flux:button>
                    @endif
                @endforeach
            </div>
        </flux:card>

        <x-confirm-modal
            wire:model.self="showDeactivateClientAdminModal"
            :title="__('Deactivate this tenant\'s Client Admin?')"
            :description="__('This immediately suspends the Client Admin and every other active user in this tenant together. Reactivating later restores exactly the users this action suspends.')"
            confirm-action="deactivateClientAdminConfirmed"
            :confirm-label="__('Deactivate')"
        />

        {{-- Permanent tenant deletion — Mother Admin only, two separate
        password confirmations (not the shared x-confirm-password-delete-
        modal component, which hardcodes a single "deletePassword" field
        already used elsewhere for lead deletion). Step 1 warns and takes
        the first password; only on success does step 2 open, asking for
        the password again as the final, authoritative confirmation. --}}
        <flux:modal wire:model.self="showDeleteTenantStep1Modal" class="md:w-96">
            <form wire:submit="deleteTenantStep1Confirmed" class="flex flex-col gap-4">
                <div>
                    <flux:heading size="lg">{{ __('Permanently delete :name?', ['name' => $tenant->name]) }}</flux:heading>
                    <flux:text class="mt-2">
                        {{ __('This erases this client\'s entire tenant — every user, lead, message, attachment, and file — with no way to recover it. Enter your password to continue.') }}
                    </flux:text>
                </div>

                <flux:input
                    wire:model="deleteTenantPassword1"
                    type="password"
                    :label="__('Your password')"
                    autocomplete="current-password"
                    viewable
                    autofocus
                />

                <div class="flex justify-end gap-2">
                    <flux:button type="button" wire:click="closeDeleteTenantStep1Modal">{{ __('Cancel') }}</flux:button>
                    <flux:button type="submit" variant="danger">{{ __('Continue') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal wire:model.self="showDeleteTenantStep2Modal" class="md:w-96">
            <form wire:submit="deleteTenantStep2Confirmed" class="flex flex-col gap-4">
                <div>
                    <flux:heading size="lg">{{ __('Final confirmation') }}</flux:heading>
                    <flux:text class="mt-2">
                        {{ __('Last chance — this cannot be undone. Enter your password one more time to permanently delete :name.', ['name' => $tenant->name]) }}
                    </flux:text>
                </div>

                <flux:input
                    wire:model="deleteTenantPassword2"
                    type="password"
                    :label="__('Your password, again')"
                    autocomplete="current-password"
                    viewable
                    autofocus
                />

                <div class="flex justify-end gap-2">
                    <flux:button type="button" wire:click="closeDeleteTenantStep2Modal">{{ __('Cancel') }}</flux:button>
                    <flux:button type="submit" variant="danger">{{ __('Delete Permanently') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    </div>
