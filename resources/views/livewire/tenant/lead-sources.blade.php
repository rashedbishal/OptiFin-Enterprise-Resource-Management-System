@include('partials.lead-management-breadcrumbs', ['leadManagementTrail' => [['label' => __('Lead Sources')]]])

<div class="flex flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8 overflow-y-auto min-h-0" wire:init="completeInitialLoad">
    <flux:heading size="xl" class="hidden lg:block">{{ __('Lead Sources') }}</flux:heading>
    <flux:text>{{ __('Configured by your account manager. Contact support to add or change a source.') }}</flux:text>

    @php
        // No filters on this page — completeInitialLoad() (fired once via
        // wire:init above) is the only thing that ever re-queries
        // $this->leadSources, so it's the whole target list. See
        // leads.blade.php's identical $showSkeletonByDefault for why the
        // skeleton's/real content's STATIC class (not just their
        // wire:loading toggle) has to be conditional on server-known state
        // — wire:loading's JS toggle only takes effect once a request is
        // actually in flight, leaving a gap on the very first paint where
        // neither side's toggle has run yet.
        $listLoadingTargets = 'completeInitialLoad';
        $showSkeletonByDefault = ! $initialLoadComplete;
    @endphp

    {{-- Mobile Card Stack (sm:hidden) — the table below scrolls
    horizontally on a phone (4 columns, one of them a webhook URL), which
    reads badly at that width; the same real/skeleton pair as everywhere
    else, just card-shaped instead of row-shaped. --}}
    <div class="flex flex-col gap-2.5 sm:hidden">
        <div wire:loading.class="!hidden" wire:target="{{ $listLoadingTargets }}" class="{{ $showSkeletonByDefault ? '!hidden' : '' }} contents">
            @forelse ($this->leadSources as $source)
                <div wire:key="lead-source-card-{{ $source->id }}" class="flex flex-col gap-2 rounded-lg border border-zinc-200/80 bg-zinc-50 p-3 dark:border-zinc-700/80 dark:bg-zinc-900/90">
                    <div class="flex items-start justify-between gap-2">
                        <span class="font-medium text-zinc-900 dark:text-white">{{ $source->name }}</span>
                        <flux:badge :color="$source->is_active ? 'green' : 'zinc'" size="sm">
                            {{ $source->is_active ? __('Active') : __('Inactive') }}
                        </flux:badge>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <flux:badge size="sm" icon="{{ $source->type->icon() }}">{{ $source->type->label() }}</flux:badge>
                        @if ($url = $source->webhookUrl())
                            <flux:text class="min-w-0 truncate font-mono text-xs">{{ $url }}</flux:text>
                        @else
                            <flux:text class="text-xs" variant="subtle">{{ __('Polled automatically') }}</flux:text>
                        @endif
                    </div>
                    @if ($this->isClientAdmin && $this->rolesEligibleForUnassigned->isNotEmpty())
                        @include('livewire.tenant.lead-sources.partials.unassigned-role-chips', ['source' => $source])
                    @endif
                </div>
            @empty
                <div class="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-zinc-200 p-8 text-center dark:border-zinc-700">
                    <flux:icon.inbox class="size-6 text-zinc-400" />
                    <flux:text variant="subtle">{{ __('No lead sources configured yet.') }}</flux:text>
                </div>
            @endforelse
        </div>

        <div class="{{ $showSkeletonByDefault ? '' : '!hidden' }} flex flex-col gap-2.5" wire:loading.remove.class="!hidden" wire:target="{{ $listLoadingTargets }}">
            @for ($i = 0; $i < 3; $i++)
                <div wire:key="lead-source-card-skeleton-{{ $i }}" class="flex flex-col gap-2 rounded-lg border border-zinc-200/80 bg-zinc-50 p-3 dark:border-zinc-700/80 dark:bg-zinc-900/90">
                    <div class="flex items-start justify-between gap-2">
                        <div class="h-3.5 w-28 skeleton-shimmer rounded"></div>
                        <div class="h-5 w-16 skeleton-shimmer rounded-full"></div>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <div class="h-5 w-20 skeleton-shimmer rounded-full"></div>
                        <div class="h-3 w-24 skeleton-shimmer rounded"></div>
                    </div>
                </div>
            @endfor
        </div>
    </div>

    {{-- Desktop / Tablet Table View (hidden sm:block) --}}
    @php
        // Client-Admin-only column — see LeadSourcePolicy::update()'s own
        // docblock for why this is the one other deliberate mutation
        // exception on an otherwise read-only page. Drives both the extra
        // <flux:table.column> below and every skeleton/empty row's colspan,
        // so they never drift out of sync with each other.
        $showUnassignedColumn = $this->isClientAdmin;
        $columnCount = $showUnassignedColumn ? 5 : 4;
    @endphp
    <div class="hidden sm:block">
    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Type') }}</flux:table.column>
            <flux:table.column>{{ __('Webhook URL') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            @if ($showUnassignedColumn)
                <flux:table.column>{{ __('Receives Unassigned Leads') }}</flux:table.column>
            @endif
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->leadSources as $source)
                <flux:table.row wire:key="lead-source-{{ $source->id }}" wire:loading.class="!hidden" wire:target="{{ $listLoadingTargets }}" @class(['!hidden' => $showSkeletonByDefault])>
                    <flux:table.cell>{{ $source->name }}</flux:table.cell>
                    <flux:table.cell><flux:badge size="sm" icon="{{ $source->type->icon() }}">{{ $source->type->label() }}</flux:badge></flux:table.cell>
                    <flux:table.cell>
                        @if ($url = $source->webhookUrl())
                            <flux:text class="font-mono text-xs">{{ $url }}</flux:text>
                        @else
                            <flux:text class="text-xs">{{ __('Polled automatically') }}</flux:text>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge :color="$source->is_active ? 'green' : 'zinc'" size="sm">
                            {{ $source->is_active ? __('Active') : __('Inactive') }}
                        </flux:badge>
                    </flux:table.cell>
                    @if ($showUnassignedColumn)
                        <flux:table.cell>
                            @if ($this->rolesEligibleForUnassigned->isNotEmpty())
                                @include('livewire.tenant.lead-sources.partials.unassigned-role-chips', ['source' => $source])
                            @else
                                <flux:text class="text-xs" variant="subtle">{{ __('No eligible roles') }}</flux:text>
                            @endif
                        </flux:table.cell>
                    @endif
                </flux:table.row>
            @empty
                <flux:table.row wire:loading.class="!hidden" wire:target="{{ $listLoadingTargets }}" @class(['!hidden' => $showSkeletonByDefault])>
                    <flux:table.cell colspan="{{ $columnCount }}">{{ __('No lead sources configured yet.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>

        {{-- Skeleton: a second <tbody> — see leads.blade.php's identical
            pattern for why this can't be a bare wire:loading directive
            (Livewire's non-class display toggling forces
            `display: inline-block` on a <tbody>, breaking its row-group
            role). Row count is arbitrary (3) — this list is never
            paginated, so there's no real per-page count to match. --}}
            <flux:table.rows @class(['!hidden' => ! $showSkeletonByDefault]) wire:loading.remove.class="!hidden" wire:target="{{ $listLoadingTargets }}">
                @for ($i = 0; $i < 3; $i++)
                    <flux:table.row wire:key="lead-source-skeleton-{{ $i }}">
                        <flux:table.cell><div class="h-3 w-24 skeleton-shimmer rounded"></div></flux:table.cell>
                        <flux:table.cell><div class="h-5 w-20 skeleton-shimmer rounded-full"></div></flux:table.cell>
                        <flux:table.cell><div class="h-3 w-32 skeleton-shimmer rounded"></div></flux:table.cell>
                        <flux:table.cell><div class="h-5 w-16 skeleton-shimmer rounded-full"></div></flux:table.cell>
                        @if ($showUnassignedColumn)
                            <flux:table.cell><div class="h-5 w-24 skeleton-shimmer rounded-full"></div></flux:table.cell>
                        @endif
                    </flux:table.row>
                @endfor
            </flux:table.rows>
        </flux:table>
    </div>
</div>
