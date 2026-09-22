@php
    // canEdit defaults false so the Kanban flyout's existing call site
    // (which never passes it) never renders the Edit/Cancel link — its
    // form is simply always shown whenever canUpdate is true, with no
    // read-only state of its own to toggle back to.
    $canEdit = $canEdit ?? false;
@endphp

<flux:card class="flex flex-col gap-4">
    <div class="flex items-center justify-between gap-2 border-b border-zinc-100 pb-3 dark:border-zinc-800">
        <div class="flex items-center gap-2">
            <div class="flex size-7 items-center justify-center rounded-lg bg-indigo-50 text-indigo-600 dark:bg-indigo-950/60 dark:text-indigo-400">
                <flux:icon.building-office variant="micro" class="size-4" />
            </div>
            <flux:heading size="lg" class="font-semibold">{{ __('Company Information') }}</flux:heading>
        </div>
        @if ($canEdit && ! $editable)
            <flux:button size="xs" variant="subtle" icon="pencil-square" wire:click="startEditingCompany" class="cursor-pointer font-semibold">
                {{ __('Edit') }}
            </flux:button>
        @endif
    </div>

    @if ($editable)
        <form wire:submit="updateCompany" class="flex flex-col gap-3">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <flux:input wire:model="leadCompany" :label="__('Company / Organization')" />
                <flux:input wire:model="leadWebsite" type="url" :label="__('Website URL')" />
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <flux:input wire:model="leadIndustry" :label="__('Industry Sector')" />
                <flux:input wire:model="leadLocation" :label="__('Location / City')" />
            </div>
            <div class="flex gap-2 pt-1">
                <flux:button type="submit" variant="primary" size="sm">{{ __('Save') }}</flux:button>
                @if ($canEdit)
                    <flux:button type="button" size="sm" wire:click="cancelEditingCompany">{{ __('Cancel') }}</flux:button>
                @endif
            </div>
        </form>
    @else
        @php $hasCompanyDetails = collect([$lead->company, $lead->website, $lead->industry, $lead->location])->filter()->isNotEmpty(); @endphp
        @if ($hasCompanyDetails)
            <div class="flex flex-col gap-3.5">
                {{-- Company Name Hero Box --}}
                @if ($lead->company)
                    <div class="flex items-center gap-3 rounded-xl border border-zinc-200/80 bg-zinc-50/60 p-3.5 shadow-2xs dark:border-zinc-800 dark:bg-zinc-900/60">
                        <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-indigo-50 text-indigo-600 dark:bg-indigo-950/60 dark:text-indigo-400">
                            <flux:icon.building-office class="size-5" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Company / Organization') }}</span>
                            <p class="text-sm font-semibold text-zinc-900 dark:text-white break-words">{{ $lead->company }}</p>
                        </div>
                    </div>
                @endif

                {{-- Company Attribute Badges & Cards --}}
                <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
                    @if ($lead->industry)
                        <div class="flex items-center gap-2.5 rounded-xl border border-white/70 bg-white/40 bg-[image:linear-gradient(165deg,rgb(255_255_255_/_0.22),rgb(255_248_224_/_0.12))] backdrop-blur-[20px] backdrop-saturate-[260%] shadow-[inset_0_1px_1px_rgb(255_255_255_/_0.75),inset_0_-1px_1px_rgb(120_90_40_/_0.05)] p-3 dark:border-zinc-800 dark:bg-zinc-900 dark:bg-[image:none] dark:backdrop-blur-none dark:backdrop-saturate-100 dark:shadow-none">
                            <div class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-purple-50 text-purple-600 dark:bg-purple-950/60 dark:text-purple-400">
                                <flux:icon.briefcase variant="micro" class="size-4" />
                            </div>
                            <div class="min-w-0">
                                <span class="block text-[11px] font-medium text-zinc-500 dark:text-zinc-400">{{ __('Industry') }}</span>
                                <span class="text-xs sm:text-sm font-semibold text-zinc-900 dark:text-white break-words">{{ $lead->industry }}</span>
                            </div>
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

                @if ($lead->website)
                    <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-white/70 bg-white/40 bg-[image:linear-gradient(165deg,rgb(255_255_255_/_0.22),rgb(255_248_224_/_0.12))] backdrop-blur-[20px] backdrop-saturate-[260%] shadow-[inset_0_1px_1px_rgb(255_255_255_/_0.75),inset_0_-1px_1px_rgb(120_90_40_/_0.05)] p-3 dark:border-zinc-800 dark:bg-zinc-900 dark:bg-[image:none] dark:backdrop-blur-none dark:backdrop-saturate-100 dark:shadow-none">
                        <div class="flex items-center gap-2.5 min-w-0">
                            <div class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-950/60 dark:text-blue-400">
                                <flux:icon.globe-alt variant="micro" class="size-4" />
                            </div>
                            <div class="min-w-0">
                                <span class="block text-[11px] font-medium text-zinc-500 dark:text-zinc-400">{{ __('Website URL') }}</span>
                                <span class="text-xs sm:text-sm font-semibold text-zinc-900 dark:text-white break-all">{{ $lead->website }}</span>
                            </div>
                        </div>
                        <flux:button type="button" size="xs" variant="subtle" icon="arrow-top-right-on-square" href="{{ $lead->website }}" target="_blank" rel="noopener noreferrer" class="cursor-pointer ms-auto">
                            {{ __('Visit Site') }}
                        </flux:button>
                    </div>
                @endif
            </div>
        @else
            <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 italic">{{ __('No company details recorded yet.') }}</p>
        @endif
    @endif
</flux:card>

