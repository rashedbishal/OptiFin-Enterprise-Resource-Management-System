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

<div class="rounded-2xl border border-zinc-200/80 bg-gradient-to-br from-white via-white to-zinc-50 p-3 shadow-sm sm:p-5 lg:p-6 dark:border-zinc-700/80 dark:from-zinc-800 dark:via-zinc-800 dark:to-zinc-900">
    {{-- Real content: hidden by default before initialLoadComplete, toggled
    by wire:loading otherwise — never a bare wire:loading/.remove, see
    leads.blade.php's long comment for why. --}}
    <div wire:loading.class="!hidden" wire:target="{{ $listLoadingTargets }}" class="{{ $showSkeletonByDefault ? '!hidden' : '' }} contents">
    <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between lg:gap-8">
        <div class="flex min-w-0 flex-1 flex-col gap-2 sm:gap-3">
            <div class="flex flex-wrap items-center gap-1.5 sm:gap-2">
                <flux:badge size="sm" color="zinc" class="font-mono">{{ $lead->identifier }}</flux:badge>
                <flux:badge size="sm" :color="$lead->status->color()">{{ $lead->status->label() }}</flux:badge>
                <flux:badge size="sm" :color="$lead->priority->color()">{{ $lead->priority->label() }}</flux:badge>
            </div>

            <div class="flex items-center gap-3 sm:gap-4">
                @if ($lead->avatarUrl())
                    <img src="{{ $lead->avatarUrl() }}" alt="" class="size-9 shrink-0 rounded-full object-cover sm:size-11">
                @else
                    <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-zinc-100 ring-1 ring-zinc-200 sm:size-12 dark:bg-zinc-700 dark:ring-zinc-600">
                        <flux:icon :icon="$hasCompany ? 'building-office' : 'user'" class="size-4 text-zinc-500 sm:size-5 dark:text-zinc-300" />
                    </div>
                @endif
                <div class="min-w-0 flex-1">
                    {{-- A plain element rather than flux:heading size="xl" —
                    the size prop only takes a single fixed value, so it
                    can't shrink on mobile and grow back at sm+ the way this
                    needs to; text-lg sm:text-2xl replicates size="xl"'s own
                    look (text-2xl, font-medium, same text color) above sm,
                    while reading much more compact on a phone. --}}
                    <p class="truncate text-xl font-semibold tracking-tight text-zinc-900 sm:text-2xl dark:text-white">{{ $hasCompany ? $lead->company : $lead->name }}</p>
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

        <div class="flex w-full flex-col gap-3 lg:w-auto lg:min-w-[24rem] lg:items-end">
            @if ($lead->deal_value !== null || $lead->win_probability !== null)
                <div class="grid w-full grid-cols-2 gap-2 sm:gap-3 lg:max-w-[24rem]">
                    @if ($lead->deal_value !== null)
                        <div class="min-w-0 overflow-hidden rounded-xl border border-emerald-200 bg-emerald-50/70 p-2.5 text-center dark:border-emerald-900/70 dark:bg-emerald-950/30 sm:p-3">
                            <div class="flex min-w-0 items-center justify-center gap-1.5 text-emerald-700 dark:text-emerald-300">
                                <span class="flex size-5 shrink-0 items-center justify-center rounded-md bg-emerald-200/80 dark:bg-emerald-900/70">
                                    <flux:icon.banknotes variant="micro" class="size-3" />
                                </span>
                                <flux:text size="xs" variant="subtle" class="min-w-0 truncate font-semibold text-emerald-800 dark:text-emerald-200">{{ __('Deal Value') }}</flux:text>
                            </div>
                            <flux:text class="mt-1.5 block truncate text-base font-bold text-emerald-700 dark:text-emerald-300 sm:text-lg">
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
                        <div class="min-w-0 overflow-hidden rounded-xl border border-indigo-200 bg-indigo-50/70 p-2.5 text-center dark:border-indigo-900/70 dark:bg-indigo-950/30 sm:p-3">
                            <div class="flex min-w-0 items-center justify-center gap-1.5 text-indigo-700 dark:text-indigo-300">
                                <span class="flex size-5 shrink-0 items-center justify-center rounded-md bg-indigo-200/80 dark:bg-indigo-900/70">
                                    <flux:icon.chart-pie variant="micro" class="size-3" />
                                </span>
                                <flux:text size="xs" variant="subtle" class="min-w-0 truncate font-semibold text-indigo-800 dark:text-indigo-200">{{ __('Win Probability') }}</flux:text>
                            </div>
                            <flux:text class="mt-1.5 block text-base font-bold text-indigo-700 dark:text-indigo-300 sm:text-lg">
                                {{ $lead->win_probability }}%
                            </flux:text>
                        </div>
                    @endif
                </div>
            @endif

            <div class="flex w-full flex-wrap gap-2 lg:w-auto lg:justify-end">
                {{-- Delete moved to the Status & Assignment card (see
                status-assignment-form.blade.php) — it's now only offered
                once the lead's status is Unqualified, which this hero
                banner has no natural place to gate on without duplicating
                that card's own condition. --}}
                <flux:button type="button" size="sm" icon="arrow-left" :href="$backToLeadsUrl" wire:navigate class="flex-1 sm:flex-none">
                    {{ __('Back to Leads') }}
                </flux:button>
            </div>
        </div>
    </div>
    </div>

    {{-- Skeleton: hidden by default, revealed only while
    initialLoadComplete's request is in flight — see leads.blade.php for
    why this has to be class-based, never a bare wire:loading. --}}
    <div class="{{ $showSkeletonByDefault ? '' : '!hidden' }} contents" wire:loading.remove.class="!hidden" wire:target="{{ $listLoadingTargets }}">
        <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between lg:gap-8">
            <div class="flex flex-col gap-2 sm:gap-3">
                <div class="flex flex-wrap items-center gap-1.5 sm:gap-2">
                    <div class="h-5 w-20 skeleton-shimmer rounded-full"></div>
                    <div class="h-5 w-16 skeleton-shimmer rounded-full"></div>
                    <div class="h-5 w-14 skeleton-shimmer rounded-full"></div>
                </div>
                <div class="flex items-center gap-2 sm:gap-3">
                    <div class="size-10 shrink-0 skeleton-shimmer rounded-xl sm:size-12"></div>
                    <div class="flex flex-col gap-1.5">
                        <div class="h-5 w-40 skeleton-shimmer rounded sm:h-6 sm:w-56"></div>
                        <div class="h-3 w-32 skeleton-shimmer rounded"></div>
                    </div>
                </div>
            </div>
            <div class="flex w-full flex-col gap-3 lg:w-auto lg:min-w-[24rem] lg:items-end">
                <div class="grid w-full grid-cols-2 gap-2 sm:gap-3 lg:max-w-[24rem]">
                    <div class="h-[4.75rem] skeleton-shimmer rounded-xl"></div>
                    <div class="h-[4.75rem] skeleton-shimmer rounded-xl"></div>
                </div>
                <div class="flex w-full gap-2 lg:w-auto">
                    <div class="h-8 flex-1 skeleton-shimmer rounded sm:w-24 sm:flex-none"></div>
                    <div class="h-8 flex-1 skeleton-shimmer rounded sm:w-32 sm:flex-none"></div>
                </div>
            </div>
        </div>
    </div>
</div>
