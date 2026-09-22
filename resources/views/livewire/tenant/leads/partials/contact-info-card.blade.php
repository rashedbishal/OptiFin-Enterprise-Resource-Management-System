@php
    $hasContactDetails = collect([$lead->phone, $lead->email, $lead->website, $lead->location])->filter()->isNotEmpty();
    $whatsappDigits = $lead->phone ? preg_replace('/\D/', '', $lead->phone) : null;
    // tel: is lenient but still shouldn't carry spaces/parens/dashes — keeps
    // digits and a leading + (country code) only.
    $telNumber = $lead->phone ? preg_replace('/[^\d+]/', '', $lead->phone) : null;

    // See lead-hero.blade.php's identical pair for why this is purely a
    // visual reveal-together toggle, not a real query defer: $lead's
    // fields are already loaded, nothing here is actually being fetched
    // async. Only ever used inside show.blade.php's Overview tab (never
    // the panel/flyout), so this re-shimmers on every tab switch too, not
    // just the very first page load — see Show::$tabContentReady's
    // docblock.
    $listLoadingTargets = 'switchTab,markTabContentReady';
    $showSkeletonByDefault = ! $tabContentReady;
@endphp

<flux:card class="flex flex-col gap-4">
    <div class="flex items-center gap-2 border-b border-zinc-100 pb-3 dark:border-zinc-800">
        <div class="flex size-7 items-center justify-center rounded-lg bg-accent/10 text-accent">
            <flux:icon.user variant="micro" class="size-4" />
        </div>
        <flux:heading size="lg" class="font-semibold">{{ __('Contact Information') }}</flux:heading>
    </div>

    {{-- Real content: hidden by default before initialLoadComplete, toggled
    by wire:loading otherwise — never a bare wire:loading/.remove, see
    leads.blade.php's long comment for why. --}}
    <div wire:loading.class="!hidden" wire:target="{{ $listLoadingTargets }}" class="{{ $showSkeletonByDefault ? '!hidden' : '' }} contents">
    @if ($hasContactDetails)
        <div class="flex flex-col gap-2.5">
            @if ($lead->phone)
                <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-white/70 bg-white/40 bg-[image:linear-gradient(165deg,rgb(255_255_255_/_0.22),rgb(255_248_224_/_0.12))] backdrop-blur-[20px] backdrop-saturate-[260%] shadow-[inset_0_1px_1px_rgb(255_255_255_/_0.75),inset_0_-1px_1px_rgb(120_90_40_/_0.05)] p-3 dark:border-zinc-800 dark:bg-zinc-900 dark:bg-[image:none] dark:backdrop-blur-none dark:backdrop-saturate-100 dark:shadow-none">
                    <div class="flex items-center gap-2.5 min-w-0">
                        <div class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
                            <flux:icon.phone variant="micro" class="size-4" />
                        </div>
                        <div class="min-w-0">
                            <span class="block text-[11px] font-medium text-zinc-500 dark:text-zinc-400">{{ __('Phone / WhatsApp') }}</span>
                            <span class="text-xs sm:text-sm font-semibold text-zinc-900 dark:text-white">{{ $lead->phone }}</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-1.5 ms-auto">
                        @if ($telNumber)
                            <flux:button type="button" size="xs" variant="subtle" icon="phone" href="tel:{{ $telNumber }}" class="cursor-pointer">
                                {{ __('Call') }}
                            </flux:button>
                        @endif
                        @if ($whatsappDigits)
                            <flux:button type="button" size="xs" variant="subtle" icon="chat-bubble-left-right" href="https://wa.me/{{ $whatsappDigits }}" target="_blank" class="cursor-pointer">
                                {{ __('WhatsApp') }}
                            </flux:button>
                        @endif
                    </div>
                </div>
            @endif

            @if ($lead->email)
                <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-white/70 bg-white/40 bg-[image:linear-gradient(165deg,rgb(255_255_255_/_0.22),rgb(255_248_224_/_0.12))] backdrop-blur-[20px] backdrop-saturate-[260%] shadow-[inset_0_1px_1px_rgb(255_255_255_/_0.75),inset_0_-1px_1px_rgb(120_90_40_/_0.05)] p-3 dark:border-zinc-800 dark:bg-zinc-900 dark:bg-[image:none] dark:backdrop-blur-none dark:backdrop-saturate-100 dark:shadow-none">
                    <div class="flex items-center gap-2.5 min-w-0">
                        <div class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-950/60 dark:text-blue-400">
                            <flux:icon.envelope variant="micro" class="size-4" />
                        </div>
                        <div class="min-w-0">
                            <span class="block text-[11px] font-medium text-zinc-500 dark:text-zinc-400">{{ __('Email Address') }}</span>
                            <span class="text-xs sm:text-sm font-semibold text-zinc-900 dark:text-white break-all">{{ $lead->email }}</span>
                        </div>
                    </div>
                    <flux:button type="button" size="xs" variant="subtle" icon="envelope" href="mailto:{{ $lead->email }}" class="cursor-pointer ms-auto">
                        {{ __('Email') }}
                    </flux:button>
                </div>
            @endif

            @if ($lead->website)
                <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-white/70 bg-white/40 bg-[image:linear-gradient(165deg,rgb(255_255_255_/_0.22),rgb(255_248_224_/_0.12))] backdrop-blur-[20px] backdrop-saturate-[260%] shadow-[inset_0_1px_1px_rgb(255_255_255_/_0.75),inset_0_-1px_1px_rgb(120_90_40_/_0.05)] p-3 dark:border-zinc-800 dark:bg-zinc-900 dark:bg-[image:none] dark:backdrop-blur-none dark:backdrop-saturate-100 dark:shadow-none">
                    <div class="flex items-center gap-2.5 min-w-0">
                        <div class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-purple-50 text-purple-600 dark:bg-purple-950/60 dark:text-purple-400">
                            <flux:icon.globe-alt variant="micro" class="size-4" />
                        </div>
                        <div class="min-w-0">
                            <span class="block text-[11px] font-medium text-zinc-500 dark:text-zinc-400">{{ __('Website') }}</span>
                            <span class="text-xs sm:text-sm font-semibold text-zinc-900 dark:text-white break-all">{{ $lead->website }}</span>
                        </div>
                    </div>
                    <flux:button type="button" size="xs" variant="subtle" icon="arrow-top-right-on-square" href="{{ $lead->website }}" target="_blank" rel="noopener noreferrer" class="cursor-pointer ms-auto">
                        {{ __('Visit Site') }}
                    </flux:button>
                </div>
            @endif

            @if ($lead->location)
                <div class="flex items-center gap-2.5 rounded-xl border border-white/70 bg-white/40 bg-[image:linear-gradient(165deg,rgb(255_255_255_/_0.22),rgb(255_248_224_/_0.12))] backdrop-blur-[20px] backdrop-saturate-[260%] shadow-[inset_0_1px_1px_rgb(255_255_255_/_0.75),inset_0_-1px_1px_rgb(120_90_40_/_0.05)] p-3 dark:border-zinc-800 dark:bg-zinc-900 dark:bg-[image:none] dark:backdrop-blur-none dark:backdrop-saturate-100 dark:shadow-none">
                    <div class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-amber-50 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400">
                        <flux:icon.map-pin variant="micro" class="size-4" />
                    </div>
                    <div class="min-w-0">
                        <span class="block text-[11px] font-medium text-zinc-500 dark:text-zinc-400">{{ __('Location') }}</span>
                        <span class="text-xs sm:text-sm font-semibold text-zinc-900 dark:text-white break-words">{{ $lead->location }}</span>
                    </div>
                </div>
            @endif
        </div>
    @else
        <span class="text-xs sm:text-sm font-medium text-zinc-600 dark:text-zinc-400 italic">{{ __('No contact details yet.') }}</span>
    @endif
    </div>

    {{-- Skeleton: hidden by default, revealed only while
    initialLoadComplete's request is in flight — see leads.blade.php for
    why this has to be class-based, never a bare wire:loading. --}}
    <div class="{{ $showSkeletonByDefault ? '' : '!hidden' }} flex flex-col gap-3" wire:loading.remove.class="!hidden" wire:target="{{ $listLoadingTargets }}">
        @for ($i = 0; $i < 3; $i++)
            <div wire:key="contact-info-skeleton-{{ $i }}" class="flex items-center gap-2">
                <div class="size-4 shrink-0 skeleton-shimmer rounded"></div>
                <div class="h-3 w-24 skeleton-shimmer rounded"></div>
                <div class="h-3 w-32 skeleton-shimmer rounded"></div>
            </div>
        @endfor
    </div>
</flux:card>

