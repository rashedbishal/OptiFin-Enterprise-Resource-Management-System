@php
    // Defaults preserve the Kanban flyout's existing unconditional-form
    // behavior (show-panel.blade.php includes this with no props at all):
    // editable stays true and no Edit/Cancel link ever renders there.
    $editable = $editable ?? true;
    $canEdit = $canEdit ?? false;

    $whatsappDigits = $lead->phone ? preg_replace('/\D/', '', $lead->phone) : null;
    $telNumber = $lead->phone ? preg_replace('/[^\d+]/', '', $lead->phone) : null;
@endphp

<flux:card class="flex flex-col gap-4">
    <div class="flex items-center justify-between gap-2 border-b border-zinc-100 pb-3 dark:border-zinc-800">
        <div class="flex items-center gap-2">
            <div class="flex size-7 items-center justify-center rounded-lg bg-accent/10 text-accent">
                <flux:icon.user variant="micro" class="size-4" />
            </div>
            <flux:heading size="lg" class="font-semibold">{{ __('Contact Information') }}</flux:heading>
        </div>
        @if ($canEdit && ! $editable)
            <flux:button size="xs" variant="subtle" icon="pencil-square" wire:click="startEditingContact" class="cursor-pointer font-semibold">
                {{ __('Edit') }}
            </flux:button>
        @endif
    </div>

    @if ($editable)
        <form wire:submit="updateContact" class="flex flex-col gap-3">
            <flux:input wire:model="leadName" :label="__('Contact Person')" required />

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <flux:input wire:model="leadPhone" type="tel" :label="__('Phone / WhatsApp')" />
                <flux:input wire:model="leadEmail" type="email" :label="__('Email Address')" />
            </div>
            <div class="flex gap-2 pt-1">
                <flux:button type="submit" variant="primary" size="sm">{{ __('Save') }}</flux:button>
                @if ($canEdit)
                    <flux:button type="button" size="sm" wire:click="cancelEditingContact">{{ __('Cancel') }}</flux:button>
                @endif
            </div>
        </form>
    @else
        <div class="flex flex-col gap-3.5">
            {{-- Contact Person Box --}}
            <div class="flex items-center gap-3 rounded-xl border border-zinc-200/80 bg-zinc-50/60 p-3.5 shadow-2xs dark:border-zinc-800 dark:bg-zinc-900/60">
                <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-accent/10 font-bold text-accent dark:bg-accent/20">
                    {{ strtoupper(substr($lead->name, 0, 1)) }}
                </div>
                <div class="min-w-0 flex-1">
                    <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Contact Person') }}</span>
                    <p class="text-sm font-semibold text-zinc-900 dark:text-white break-words">{{ $lead->name }}</p>
                </div>
            </div>

            {{-- Details Grid --}}
            <div class="grid grid-cols-1 gap-2.5">
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

                @if (! $lead->phone && ! $lead->email)
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 italic">{{ __('No additional contact details recorded.') }}</p>
                @endif
            </div>
        </div>
    @endif
</flux:card>

