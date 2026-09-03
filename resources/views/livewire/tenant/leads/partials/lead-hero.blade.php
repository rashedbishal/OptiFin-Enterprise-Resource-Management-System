@php
    $hasCompany = filled($lead->company);
@endphp

<div class="rounded-xl border border-zinc-200 bg-gradient-to-br from-white to-zinc-50 p-5 dark:border-zinc-700 dark:from-zinc-800 dark:to-zinc-900">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div class="flex flex-col gap-2">
            <div class="flex flex-wrap items-center gap-2">
                <flux:badge size="sm" color="zinc" class="font-mono">{{ $lead->identifier }}</flux:badge>
                <flux:badge size="sm" :color="$lead->status->color()">{{ $lead->status->label() }}</flux:badge>
                <flux:badge size="sm" :color="$lead->priority->color()">{{ $lead->priority->label() }}</flux:badge>
            </div>

            <div class="flex items-center gap-3">
                @if ($lead->avatarUrl())
                    <img src="{{ $lead->avatarUrl() }}" alt="" class="size-11 shrink-0 rounded-full object-cover">
                @else
                    <div class="flex size-11 shrink-0 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-700">
                        <flux:icon :icon="$hasCompany ? 'building-office' : 'user'" class="size-5 text-zinc-500 dark:text-zinc-300" />
                    </div>
                @endif
                <div>
                    <flux:heading size="xl">{{ $hasCompany ? $lead->company : $lead->name }}</flux:heading>
                    <flux:text size="sm" variant="subtle" class="flex flex-wrap items-center gap-x-1.5">
                        @if ($hasCompany)
                            <span class="inline-flex items-center gap-1">
                                <flux:icon.user variant="micro" class="size-3.5" />
                                {{ $lead->name }}
                            </span>
                        @endif
                        @if ($lead->service_interest)
                            <span>&middot; {{ $lead->service_interest }}</span>
                        @endif
                    </flux:text>
                </div>
            </div>
        </div>

        <div class="flex flex-col items-end gap-2">
            <div class="flex gap-2">
                @if ($canDelete)
                    <flux:button type="button" size="sm" variant="danger" icon="trash" wire:click="confirmDelete">
                        {{ __('Delete') }}
                    </flux:button>
                @endif

                <flux:button type="button" size="sm" :href="route('tenant-leads.index')" wire:navigate>
                    {{ __('Back to Leads') }}
                </flux:button>
            </div>

            @if ($lead->deal_value !== null || $lead->win_probability !== null)
                <div class="flex gap-3">
                    @if ($lead->deal_value !== null)
                        <div class="min-w-28 rounded-lg border border-zinc-200 bg-white/70 px-4 py-2 text-center dark:border-zinc-700 dark:bg-zinc-900/40">
                            <flux:text size="xs" variant="subtle" class="uppercase tracking-wide">{{ __('Deal Value') }}</flux:text>
                            <flux:text class="block text-lg font-semibold text-emerald-600 dark:text-emerald-400">
                                {{ $lead->dealValueCurrency?->code }} {{ number_format((float) $lead->deal_value, 0) }}
                            </flux:text>
                        </div>
                    @endif
                    @if ($lead->win_probability !== null)
                        <div class="min-w-28 rounded-lg border border-zinc-200 bg-white/70 px-4 py-2 text-center dark:border-zinc-700 dark:bg-zinc-900/40">
                            <flux:text size="xs" variant="subtle" class="uppercase tracking-wide">{{ __('Lead Score') }}</flux:text>
                            <flux:text class="block text-lg font-semibold text-purple-600 dark:text-purple-400">
                                {{ $lead->win_probability }}%
                            </flux:text>
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>
