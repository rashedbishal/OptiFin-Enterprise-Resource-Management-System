@push('breadcrumbs')
    <x-breadcrumbs :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard')],
        ['label' => __('Lead Management')],
    ]" />
@endpush

<div class="flex flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8 overflow-y-auto min-h-0">
    {{-- Module hero: tinted gradient panel (warm sand in light mode echoing
    the sidebar's #E7E1D2, navy-steel in dark mode matching the zinc-900
    canvas) with a soft glow. Purely decorative — aria-hidden + pointer-
    events-none so it never intercepts clicks or screen readers. --}}
    <div class="relative shrink-0 overflow-hidden rounded-2xl border border-zinc-200/70 bg-gradient-to-br from-[#F7F2E4] via-[#FCFBF5] to-white shadow-sm dark:border-zinc-700/70 dark:from-[#16283e] dark:via-[#111f30] dark:to-[#0e1826]">
        <div aria-hidden="true" class="pointer-events-none absolute -top-24 -right-12 size-72 rounded-full bg-[#d8c9a3]/40 blur-3xl dark:bg-sky-500/10"></div>
        <div aria-hidden="true" class="pointer-events-none absolute -bottom-28 -left-16 size-72 rounded-full bg-white/70 blur-3xl dark:bg-transparent"></div>

        <div class="relative flex flex-col gap-5 p-5 sm:p-6 lg:p-8 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-4 min-w-0">
                <div class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-zinc-900 text-white shadow-md dark:bg-white dark:text-zinc-900">
                    <flux:icon.chat-bubble-left-right class="size-6" />
                </div>
                <div class="flex flex-col gap-1 min-w-0">
                    <flux:heading size="xl">{{ __('Lead Management') }}</flux:heading>
                    <flux:text variant="subtle">{{ __('Capture, organize, and convert your leads — every tool of the module lives one click away.') }}</flux:text>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2 w-full sm:w-auto shrink-0">
                @if ($this->canCreateLead)
                    <flux:button icon="arrow-up-tray" size="sm" :href="route('tenant-lead-imports.index')" wire:navigate class="w-full sm:w-auto">
                        {{ __('Import Leads') }}
                    </flux:button>
                @endif
                @if ($this->isClientAdmin)
                    <flux:button icon="chart-bar" size="sm" :href="route('tenant-leads.reports')" wire:navigate class="w-full sm:w-auto">
                        {{ __('Reports & Analytics') }}
                    </flux:button>
                @endif
            </div>
        </div>
    </div>

    {{-- Read-only module stats, mirroring the main Dashboard's stat-card
    pattern. Counts are tenant-wide and safe for every module viewer. --}}
    <div class="grid auto-rows-min gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5">
        <flux:card>
            <div class="flex items-center justify-between gap-3">
                <div class="flex flex-col gap-1">
                    <flux:text>{{ __('Total Leads') }}</flux:text>
                    <flux:heading size="xl">{{ $this->totalLeads }}</flux:heading>
                </div>
                <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600 ring-1 ring-blue-200/60 dark:bg-blue-500/10 dark:text-blue-400 dark:ring-blue-500/20">
                    <flux:icon.users class="size-5" />
                </div>
            </div>
        </flux:card>
        <flux:card>
            <div class="flex items-center justify-between gap-3">
                <div class="flex flex-col gap-1">
                    <flux:text>{{ __('Lead Sources') }}</flux:text>
                    <flux:heading size="xl">{{ $this->totalSources }}</flux:heading>
                </div>
                <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 ring-1 ring-emerald-200/60 dark:bg-emerald-500/10 dark:text-emerald-400 dark:ring-emerald-500/20">
                    <flux:icon.signal class="size-5" />
                </div>
            </div>
        </flux:card>
        <flux:card>
            <div class="flex items-center justify-between gap-3">
                <div class="flex flex-col gap-1">
                    <flux:text>{{ __('Scoring Rules') }}</flux:text>
                    <flux:heading size="xl">{{ $this->totalScoringRules }}</flux:heading>
                </div>
                <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-rose-50 text-rose-600 ring-1 ring-rose-200/60 dark:bg-rose-500/10 dark:text-rose-400 dark:ring-rose-500/20">
                    <flux:icon.adjustments-horizontal class="size-5" />
                </div>
            </div>
        </flux:card>
        <flux:card>
            <div class="flex items-center justify-between gap-3">
                <div class="flex flex-col gap-1">
                    <flux:text>{{ __('Custom Fields') }}</flux:text>
                    <flux:heading size="xl">{{ $this->totalCustomFields }}</flux:heading>
                </div>
                <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-violet-50 text-violet-600 ring-1 ring-violet-200/60 dark:bg-violet-500/10 dark:text-violet-400 dark:ring-violet-500/20">
                    <flux:icon.squares-plus class="size-5" />
                </div>
            </div>
        </flux:card>
        <flux:card>
            <div class="flex items-center justify-between gap-3">
                <div class="flex flex-col gap-1">
                    <flux:text>{{ __('Website Forms') }}</flux:text>
                    <flux:heading size="xl">{{ $this->totalWebsiteForms }}</flux:heading>
                </div>
                <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-sky-50 text-sky-600 ring-1 ring-sky-200/60 dark:bg-sky-500/10 dark:text-sky-400 dark:ring-sky-500/20">
                    <flux:icon.document-text class="size-5" />
                </div>
            </div>
        </flux:card>
    </div>

    <div class="flex flex-col gap-3">
        <flux:heading size="lg">{{ __('Module Pages') }}</flux:heading>

        <div class="grid auto-rows-min gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {{-- One navigation tile per nested page of the module. Each tile
            is a real <a wire:navigate> — the whole card is the click target.
            "$gate" is the authorization bar of the page the tile opens (each
            nested page enforces the same gate server-side on arrival); a
            user who can't enter still sees the tile with a lock badge, so
            the module's shape stays legible instead of silently missing
            cards. Tints are per-page accents; everything else is shared. --}}
            @php
                // label, route name, icon, tint, description, entry count [computed, label], gate
                $tiles = [
                    [
                        'label' => __('Lead Details'),
                        'route' => 'tenant-leads.index',
                        'icon' => 'users',
                        'tint' => 'blue',
                        'description' => __('Browse, filter, and manage every lead in your pipeline.'),
                        'count' => [$this->totalLeads, __(':count leads', ['count' => $this->totalLeads])],
                        'gate' => null,
                    ],
                    [
                        'label' => __('Lead Sources'),
                        'route' => 'tenant-lead-sources.index',
                        'icon' => 'signal',
                        'tint' => 'emerald',
                        'description' => __('The channels configured by your account manager that capture leads.'),
                        'count' => [$this->totalSources, __(':count configured', ['count' => $this->totalSources])],
                        'gate' => null,
                    ],
                    [
                        'label' => __('Scoring Rules'),
                        'route' => 'tenant-leads.scoring-rules',
                        'icon' => 'adjustments-horizontal',
                        'tint' => 'rose',
                        'description' => __('Configure rule-based scoring to rank and prioritize incoming leads.'),
                        'count' => [$this->totalScoringRules, __(':count rules', ['count' => $this->totalScoringRules])],
                        'gate' => $this->canManageCatalogs,
                    ],
                    [
                        'label' => __('Custom Fields'),
                        'route' => 'tenant-leads.custom-fields',
                        'icon' => 'squares-plus',
                        'tint' => 'violet',
                        'description' => __('Extra fields your team can fill in on any lead, beyond the built-in ones.'),
                        'count' => [$this->totalCustomFields, __(':count fields', ['count' => $this->totalCustomFields])],
                        'gate' => $this->canManageCatalogs,
                    ],
                    [
                        'label' => __('Source Costs'),
                        'route' => 'tenant-leads.source-costs',
                        'icon' => 'banknotes',
                        'tint' => 'amber',
                        'description' => __('Monthly marketing spend per source, feeding Cost per Lead on Reports.'),
                        'count' => [$this->totalSourceCostEntries, __(':count entries', ['count' => $this->totalSourceCostEntries])],
                        'gate' => $this->canManageCatalogs,
                    ],
                    [
                        'label' => __('Website Forms'),
                        'route' => 'tenant-leads.website-forms',
                        'icon' => 'document-text',
                        'tint' => 'sky',
                        'description' => __('Build your own HTML forms that POST submissions straight into your leads.'),
                        'count' => [$this->totalWebsiteForms, __(':count forms', ['count' => $this->totalWebsiteForms])],
                        'gate' => $this->canManageCatalogs,
                    ],
                ];
            @endphp

            @foreach ($tiles as $tile)
                {{-- Full class strings (never runtime-concatenated fragments):
                Tailwind's source scanner needs each utility to appear
                verbatim in this file to generate it. --}}
                @php
                    $tintClasses = [
                        'blue' => 'bg-blue-50 text-blue-600 ring-blue-200/60 dark:bg-blue-500/10 dark:text-blue-400 dark:ring-blue-500/20',
                        'emerald' => 'bg-emerald-50 text-emerald-600 ring-emerald-200/60 dark:bg-emerald-500/10 dark:text-emerald-400 dark:ring-emerald-500/20',
                        'rose' => 'bg-rose-50 text-rose-600 ring-rose-200/60 dark:bg-rose-500/10 dark:text-rose-400 dark:ring-rose-500/20',
                        'violet' => 'bg-violet-50 text-violet-600 ring-violet-200/60 dark:bg-violet-500/10 dark:text-violet-400 dark:ring-violet-500/20',
                        'amber' => 'bg-amber-50 text-amber-600 ring-amber-200/60 dark:bg-amber-500/10 dark:text-amber-400 dark:ring-amber-500/20',
                        'sky' => 'bg-sky-50 text-sky-600 ring-sky-200/60 dark:bg-sky-500/10 dark:text-sky-400 dark:ring-sky-500/20',
                    ][$tile['tint']];

                    $glowClasses = [
                        'blue' => 'via-blue-500/50',
                        'emerald' => 'via-emerald-500/50',
                        'rose' => 'via-rose-500/50',
                        'violet' => 'via-violet-500/50',
                        'amber' => 'via-amber-500/50',
                        'sky' => 'via-sky-500/50',
                    ][$tile['tint']];
                @endphp
                <a
                    href="{{ route($tile['route']) }}"
                    wire:navigate
                    class="group relative flex flex-col gap-4 overflow-hidden rounded-xl border border-zinc-200/80 bg-white p-5 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:border-zinc-300 hover:shadow-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-900 active:translate-y-0 dark:border-zinc-700/80 dark:bg-zinc-800 dark:hover:border-zinc-600"
                >
                    {{-- Hairline glow along the tile's top edge on hover. --}}
                    <div aria-hidden="true" class="pointer-events-none absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent {{ $glowClasses }} to-transparent opacity-0 transition-opacity duration-300 group-hover:opacity-100"></div>

                    <div class="flex items-start justify-between gap-3">
                        <div class="flex size-11 shrink-0 items-center justify-center rounded-lg ring-1 transition-transform duration-200 group-hover:scale-105 {{ $tintClasses }}">
                            <flux:icon :icon="$tile['icon']" class="size-5" />
                        </div>
                        <flux:icon.chevron-right class="mt-1 size-4 shrink-0 text-zinc-300 transition-all duration-200 group-hover:translate-x-0.5 group-hover:text-zinc-500 dark:text-zinc-600 dark:group-hover:text-zinc-300" />
                    </div>

                    <div class="flex flex-col gap-1">
                        <flux:heading size="sm">{{ $tile['label'] }}</flux:heading>
                        <flux:text size="sm" variant="subtle" class="line-clamp-2">{{ $tile['description'] }}</flux:text>
                    </div>

                    <div class="flex items-center gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-700/60">
                        @if ($tile['gate'] === null || $tile['gate'] === true)
                            <flux:badge size="sm" color="zinc">{{ $tile['count'][1] }}</flux:badge>
                        @else
                            <flux:badge size="sm" color="zinc" icon="lock-closed">{{ __('Client Admin') }}</flux:badge>
                        @endif
                    </div>
                </a>
            @endforeach
        </div>
    </div>
</div>
