@props(['userId'])

@php
    // Everything the softphone's JavaScript needs and nothing secret: the user's
    // own id (for their private Echo channel), the call URLs (only the call id is
    // ever substituted) and the few strings it shows. No token, no phone number id.
    $softphoneConfig = [
        'userId' => (string) $userId,
        'urls' => [
            'active' => route('tenant-lead-calls.active'),
            'claim' => route('tenant-lead-calls.claim', '__CALL__'),
            'answer' => route('tenant-lead-calls.answer', '__CALL__'),
            'accept' => route('tenant-lead-calls.accept', '__CALL__'),
            'reject' => route('tenant-lead-calls.reject', '__CALL__'),
        ],
        'texts' => [
            'micNeeded' => __('Microphone access is needed to answer calls.'),
            'unsupported' => __('This browser cannot take calls here. Use a current browser over HTTPS.'),
            'ended' => __('The call ended.'),
            'handledElsewhere' => __('This call is being handled elsewhere.'),
            'declined' => __('Call declined.'),
            'declinedUnconfirmed' => __('Declined here — WhatsApp may take a moment to stop ringing.'),
            'offline' => __('You are offline. Calls will reappear when you reconnect.'),
            'genericError' => __('Something went wrong with the call.'),
        ],
    ];
@endphp

{{-- Global softphone shell — mounted once in the tenant layout inside @persist, so
a live call survives wire:navigate. Desktop: a floating panel bottom-right. Mobile:
a full-width bottom sheet. z-40 sits above the top bar (z-[15]) and the mobile
sidebar drawer (z-20); dialogs and toasts render in the browser's top layer above
it. The container ignores pointer events so it never blocks the page around the
cards. --}}
<div
    x-data="softphone(@js($softphoneConfig))"
    x-cloak
    x-show="view.cards.length > 0 || view.notices.length > 0 || view.offline"
    class="pointer-events-none fixed inset-x-0 bottom-0 z-40 flex flex-col gap-2 p-2 sm:inset-x-auto sm:bottom-4 sm:right-4 sm:w-[24rem] sm:p-0"
    aria-live="polite"
    data-test="softphone"
>
    <audio x-ref="remoteAudio" playsinline class="hidden"></audio>

    <div x-show="view.offline" class="pointer-events-auto rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200" x-text="view.offlineText"></div>

    <template x-for="notice in view.notices" :key="notice.id">
        <div
            class="pointer-events-auto rounded-lg border px-3 py-2 text-sm shadow-lg"
            :class="notice.kind === 'error'
                ? 'border-red-300 bg-red-50 text-red-900 dark:border-red-500/40 dark:bg-red-500/10 dark:text-red-200'
                : 'border-zinc-200 bg-white text-zinc-800 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100'"
            x-text="notice.text"
        ></div>
    </template>

    <template x-for="card in view.cards" :key="card.id">
        <div
            class="pointer-events-auto rounded-xl border border-zinc-200 bg-white p-4 shadow-xl dark:border-zinc-700 dark:bg-zinc-800"
            :data-call-id="card.id"
            :data-mode="card.mode"
        >
            <div class="flex items-start gap-3">
                <div
                    class="flex size-10 shrink-0 items-center justify-center rounded-full"
                    :class="card.mode === 'connected' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300' : 'bg-sky-100 text-sky-700 dark:bg-sky-500/20 dark:text-sky-300'"
                >
                    <flux:icon.phone class="size-5" />
                </div>

                <div class="min-w-0 flex-1">
                    <div class="truncate font-semibold text-zinc-900 dark:text-white" x-text="card.lead.name || card.lead.phone || @js(__('Unknown caller'))"></div>
                    <div class="truncate text-sm text-zinc-500 dark:text-zinc-400" x-show="card.lead.name && card.lead.phone" x-text="card.lead.phone"></div>
                    <div class="text-xs text-zinc-500 dark:text-zinc-400" x-show="card.mode === 'connected'" x-text="format(card.elapsedSeconds)"></div>
                </div>

                <button
                    type="button"
                    class="cursor-pointer rounded p-1 text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200"
                    x-on:click="toggleMinimized(card.id)"
                    :aria-expanded="!minimized[card.id]"
                    aria-label="{{ __('Minimize') }}"
                >
                    <flux:icon.chevron-down class="size-4 transition-transform" x-bind:class="minimized[card.id] ? 'rotate-180' : ''" />
                </button>
            </div>

            <div class="mt-3 flex flex-col gap-3" x-show="!minimized[card.id]">
                {{-- Ringing --}}
                <template x-if="card.mode === 'ringing'">
                    <div class="flex flex-col gap-3">
                        <div class="flex items-center justify-between text-sm">
                            <span class="font-medium text-sky-700 dark:text-sky-300">{{ __('Incoming WhatsApp call') }}</span>
                            <span class="tabular-nums text-zinc-500 dark:text-zinc-400" x-show="card.remainingSeconds !== null" x-text="card.remainingSeconds + 's'"></span>
                        </div>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400" x-show="view.mediaBusy && !card.canAnswer">{{ __('Finish your current call before answering another.') }}</p>
                        <div class="flex flex-wrap gap-2">
                            <flux:button size="sm" variant="primary" icon="phone" x-on:click="answer(card.id)" x-bind:disabled="!card.canAnswer">{{ __('Answer') }}</flux:button>
                            <flux:button size="sm" variant="danger" x-on:click="decline(card.id)" x-bind:disabled="!card.canDecline">{{ __('Decline') }}</flux:button>
                            <flux:button size="sm" variant="ghost" x-on:click="dismiss(card.id)">{{ __('Dismiss') }}</flux:button>
                        </div>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Decline ends the call for everyone it is ringing. Dismiss only hides it here.') }}</p>
                    </div>
                </template>

                {{-- Connecting --}}
                <template x-if="card.mode === 'connecting'">
                    <div class="flex flex-col gap-3">
                        <span class="text-sm text-zinc-600 dark:text-zinc-300">{{ __('Connecting…') }}</span>
                        <div class="flex gap-2">
                            <flux:button size="sm" variant="danger" x-on:click="decline(card.id)" x-bind:disabled="!card.canDecline">{{ __('Decline') }}</flux:button>
                        </div>
                    </div>
                </template>

                {{-- Failed --}}
                <template x-if="card.mode === 'failed'">
                    <div class="flex flex-col gap-3">
                        <span class="text-sm text-red-700 dark:text-red-300" x-text="card.error"></span>
                        <div class="flex gap-2">
                            <flux:button size="sm" variant="primary" x-show="card.retryable" x-on:click="retry()">{{ __('Retry') }}</flux:button>
                            <flux:button size="sm" variant="danger" x-on:click="decline(card.id)" x-bind:disabled="!card.canDecline">{{ __('Decline') }}</flux:button>
                        </div>
                    </div>
                </template>

                {{-- Connected --}}
                <template x-if="card.mode === 'connected'">
                    <div class="flex flex-col gap-3">
                        <span class="text-sm text-emerald-700 dark:text-emerald-300" x-show="!card.reconnecting">{{ __('Connected') }}</span>
                        <span class="text-sm text-amber-700 dark:text-amber-300" x-show="card.reconnecting">{{ __('Reconnecting audio…') }}</span>
                        <div class="flex flex-wrap gap-2">
                            <flux:button size="sm" x-on:click="toggleMute()"><span x-text="card.muted ? @js(__('Unmute')) : @js(__('Mute'))"></span></flux:button>
                            <flux:button size="sm" variant="primary" x-show="card.audioBlocked" x-on:click="enableAudio()">{{ __('Enable audio') }}</flux:button>
                        </div>
                    </div>
                </template>

                {{-- Active in another tab --}}
                <template x-if="card.mode === 'other-tab'">
                    <span class="text-sm text-zinc-600 dark:text-zinc-300">{{ __('This call is active in another tab.') }}</span>
                </template>

                {{-- Read-only after a reload: the audio connection cannot be restored --}}
                <template x-if="card.mode === 'lost'">
                    <div class="flex flex-col gap-3">
                        <span class="text-sm text-zinc-600 dark:text-zinc-300">{{ __('This call is in progress, but its audio was lost when the page reloaded and cannot be resumed here.') }}</span>
                        <div class="flex gap-2" x-show="card.canDecline">
                            <flux:button size="sm" variant="danger" x-on:click="decline(card.id)">{{ __('Decline') }}</flux:button>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </template>
</div>
