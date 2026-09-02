<div class="flex flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8 overflow-y-auto min-h-0">
        <x-breadcrumbs :items="[['label' => __('Dashboard')]]" />

        <flux:heading size="xl" class="hidden lg:block">{{ $this->tenant->name }}</flux:heading>

        <div class="grid auto-rows-min gap-4 md:grid-cols-3">
            <flux:card>
                <flux:text>{{ __('Assigned Modules') }}</flux:text>
                <flux:heading size="xl">{{ $this->assignedModules->count() }}</flux:heading>
            </flux:card>
            <flux:card>
                <flux:text>{{ __('Total Users') }}</flux:text>
                <flux:heading size="xl">{{ $this->totalUsers }}</flux:heading>
            </flux:card>
            <flux:card>
                <flux:text>{{ __('Total Roles') }}</flux:text>
                <flux:heading size="xl">{{ $this->totalRoles }}</flux:heading>
            </flux:card>
        </div>

        <flux:card>
            <flux:heading size="lg" class="mb-4">{{ __('Your Modules') }}</flux:heading>
            <div class="flex flex-wrap gap-2">
                @forelse ($this->assignedModules as $module)
                    <flux:badge>{{ $module->name }}</flux:badge>
                @empty
                    <flux:text>{{ __('No modules have been assigned to your organization yet.') }}</flux:text>
                @endforelse
            </div>
        </flux:card>
    </div>
