@php
    $hasCompany = filled($lead->company);
    // A Facebook Lead Ads lead's "list" is its own source's spreadsheet
    // (tenant-leads.source), not the main Leads table it never actually
    // appears in as a row (see Index::filteredQuery() — FB Lead Ads leads
    // are excluded from that table and only show as one aggregate card
    // per source instead). Every other source type goes back to the main
    // table as before.
    $backToLeadsUrl = $lead->source->type === \App\Enums\LeadSourceType::FacebookLeadAds
        ? route('tenant-leads.source', $lead->source)
        : route('tenant-leads.index');

    // Unlike Activity's genuinely-deferred query, $lead itself is always
    // loaded synchronously in Show::mount() (route-model-bound before the
    // component even runs) — there's no query to gate here. This card's
    // real/skeleton toggle exists purely so the whole page's cards reveal
    // together on first paint rather than this one popping in ahead of the
    // (genuinely async) Activity feed. See Show::$initialLoadComplete.
    $listLoadingTargets = 'completeInitialLoad';
    $showSkeletonByDefault = ! $initialLoadComplete;
@endphp

<div class="rounded-xl border border-zinc-200 bg-gradient-to-br from-white to-zinc-50 p-3 sm:p-5 dark:border-zinc-700 dark:from-zinc-800 dark:to-zinc-900">
    {{-- Real content: hidden by default before initialLoadComplete, toggled
    by wire:loading otherwise — never a bare wire:loading/.remove, see
    leads.blade.php's long comment for why. --}}
    <div wire:loading.class="!hidden" wire:target="{{ $listLoadingTargets }}" class="{{ $showSkeletonByDefault ? '!hidden' : '' }} contents">
    <div class="flex flex-col gap-3 sm:gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div class="flex flex-col gap-1.5 sm:gap-2">
            <div class="flex flex-wrap items-center gap-1.5 sm:gap-2">
                <flux:badge size="sm" color="zinc" class="font-mono">{{ $lead->identifier }}</flux:badge>
                <flux:badge size="sm" :color="$lead->status->color()">{{ $lead->status->label() }}</flux:badge>
                <flux:badge size="sm" :color="$lead->priority->color()">{{ $lead->priority->label() }}</flux:badge>
            </div>

            <div class="flex items-center gap-2 sm:gap-3">
                @if ($lead->avatarUrl())
                    <img src="{{ $lead->avatarUrl() }}" alt="" class="size-9 shrink-0 rounded-full object-cover sm:size-11">
                @else
                    <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-zinc-100 sm:size-11 dark:bg-zinc-700">
                        <flux:icon :icon="$hasCompany ? 'building-office' : 'user'" class="size-4 text-zinc-500 sm:size-5 dark:text-zinc-300" />
                    </div>
                @endif
                <div>
                    {{-- A plain element rather than flux:heading size="xl" —
                    the size prop only takes a single fixed value, so it
                    can't shrink on mobile and grow back at sm+ the way this
                    needs to; text-lg sm:text-2xl replicates size="xl"'s own
                    look (text-2xl, font-medium, same text color) above sm,
                    while reading much more compact on a phone. --}}
                    <p class="text-lg font-medium text-zinc-800 sm:text-2xl dark:text-white">{{ $hasCompany ? $lead->company : $lead->name }}</p>
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

            @include('livewire.tenant.leads.partials.lead-tags-manager', ['lead' => $lead])
        </div>

        <div class="flex flex-col items-end gap-2">
            <div class="flex gap-1.5 sm:gap-2">
                @if ($canDelete && $lead->source->type->isIndividuallyDeletable())
                    <flux:button type="button" size="sm" variant="danger" icon="trash" wire:click="confirmDelete">
                        {{ __('Delete') }}
                    </flux:button>
                @endif

                <flux:button type="button" size="sm" :href="$backToLeadsUrl" wire:navigate>
                    {{ __('Back to Leads') }}
                </flux:button>
            </div>

            @if ($lead->deal_value !== null || $lead->win_probability !== null)
                <div class="flex gap-3">
                    @if ($lead->deal_value !== null)
                        <div class="min-w-28 rounded-lg border card-surface-nested px-4 py-2 text-center">
                            <flux:text size="xs" variant="subtle" class="uppercase tracking-wide">{{ __('Deal Value') }}</flux:text>
                            <flux:text class="block text-lg font-semibold text-emerald-600 dark:text-emerald-400">
                                {{ $lead->dealValueCurrency?->code }} {{ number_format((float) $lead->deal_value, 0) }}
                            </flux:text>
                        </div>
                    @endif
                    {{-- Was mislabeled "Lead Score" — win_probability is a
                    manually-entered deal field (see Show::updateDeal()),
                    unrelated to the computed Lead Scoring feature's score
                    (see score-breakdown.blade.php). Every other place this
                    column appears (Show::FIELD_LABELS, deal-form.blade.php)
                    already correctly called it "Win Probability" — this was
                    the one place still using the old name. Behavior/value
                    entirely unchanged, display label only. --}}
                    @if ($lead->win_probability !== null)
                        <div class="min-w-28 rounded-lg border card-surface-nested px-4 py-2 text-center">
                            <flux:text size="xs" variant="subtle" class="uppercase tracking-wide">{{ __('Win Probability') }}</flux:text>
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

    {{-- Skeleton: hidden by default, revealed only while
    initialLoadComplete's request is in flight — see leads.blade.php for
    why this has to be class-based, never a bare wire:loading. --}}
    <div class="{{ $showSkeletonByDefault ? '' : '!hidden' }} contents" wire:loading.remove.class="!hidden" wire:target="{{ $listLoadingTargets }}">
        <div class="flex flex-col gap-3 sm:gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="flex flex-col gap-1.5 sm:gap-2">
                <div class="flex flex-wrap items-center gap-1.5 sm:gap-2">
                    <div class="h-5 w-20 skeleton-shimmer rounded-full"></div>
                    <div class="h-5 w-16 skeleton-shimmer rounded-full"></div>
                    <div class="h-5 w-14 skeleton-shimmer rounded-full"></div>
                </div>
                <div class="flex items-center gap-2 sm:gap-3">
                    <div class="size-9 shrink-0 skeleton-shimmer rounded-full sm:size-11"></div>
                    <div class="flex flex-col gap-1.5">
                        <div class="h-5 w-40 skeleton-shimmer rounded sm:h-6 sm:w-56"></div>
                        <div class="h-3 w-32 skeleton-shimmer rounded"></div>
                    </div>
                </div>
            </div>
            <div class="flex flex-col items-end gap-2">
                <div class="h-8 w-32 skeleton-shimmer rounded"></div>
                <div class="flex gap-3">
                    <div class="h-14 w-28 skeleton-shimmer rounded-lg"></div>
                    <div class="h-14 w-28 skeleton-shimmer rounded-lg"></div>
                </div>
            </div>
        </div>
    </div>
</div>
