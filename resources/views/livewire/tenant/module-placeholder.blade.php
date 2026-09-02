<div class="flex flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8 overflow-y-auto min-h-0">
    <x-breadcrumbs :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard')],
        ['label' => $module->name],
    ]" />

    <div class="flex flex-1 flex-col items-center justify-center gap-4 rounded-xl border border-neutral-200 p-12 text-center dark:border-neutral-700">
        <flux:heading size="xl">{{ $module->name }}</flux:heading>
        <flux:text>{{ __('This module is coming soon.') }}</flux:text>
    </div>
</div>
