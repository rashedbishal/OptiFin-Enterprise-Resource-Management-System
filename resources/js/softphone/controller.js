import { AnswerSession, PHASE } from './media.js';

/**
 * WhatsApp inbound calling — the softphone's brain. Framework-free on purpose
 * (Alpine only renders `snapshot()`), so its state handling can be unit-tested
 * with every browser API replaced by a mock.
 *
 * Principles:
 *  - The server is the source of truth. Every realtime event, reconnect, `online`,
 *    visibility return and the 30-second safety poll simply triggers a refetch of
 *    the active calls; an event's payload is never treated as state. Refetches are
 *    serialized (never two in flight; a burst coalesces into one re-run), so
 *    duplicate and out-of-order events cannot corrupt the view.
 *  - Realtime is primary; polling is only a recovery net, and only while the tab is
 *    visible and online.
 *  - One media session per browser: while a call owns it (here or in another tab),
 *    Answer is disabled on every other ringing card; Decline and Dismiss stay.
 *  - Nothing sensitive is stored: state is in memory, no SDP/credentials anywhere.
 *  - A page reload cannot restore a WebRTC connection, so an Answering / Connected
 *    call this tab does not own is shown read-only ("lost"), never re-answered.
 */

export const MODE = {
    RINGING: 'ringing',
    CONNECTING: 'connecting',
    CONNECTED: 'connected',
    FAILED: 'failed',
    OTHER_TAB: 'other-tab',
    LOST: 'lost',
};

export const DEFAULT_TEXTS = {
    micNeeded: 'Microphone access is needed to answer calls.',
    unsupported: 'This browser cannot take calls here. Use a current browser over HTTPS.',
    ended: 'The call ended.',
    handledElsewhere: 'This call is being handled elsewhere.',
    declined: 'Call declined.',
    declinedUnconfirmed: 'Declined here — WhatsApp may take a moment to stop ringing.',
    offline: 'You are offline. Calls will reappear when you reconnect.',
    genericError: 'Something went wrong with the call.',
};

// Server errors that mean "this call is no longer yours to act on".
const GONE_CODES = new Set(['already_claimed', 'call_not_active', 'call_expired', 'offer_unavailable', 'call_state_changed', 'call_not_found', 'not_claimant', 'sdp_mismatch', 'answer_unavailable', 'call_not_answerable', 'call_not_claimed', 'call_not_connected']);

export class SoftphoneController {
    constructor({
        api,
        createMedia,
        ownership,
        subscribe,
        doc = document,
        win = window,
        timers = {
            setInterval: (...a) => setInterval(...a),
            clearInterval: (...a) => clearInterval(...a),
            setTimeout: (...a) => setTimeout(...a),
            clearTimeout: (...a) => clearTimeout(...a),
        },
        now = () => Date.now(),
        pollMs = 30000,
        noticeMs = 8000,
        texts = {},
        onChange = () => {},
    }) {
        this.api = api;
        this.createMedia = createMedia;
        this.ownership = ownership;
        this.subscribe = subscribe;
        this.doc = doc;
        this.win = win;
        this.timers = timers;
        this.now = now;
        this.pollMs = pollMs;
        this.noticeMs = noticeMs;
        this.texts = { ...DEFAULT_TEXTS, ...texts };
        this.onChange = onChange;

        this.calls = [];
        this.dismissed = new Set();
        this.busy = new Set();
        this.notices = [];
        this.session = null;
        this.loaded = false;
        this.offline = false;
        this.noticeSeq = 0;

        this.refreshing = null;
        this.refreshQueued = false;
        this.pollTimer = null;
        this.unsubscribe = null;
        this.stopped = false;
        this.listeners = [];
    }

    // ── Lifecycle ───────────────────────────────────────────────────────────

    start() {
        this.offline = this.win.navigator?.onLine === false;

        this.unsubscribe = this.subscribe({
            onEvent: () => this.refresh(),
            onReconnect: () => this.refresh(),
        });

        this.listen(this.doc, 'visibilitychange', () => this.onVisibilityChange());
        this.listen(this.win, 'online', () => this.onOnline());
        this.listen(this.win, 'offline', () => this.onOffline());
        this.unsubscribeOwnership = this.ownership.onChange(() => this.notify());

        this.refresh();
        this.syncPolling();
    }

    stop() {
        this.stopped = true;
        this.unsubscribe?.();
        this.unsubscribeOwnership?.();
        this.listeners.forEach(([target, name, handler]) => target.removeEventListener(name, handler));
        this.listeners = [];
        this.pausePolling();
        this.finishSession();
    }

    listen(target, name, handler) {
        target.addEventListener(name, handler);
        this.listeners.push([target, name, handler]);
    }

    // ── Refetching: serialized, coalesced ───────────────────────────────────

    refresh() {
        if (this.stopped) {
            return Promise.resolve();
        }

        if (this.refreshing) {
            this.refreshQueued = true;

            return this.refreshing;
        }

        this.refreshing = (async () => {
            do {
                this.refreshQueued = false;

                try {
                    this.apply(await this.api.active());
                } catch (error) {
                    this.onRefreshError(error);
                }
            } while (this.refreshQueued && !this.stopped);
        })().finally(() => {
            this.refreshing = null;
        });

        return this.refreshing;
    }

    onRefreshError() {
        // A failed read changes nothing: the previous view stays, and the next
        // event / poll / reconnect tries again.
        this.loaded = true;
        this.notify();
    }

    apply(rawCalls) {
        const receivedAt = this.now();
        const next = rawCalls.map((raw) => ({
            id: raw.id,
            status: raw.status,
            claimedByMe: raw.claimed_by_me === true,
            lead: { id: raw.lead?.id ?? null, name: raw.lead?.name ?? null, phone: raw.lead?.phone ?? null },
            expiresAt: typeof raw.expires_in_seconds === 'number' ? receivedAt + raw.expires_in_seconds * 1000 : null,
            connectedAt: raw.connected_at ? Date.parse(raw.connected_at) || null : null,
        }));

        const present = new Set(next.map((call) => call.id));
        this.dismissed = new Set([...this.dismissed].filter((id) => present.has(id)));
        this.calls = next;
        this.loaded = true;

        // The call this tab is handling is no longer active on the server: it
        // ended (caller hung up, Meta terminated it, a reject, a failure).
        if (this.session && !present.has(this.session.callId)) {
            this.finishSession(this.texts.ended);
        }

        this.notify();
    }

    // ── Polling / visibility / connectivity ─────────────────────────────────

    isVisible() {
        return this.doc.visibilityState !== 'hidden';
    }

    syncPolling() {
        if (this.isVisible() && !this.offline && !this.stopped) {
            this.pollTimer ??= this.timers.setInterval(() => this.refresh(), this.pollMs);
        } else {
            this.pausePolling();
        }
    }

    pausePolling() {
        if (this.pollTimer !== null) {
            this.timers.clearInterval(this.pollTimer);
            this.pollTimer = null;
        }
    }

    onVisibilityChange() {
        this.syncPolling();

        if (this.isVisible() && !this.offline) {
            this.refresh();
        }
    }

    onOnline() {
        this.offline = false;
        this.syncPolling();
        this.refresh();
        this.notify();
    }

    onOffline() {
        this.offline = true;
        this.pausePolling();
        this.notify();
    }

    // ── Actions ─────────────────────────────────────────────────────────────

    findCall(id) {
        return this.calls.find((call) => call.id === id) ?? null;
    }

    mediaBusy() {
        return this.session !== null || this.ownership.otherOwnedCallIds().size > 0;
    }

    isExpired(call) {
        return call.expiresAt !== null && call.expiresAt <= this.now();
    }

    dismiss(callId) {
        const call = this.findCall(callId);

        // Client-only: hides the ringing card in THIS browser. Nothing is sent,
        // nothing is stored, no call changes state.
        if (call && call.status === 'ringing' && this.session?.callId !== callId) {
            this.dismissed.add(callId);
            this.notify();
        }
    }

    /**
     * Must be invoked directly from the Answer click: the microphone permission
     * prompt is requested from that user gesture, before anything is claimed, so a
     * denial never strands a claimed call.
     */
    async answer(callId) {
        const call = this.findCall(callId);

        if (!call || call.status !== 'ringing' || this.isExpired(call) || this.busy.has(callId) || this.mediaBusy()) {
            return;
        }

        const media = this.createMedia();

        if (!media.supported) {
            this.addNotice(this.texts.unsupported, 'error');

            return;
        }

        this.busy.add(callId);
        this.notify();

        let stream;

        try {
            stream = await media.getMicrophone();
        } catch {
            this.busy.delete(callId);
            this.addNotice(this.texts.micNeeded, 'error');
            this.notify();

            return;
        }

        // The tab may have started another call while the permission prompt was open.
        if (this.session !== null || this.stopped) {
            stream.getTracks().forEach((track) => track.stop());
            this.busy.delete(callId);

            return;
        }

        this.ownership.claim(callId);
        this.session = new AnswerSession({
            callId,
            api: this.api,
            stream,
            env: media.env,
            audioEl: media.audioEl,
            connectTimeoutMs: this.connectTimeout(call),
            timers: this.timers,
            now: this.now,
            onChange: () => this.onSessionChange(),
        });
        this.busy.delete(callId);
        this.notify();

        await this.session.run();
        this.afterSessionStep();
    }

    connectTimeout(call) {
        const remaining = call.expiresAt === null ? 20000 : call.expiresAt - this.now();

        return Math.min(30000, Math.max(5000, remaining));
    }

    onSessionChange() {
        this.notify();
    }

    /**
     * After a step of the answer flow settles: a failure that says the call is no
     * longer ours ends the session and refetches; anything else stays on screen
     * (retryable → Retry, otherwise Decline).
     */
    afterSessionStep() {
        const session = this.session;

        if (!session || session.phase !== PHASE.FAILED) {
            return;
        }

        if (GONE_CODES.has(session.error?.code) || session.error?.code === 'session_expired') {
            const message = session.error.code === 'sdp_mismatch' || session.error.code === 'already_claimed'
                ? this.texts.handledElsewhere
                : (session.error.message || this.texts.ended);

            this.finishSession(message);
            this.refresh();
        }
    }

    async retry() {
        if (!this.session) {
            return;
        }

        await this.session.retry();
        this.afterSessionStep();
    }

    async decline(callId) {
        if (this.busy.has(callId)) {
            return;
        }

        this.busy.add(callId);
        this.notify();

        try {
            const result = await this.api.reject(callId);
            const own = this.session?.callId === callId;

            if (own) {
                this.finishSession();
            }

            this.calls = this.calls.filter((call) => call.id !== callId);
            this.addNotice(result?.meta_confirmed === false ? this.texts.declinedUnconfirmed : this.texts.declined, 'info');
        } catch (error) {
            if (GONE_CODES.has(error.code) || error.code === 'call_not_rejectable') {
                if (this.session?.callId === callId) {
                    this.finishSession(this.texts.ended);
                }
            } else {
                this.addNotice(error.message || this.texts.genericError, 'error');
            }
        } finally {
            this.busy.delete(callId);
            this.notify();
            this.refresh();
        }
    }

    /**
     * Ends the connected call this tab owns (Step 7a). Local state is
     * authoritative the instant the request succeeds, so the media session is
     * torn down immediately here — never waiting for the next poll or realtime
     * event — matching how the server never rolls its own Completed transition
     * back either, regardless of what Meta's terminate call reports.
     */
    async hangup(callId) {
        if (this.busy.has(callId) || this.session?.callId !== callId || this.session.phase !== PHASE.CONNECTED) {
            return;
        }

        this.busy.add(callId);
        this.notify();

        try {
            await this.api.hangup(callId);
            this.finishSession(this.texts.ended);
            this.calls = this.calls.filter((call) => call.id !== callId);
        } catch (error) {
            if (GONE_CODES.has(error.code)) {
                this.finishSession(this.texts.ended);
            } else {
                this.addNotice(error.message || this.texts.genericError, 'error');
            }
        } finally {
            this.busy.delete(callId);
            this.notify();
            this.refresh();
        }
    }

    toggleMute() {
        if (this.session && this.session.phase === PHASE.CONNECTED) {
            this.session.setMuted(!this.session.muted);
        }
    }

    enableAudio() {
        return this.session?.enableAudio();
    }

    /**
     * Tears the media session down (peer connection, microphone, audio element)
     * and releases this tab's ownership. Safe to call when nothing is active.
     */
    finishSession(message = null) {
        if (this.session) {
            this.session.close();
            this.session = null;
            this.ownership.release();

            if (message) {
                this.addNotice(message, 'info');
            }
        }

        this.notify();
    }

    // ── View model ──────────────────────────────────────────────────────────

    addNotice(text, kind) {
        const id = `n${++this.noticeSeq}`;
        this.notices = [...this.notices, { id, text, kind }];
        this.timers.setTimeout(() => {
            this.notices = this.notices.filter((notice) => notice.id !== id);
            this.notify();
        }, this.noticeMs);
        this.notify();
    }

    notify() {
        if (!this.stopped) {
            this.onChange(this.snapshot());
        }
    }

    /**
     * Everything the template needs, as plain data. Recomputed from `now()`, so a
     * once-a-second `notify()` keeps countdowns and timers current.
     */
    snapshot() {
        const now = this.now();
        const otherTabs = this.ownership.otherOwnedCallIds();
        const mediaBusy = this.session !== null || otherTabs.size > 0;
        const cards = [];

        for (const call of this.calls) {
            const own = this.session?.callId === call.id;
            const base = {
                id: call.id,
                lead: call.lead,
                remainingSeconds: call.expiresAt === null ? null : Math.max(0, Math.ceil((call.expiresAt - now) / 1000)),
                elapsedSeconds: call.connectedAt === null ? null : Math.max(0, Math.floor((now - call.connectedAt) / 1000)),
                busy: this.busy.has(call.id),
                error: null,
                retryable: false,
                muted: false,
                audioBlocked: false,
                reconnecting: false,
            };

            if (own) {
                const session = this.session;
                cards.push({
                    ...base,
                    mode: session.phase === PHASE.CONNECTED ? MODE.CONNECTED : session.phase === PHASE.FAILED ? MODE.FAILED : MODE.CONNECTING,
                    phase: session.phase,
                    error: session.error?.message ?? null,
                    retryable: session.error?.retryable === true,
                    muted: session.muted,
                    audioBlocked: session.audioBlocked,
                    reconnecting: session.reconnecting,
                    // Prefer this tab's own locally-observed connect moment
                    // (session.connectedAt, set the instant audio went live)
                    // over the server-derived base.elapsedSeconds, which
                    // depends on a poll/realtime round-trip that can lag or
                    // simply never land on this same tab.
                    elapsedSeconds: session.phase === PHASE.CONNECTED
                        ? Math.max(0, Math.floor((now - (session.connectedAt ?? call.connectedAt ?? now)) / 1000))
                        : null,
                    // Decline covers pre-connect states; once Connected, Hang Up
                    // (Step 7a) is the only way this tab can end its own call.
                    canDecline: session.phase !== PHASE.CONNECTED,
                    canHangup: session.phase === PHASE.CONNECTED && !base.busy,
                    canAnswer: false,
                    canDismiss: false,
                });
            } else if (call.status === 'ringing') {
                if (this.dismissed.has(call.id) || this.isExpired(call)) {
                    continue;
                }

                cards.push({
                    ...base,
                    mode: MODE.RINGING,
                    phase: 'ringing',
                    canAnswer: !mediaBusy && !base.busy,
                    canDecline: !base.busy,
                    canDismiss: true,
                });
            } else if (call.claimedByMe) {
                cards.push({
                    ...base,
                    mode: otherTabs.has(call.id) ? MODE.OTHER_TAB : MODE.LOST,
                    phase: call.status,
                    canAnswer: false,
                    // Only a call still being answered can be declined here. Hang Up
                    // (Step 7a) needs the live media session, which only the tab that
                    // owns it has — never this one (other-tab or post-reload "lost").
                    canDecline: call.status === 'answering' && !otherTabs.has(call.id) && !base.busy,
                    canDismiss: false,
                });
            }
        }

        return {
            loaded: this.loaded,
            offline: this.offline,
            offlineText: this.texts.offline,
            cards,
            notices: this.notices,
            mediaBusy,
            // Which card (if any) takes over the phone-width full screen —
            // this tab's own session, by id, for its entire lifecycle
            // (ringing/connecting/connected/failed — the blade template picks
            // which of those four get an actual full-screen layout vs falling
            // back to the compact card by that same id), else the first
            // ringing card so an unclaimed incoming call still rings
            // full-screen. A second simultaneous call never also goes
            // full-screen — it stays the existing compact card, on every width.
            primaryCallId: this.session?.callId ?? cards.find((card) => card.mode === MODE.RINGING)?.id ?? null,
        };
    }
}
