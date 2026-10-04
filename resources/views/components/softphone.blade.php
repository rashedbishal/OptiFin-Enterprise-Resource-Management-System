@props(['userId'])

@php
    // Everything the softphone's JavaScript needs and nothing secret: the user's
    // own id (for their private Echo channel), the call URLs (only the call id is
    // ever substituted) and the few strings it shows. No token, no phone number id.
    //
    // WhatsApp Call Backup / Overflow Receiver, Phase 2 — tenantId is the
    // caller's own tenant (never another user's, never client-suppliable:
    // Auth::user()->tenant_id, same trust boundary as $userId above), used
    // only to join this tenant's `tenant-softphone.{tenantId}` presence
    // channel (see resources/js/softphone/realtime.js). It carries no other
    // meaning and is never sent back to any endpoint.
    $softphoneConfig = [
        'userId' => (string) $userId,
        'tenantId' => (string) auth()->user()->tenant_id,
        // Null when the tenant has no custom ringtone uploaded — index.js
        // falls back to the synthesized tone in that case, unchanged.
        'ringtoneUrl' => auth()->user()->tenant?->whatsapp_call_ringtone_path ? route('tenant.whatsapp-call-ringtone') : null,
        // Web Push — only the PUBLIC VAPID key, safe to expose (that's what
        // "public" means here). Read straight from config rather than a
        // VITE_* build-time var, matching every other value in this array:
        // no rebuild needed if the key pair is ever rotated.
        'vapidPublicKey' => config('webpush.vapid.public_key'),
        'pushUrls' => [
            'subscribe' => route('tenant.push-subscriptions.store'),
            'unsubscribe' => route('tenant.push-subscriptions.destroy'),
        ],
        'pollMs' => (int) config('calling.softphone_poll_seconds', 5) * 1000,
        'heartbeatMs' => (int) config('calling.softphone_heartbeat_seconds', 15) * 1000,
        'staleMs' => (int) config('calling.softphone_staleness_seconds', 20) * 1000,
        'urls' => [
            'active' => route('tenant-lead-calls.active'),
            'claim' => route('tenant-lead-calls.claim', '__CALL__'),
            'answer' => route('tenant-lead-calls.answer', '__CALL__'),
            'accept' => route('tenant-lead-calls.accept', '__CALL__'),
            'reject' => route('tenant-lead-calls.reject', '__CALL__'),
            'hangup' => route('tenant-lead-calls.hangup', '__CALL__'),
            // WhatsApp Outbound Calling
            'initiateOutbound' => route('tenant-lead-calls.initiate-outbound', '__LEAD__'),
            'outboundAnswer' => route('tenant-lead-calls.outbound-answer', '__CALL__'),
            'cancelOutbound' => route('tenant-lead-calls.cancel-outbound', '__CALL__'),
            'outboundConnected' => route('tenant-lead-calls.outbound-connected', '__CALL__'),
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
            'notificationsUnsupported' => __('This browser cannot show call notifications here.'),
            'notificationsPermissionDenied' => __('Notifications are blocked for this site in your browser settings.'),
        ],
    ];
@endphp

{{-- Global softphone shell — mounted once in the tenant layout inside @persist, so
a live call survives wire:navigate. Both children below share this one x-data
instance but toggle independently: the notifications prompt has nothing to do
with whether a call is active, so it must not live inside the call-cards
container's own x-show (which stays hidden with zero active calls). --}}
<div x-data="softphone(@js($softphoneConfig))" x-cloak>
    {{-- Web Push opt-in — a real modal (backdrop + centered dialog), shown
    only while the browser hasn't yet decided (permission 'default') AND the
    user hasn't dismissed it this browser. 'granted'/'denied'/'unsupported'
    all hide it too: a denied permission can only be changed from the
    browser's own site settings, never by a second JS prompt. Hand-rolled
    rather than <flux:modal> — this is a plain Alpine component with no
    Livewire backing, and the file already hand-rolls its other overlays
    (Ringing/Connecting/… full-screen states below) the same way. --}}
    <div
        x-show="notificationsPermission === 'default' && !notificationsDismissed"
        class="pointer-events-auto fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/40 p-4"
        data-test="softphone-enable-notifications"
    >
        <div class="w-full max-w-sm rounded-xl bg-white p-6 shadow-lg ring ring-zinc-950/5 dark:bg-zinc-800 dark:ring-white/10">
            <flux:heading size="lg">{{ __('Enable call notifications?') }}</flux:heading>
            <flux:text class="mt-2" variant="subtle">
                {{ __('Get notified of incoming WhatsApp calls even when this tab is closed.') }}
            </flux:text>

            <div x-show="notificationsError" class="mt-3 rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-900 dark:border-red-500/40 dark:bg-red-500/10 dark:text-red-200" x-text="notificationsError"></div>

            <div class="mt-5 flex justify-end gap-2">
                <flux:button variant="ghost" x-on:click="dismissNotificationsPrompt()" x-bind:disabled="notificationsBusy">{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" x-on:click="enableNotifications()" x-bind:disabled="notificationsBusy">
                    <span x-show="!notificationsBusy">{{ __('Enable') }}</span>
                    <span x-show="notificationsBusy">{{ __('Enabling…') }}</span>
                </flux:button>
            </div>
        </div>
    </div>

    {{-- Desktop: a floating panel bottom-right. Mobile: a full-width bottom
    sheet. z-40 sits above the top bar (z-[15]) and the mobile sidebar drawer
    (z-20); dialogs and toasts render in the browser's top layer above it.
    The container ignores pointer events so it never blocks the page around
    the cards. --}}
    <div
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
        {{-- `contents` keeps this purely a grouping wrapper — it takes no box
        of its own, so the flex-col stack of compact cards and the fixed
        full-screen overlays (siblings below) are unaffected by it. Only the
        PRIMARY call (view.primaryCallId — this tab's own session, else the
        first ringing call) ever gets the phone full-screen treatment, and
        only while Ringing or Connected; every other card, and every other
        state of the primary card, keeps today's compact card unchanged. Both
        the compact and full-screen markup exist for every card at all times,
        toggled by CSS (x-show + the sm: breakpoint) rather than created/
        destroyed, so the full-screen buttons' own drag state never resets
        under a resize. --}}
        <div class="contents">
        <div
            class="pointer-events-auto rounded-xl border border-zinc-200 bg-white p-4 shadow-xl dark:border-zinc-700 dark:bg-zinc-800"
            :class="card.id === view.primaryCallId && ['ringing', 'connecting', 'connected', 'failed'].includes(card.mode) ? 'hidden sm:block' : ''"
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
                    {{-- WhatsApp Call Backup / Overflow Receiver — additive
                    only, never shown for the normal case (receivingAs ===
                    'assignee', the current viewer IS the lead's own
                    assignee) so a tenant that never uses this feature sees
                    no change at all. assignee/receivingAs are both
                    server-computed (ListActiveLeadCalls) and simply
                    rendered here, never inferred client-side. --}}
                    <div class="truncate text-xs text-zinc-500 dark:text-zinc-400" x-show="card.assignee && card.receivingAs !== 'assignee'">
                        {{ __('Assigned to') }}: <span class="font-medium text-zinc-700 dark:text-zinc-300" x-text="card.assignee?.name"></span>
                    </div>
                    <div class="text-xs font-medium text-indigo-600 dark:text-indigo-400" x-show="card.receivingAs === 'backup'">{{ __('Receiving as: Backup') }}</div>
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

                {{-- Connecting — WhatsApp Outbound Calling: an outbound
                card here covers both actually-negotiating AND still
                Ringing/awaiting-answer (snapshot() maps every non-
                connected/non-failed outbound phase to this same mode); its
                Cancel button is a different endpoint from Decline
                (CancelOutboundLeadCall, not RejectLeadCall) — see
                controller.js's canCancel/canDecline split. --}}
                <template x-if="card.mode === 'connecting'">
                    <div class="flex flex-col gap-3">
                        <span class="text-sm text-zinc-600 dark:text-zinc-300" x-text="card.direction === 'outbound' ? @js(__('Calling…')) : @js(__('Connecting…'))"></span>
                        <div class="flex gap-2">
                            <flux:button size="sm" variant="danger" x-show="card.direction === 'outbound'" x-on:click="cancelOutboundCall()" x-bind:disabled="!card.canCancel">{{ __('Cancel') }}</flux:button>
                            <flux:button size="sm" variant="danger" x-show="card.direction !== 'outbound'" x-on:click="decline(card.id)" x-bind:disabled="!card.canDecline">{{ __('Decline') }}</flux:button>
                        </div>
                    </div>
                </template>

                {{-- Failed --}}
                <template x-if="card.mode === 'failed'">
                    <div class="flex flex-col gap-3">
                        <span class="text-sm text-red-700 dark:text-red-300" x-text="card.error"></span>
                        <div class="flex gap-2">
                            <flux:button size="sm" variant="primary" x-show="card.retryable" x-on:click="retry()">{{ __('Retry') }}</flux:button>
                            <flux:button size="sm" variant="danger" x-show="card.direction === 'outbound'" x-on:click="cancelOutboundCall()">{{ __('Dismiss') }}</flux:button>
                            <flux:button size="sm" variant="danger" x-show="card.direction !== 'outbound'" x-on:click="decline(card.id)" x-bind:disabled="!card.canDecline">{{ __('Decline') }}</flux:button>
                        </div>
                    </div>
                </template>

                {{-- Connected — outbound gets a softer "In call…" label than
                inbound's "Connected": an outbound call's own real WebRTC
                connection is confirmed here, but that only proves this
                browser reached Meta's media relay, not that the consumer
                has actually picked up (Meta does not currently deliver a
                live "answered" signal for business-initiated calls — see
                RecordOutboundCallStatusEvent). Inbound has no such
                ambiguity: Connected there is the agent's own explicit
                Accept action. --}}
                <template x-if="card.mode === 'connected'">
                    <div class="flex flex-col gap-3">
                        <span class="text-sm text-emerald-700 dark:text-emerald-300" x-show="!card.reconnecting" x-text="card.direction === 'outbound' ? @js(__('In call…')) : @js(__('Connected'))"></span>
                        <span class="text-sm text-amber-700 dark:text-amber-300" x-show="card.reconnecting">{{ __('Reconnecting audio…') }}</span>
                        <div class="flex flex-wrap gap-2">
                            <flux:button size="sm" x-on:click="toggleMute()"><span x-text="card.muted ? @js(__('Unmute')) : @js(__('Mute'))"></span></flux:button>
                            {{-- Earpiece/loudspeaker — phone/tablet only (lg:hidden): a
                            desktop computer has no earpiece, so the toggle is
                            meaningless there. Also hidden on any device where
                            setSinkId()/distinct output devices aren't available at all
                            (iOS Safari, mainly — see resources/js/softphone/audio-output.js). --}}
                            <flux:button size="sm" class="lg:hidden" x-show="card.speakerAvailable" x-on:click="toggleSpeaker()">
                                <span class="flex items-center gap-1">
                                    <flux:icon.speaker-wave x-show="card.speakerOn" class="size-4" />
                                    <flux:icon.device-phone-mobile x-show="!card.speakerOn" class="size-4" />
                                    <span x-text="card.speakerOn ? @js(__('Speaker')) : @js(__('Earpiece'))"></span>
                                </span>
                            </flux:button>
                            <flux:button size="sm" variant="primary" x-show="card.audioBlocked" x-on:click="enableAudio()">{{ __('Enable audio') }}</flux:button>
                            <flux:button size="sm" variant="danger" x-on:click="hangup(card.id)" x-bind:disabled="!card.canHangup">{{ __('Hang up') }}</flux:button>
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

        {{-- Ringing — phone full-screen, primary call only. Both buttons stay
        directly tappable (a near-zero-movement release triggers immediately,
        same as a click); dragging past the threshold also triggers; a
        partial drag snaps back. See resources/js/softphone/drag.js. --}}
        <div
            x-show="card.id === view.primaryCallId && card.mode === 'ringing'"
            class="pointer-events-auto fixed inset-0 z-40 flex flex-col items-center justify-between bg-white px-6 py-12 text-center dark:bg-zinc-900 sm:hidden"
            :data-call-id="card.id"
            data-test="softphone-fullscreen-ringing"
        >
            <div class="flex flex-col items-center gap-3 pt-8">
                <span class="text-sm font-medium text-sky-600 dark:text-sky-400">{{ __('Incoming WhatsApp call') }}</span>
                <div class="relative flex size-24 items-center justify-center rounded-full bg-sky-100 text-sky-700 dark:bg-sky-500/20 dark:text-sky-300">
                    <span class="absolute inset-0 animate-ping rounded-full bg-sky-400/40"></span>
                    <flux:icon.phone class="relative size-10" />
                </div>
                <div class="mt-2">
                    <div class="text-xl font-semibold text-zinc-900 dark:text-white" x-text="card.lead.name || card.lead.phone || @js(__('Unknown caller'))"></div>
                    <div class="text-sm text-zinc-500 dark:text-zinc-400" x-show="card.lead.name && card.lead.phone" x-text="card.lead.phone"></div>
                    <div class="text-xs text-zinc-500 dark:text-zinc-400" x-show="card.assignee && card.receivingAs !== 'assignee'">
                        {{ __('Assigned to') }}: <span class="font-medium text-zinc-700 dark:text-zinc-300" x-text="card.assignee?.name"></span>
                    </div>
                    <div class="text-xs font-medium text-indigo-600 dark:text-indigo-400" x-show="card.receivingAs === 'backup'">{{ __('Receiving as: Backup') }}</div>
                </div>
                <span class="tabular-nums text-xs text-zinc-400 dark:text-zinc-500" x-show="card.remainingSeconds !== null" x-text="card.remainingSeconds + 's'"></span>
            </div>

            <p class="text-xs text-zinc-500 dark:text-zinc-400" x-show="view.mediaBusy && !card.canAnswer">{{ __('Finish your current call before answering another.') }}</p>

            <div class="flex w-full flex-col items-center gap-4 pb-2">
                <div class="flex w-full max-w-xs items-center justify-between px-4">
                    <div class="flex flex-col items-center gap-2">
                        <div
                            x-data="callDragButton()"
                            x-on:pointerdown="start($event)"
                            x-on:pointermove="move($event)"
                            x-on:pointerup="end($event)"
                            x-on:pointercancel="cancel()"
                            x-on:call-drag-trigger="decline(card.id)"
                            x-bind:style="dragStyle"
                            x-bind:class="!card.canDecline ? 'opacity-40 pointer-events-none' : ''"
                            class="flex size-16 cursor-pointer touch-none items-center justify-center rounded-full bg-red-500 text-white shadow-lg transition-transform"
                            aria-label="{{ __('Decline') }}"
                            role="button"
                        >
                            <flux:icon.phone-x-mark class="size-7" />
                        </div>
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Decline') }}</span>
                    </div>

                    <div class="flex flex-col items-center gap-2">
                        <div
                            x-data="callDragButton()"
                            x-on:pointerdown="start($event)"
                            x-on:pointermove="move($event)"
                            x-on:pointerup="end($event)"
                            x-on:pointercancel="cancel()"
                            x-on:call-drag-trigger="answer(card.id)"
                            x-bind:style="dragStyle"
                            x-bind:class="!card.canAnswer ? 'opacity-40 pointer-events-none' : ''"
                            class="flex size-16 cursor-pointer touch-none items-center justify-center rounded-full bg-emerald-500 text-white shadow-lg transition-transform"
                            aria-label="{{ __('Answer') }}"
                            role="button"
                        >
                            <flux:icon.phone class="size-7" />
                        </div>
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Answer') }}</span>
                    </div>
                </div>

                <p class="px-4 text-[11px] text-zinc-400 dark:text-zinc-500">{{ __('Decline ends the call for everyone it is ringing. Dismiss only hides it here.') }}</p>

                <button type="button" class="cursor-pointer text-xs text-zinc-400 underline dark:text-zinc-500" x-on:click="dismiss(card.id)">{{ __('Dismiss') }}</button>
            </div>
        </div>

        {{-- Connecting — phone full-screen, primary call only. Keeps the
        screen up continuously from Ringing through to Connected/Failed —
        dropping to the compact card for this brief WebRTC-negotiation window
        was a jarring flash back and forth, so it stays full-screen here too. --}}
        <div
            x-show="card.id === view.primaryCallId && card.mode === 'connecting'"
            class="pointer-events-auto fixed inset-0 z-40 flex flex-col items-center justify-between bg-white px-6 py-12 text-center dark:bg-zinc-900 sm:hidden"
            :data-call-id="card.id"
            data-test="softphone-fullscreen-connecting"
        >
            <div class="flex flex-col items-center gap-3 pt-8">
                <span class="text-sm font-medium text-zinc-500 dark:text-zinc-400" x-text="card.direction === 'outbound' ? @js(__('Calling…')) : @js(__('Connecting…'))"></span>
                <div class="flex size-24 animate-pulse items-center justify-center rounded-full bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                    <flux:icon.phone class="size-10" />
                </div>
                <div class="mt-2">
                    <div class="text-xl font-semibold text-zinc-900 dark:text-white" x-text="card.lead.name || card.lead.phone || @js(__('Unknown caller'))"></div>
                    <div class="text-sm text-zinc-500 dark:text-zinc-400" x-show="card.lead.name && card.lead.phone" x-text="card.lead.phone"></div>
                    <div class="text-xs text-zinc-500 dark:text-zinc-400" x-show="card.assignee && card.receivingAs !== 'assignee'">
                        {{ __('Assigned to') }}: <span class="font-medium text-zinc-700 dark:text-zinc-300" x-text="card.assignee?.name"></span>
                    </div>
                    <div class="text-xs font-medium text-indigo-600 dark:text-indigo-400" x-show="card.receivingAs === 'backup'">{{ __('Receiving as: Backup') }}</div>
                </div>
            </div>

            <button
                type="button"
                class="flex size-16 cursor-pointer items-center justify-center rounded-full bg-red-500 text-white shadow-lg disabled:cursor-not-allowed disabled:opacity-40"
                x-show="card.direction === 'outbound'"
                x-on:click="cancelOutboundCall()"
                x-bind:disabled="!card.canCancel"
                aria-label="{{ __('Cancel') }}"
            >
                <flux:icon.phone-x-mark class="size-7" />
            </button>
            <button
                type="button"
                class="flex size-16 cursor-pointer items-center justify-center rounded-full bg-red-500 text-white shadow-lg disabled:cursor-not-allowed disabled:opacity-40"
                x-show="card.direction !== 'outbound'"
                x-on:click="decline(card.id)"
                x-bind:disabled="!card.canDecline"
                aria-label="{{ __('Decline') }}"
            >
                <flux:icon.phone-x-mark class="size-7" />
            </button>
        </div>

        {{-- Failed — phone full-screen, primary call only. --}}
        <div
            x-show="card.id === view.primaryCallId && card.mode === 'failed'"
            class="pointer-events-auto fixed inset-0 z-40 flex flex-col items-center justify-between bg-white px-6 py-12 text-center dark:bg-zinc-900 sm:hidden"
            :data-call-id="card.id"
            data-test="softphone-fullscreen-failed"
        >
            <div class="flex flex-col items-center gap-3 pt-8">
                <div class="flex size-24 items-center justify-center rounded-full bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-300">
                    <flux:icon.phone-x-mark class="size-10" />
                </div>
                <div class="mt-2">
                    <div class="text-xl font-semibold text-zinc-900 dark:text-white" x-text="card.lead.name || card.lead.phone || @js(__('Unknown caller'))"></div>
                    <div class="text-sm text-zinc-500 dark:text-zinc-400" x-show="card.lead.name && card.lead.phone" x-text="card.lead.phone"></div>
                    <div class="text-xs text-zinc-500 dark:text-zinc-400" x-show="card.assignee && card.receivingAs !== 'assignee'">
                        {{ __('Assigned to') }}: <span class="font-medium text-zinc-700 dark:text-zinc-300" x-text="card.assignee?.name"></span>
                    </div>
                    <div class="text-xs font-medium text-indigo-600 dark:text-indigo-400" x-show="card.receivingAs === 'backup'">{{ __('Receiving as: Backup') }}</div>
                </div>
                <span class="text-sm text-red-700 dark:text-red-300" x-text="card.error"></span>
            </div>

            <div class="flex w-full max-w-xs items-center justify-center gap-10 pb-2">
                <flux:button variant="primary" x-show="card.retryable" x-on:click="retry()">{{ __('Retry') }}</flux:button>
                <button
                    type="button"
                    class="flex size-16 cursor-pointer items-center justify-center rounded-full bg-red-500 text-white shadow-lg disabled:cursor-not-allowed disabled:opacity-40"
                    x-show="card.direction === 'outbound'"
                    x-on:click="cancelOutboundCall()"
                    aria-label="{{ __('Dismiss') }}"
                >
                    <flux:icon.phone-x-mark class="size-7" />
                </button>
                <button
                    type="button"
                    class="flex size-16 cursor-pointer items-center justify-center rounded-full bg-red-500 text-white shadow-lg disabled:cursor-not-allowed disabled:opacity-40"
                    x-show="card.direction !== 'outbound'"
                    x-on:click="decline(card.id)"
                    x-bind:disabled="!card.canDecline"
                    aria-label="{{ __('Decline') }}"
                >
                    <flux:icon.phone-x-mark class="size-7" />
                </button>
            </div>
        </div>

        {{-- Connected — phone full-screen, primary call only. Hang Up is
        tap-only (no drag) so a stray swipe mid-call can never end it. --}}
        <div
            x-show="card.id === view.primaryCallId && card.mode === 'connected'"
            class="pointer-events-auto fixed inset-0 z-40 flex flex-col items-center justify-between bg-white px-6 py-12 text-center dark:bg-zinc-900 sm:hidden"
            :data-call-id="card.id"
            data-test="softphone-fullscreen-connected"
        >
            <div class="flex flex-col items-center gap-3 pt-8">
                <span
                    class="text-sm font-medium"
                    :class="card.reconnecting ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400'"
                    x-text="card.reconnecting ? @js(__('Reconnecting audio…')) : (card.direction === 'outbound' ? @js(__('In call…')) : @js(__('Connected')))"
                ></span>
                <div class="flex size-24 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300">
                    <flux:icon.phone class="size-10" />
                </div>
                <div class="mt-2">
                    <div class="text-xl font-semibold text-zinc-900 dark:text-white" x-text="card.lead.name || card.lead.phone || @js(__('Unknown caller'))"></div>
                    <div class="text-sm text-zinc-500 dark:text-zinc-400" x-show="card.lead.name && card.lead.phone" x-text="card.lead.phone"></div>
                    <div class="text-xs text-zinc-500 dark:text-zinc-400" x-show="card.assignee && card.receivingAs !== 'assignee'">
                        {{ __('Assigned to') }}: <span class="font-medium text-zinc-700 dark:text-zinc-300" x-text="card.assignee?.name"></span>
                    </div>
                    <div class="text-xs font-medium text-indigo-600 dark:text-indigo-400" x-show="card.receivingAs === 'backup'">{{ __('Receiving as: Backup') }}</div>
                </div>
                <span class="tabular-nums text-lg text-zinc-500 dark:text-zinc-400" x-text="format(card.elapsedSeconds)"></span>
            </div>

            <div class="flex w-full flex-col items-center gap-6 pb-2">
                <flux:button variant="primary" x-show="card.audioBlocked" x-on:click="enableAudio()" class="w-full max-w-xs">{{ __('Enable audio') }}</flux:button>

                <div class="flex w-full max-w-xs items-center justify-between gap-6">
                    <div class="flex flex-col items-center gap-2">
                        <button
                            type="button"
                            class="flex size-16 cursor-pointer items-center justify-center rounded-full text-zinc-700 shadow-lg dark:text-zinc-200"
                            :class="card.muted ? 'bg-zinc-300 dark:bg-zinc-600' : 'bg-zinc-100 dark:bg-zinc-800'"
                            x-on:click="toggleMute()"
                            aria-label="{{ __('Mute') }}"
                        >
                            <flux:icon.microphone class="size-7" />
                        </button>
                        <span class="text-xs text-zinc-500 dark:text-zinc-400" x-text="card.muted ? @js(__('Unmute')) : @js(__('Mute'))"></span>
                    </div>

                    {{-- Earpiece/loudspeaker — this full-screen layout is
                    phone-only already (sm:hidden on its parent, so a
                    desktop never reaches it), unlike the compact card's own
                    copy of this button, which still needs the lg:hidden
                    class since it's shared with tablet/desktop. --}}
                    <div class="flex flex-col items-center gap-2" x-show="card.speakerAvailable">
                        <button
                            type="button"
                            class="flex size-16 cursor-pointer items-center justify-center rounded-full text-zinc-700 shadow-lg dark:text-zinc-200"
                            :class="card.speakerOn ? 'bg-zinc-300 dark:bg-zinc-600' : 'bg-zinc-100 dark:bg-zinc-800'"
                            x-on:click="toggleSpeaker()"
                            aria-label="{{ __('Speaker') }}"
                        >
                            <flux:icon.speaker-wave x-show="card.speakerOn" class="size-7" />
                            <flux:icon.device-phone-mobile x-show="!card.speakerOn" class="size-7" />
                        </button>
                        <span class="text-xs text-zinc-500 dark:text-zinc-400" x-text="card.speakerOn ? @js(__('Speaker')) : @js(__('Earpiece'))"></span>
                    </div>

                    <div class="flex flex-col items-center gap-2">
                        <button
                            type="button"
                            class="flex size-16 cursor-pointer items-center justify-center rounded-full bg-red-500 text-white shadow-lg disabled:cursor-not-allowed disabled:opacity-40"
                            x-on:click="hangup(card.id)"
                            x-bind:disabled="!card.canHangup"
                            aria-label="{{ __('Hang up') }}"
                        >
                            <flux:icon.phone-x-mark class="size-7" />
                        </button>
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Hang up') }}</span>
                    </div>
                </div>
            </div>
        </div>
        </div>
    </template>
    </div>
</div>
