import { AnswerSession, PHASE } from './media.js';
import { OUTBOUND_PHASE, OutboundSession } from './outbound-media.js';

/**
 * WhatsApp inbound calling — the softphone's brain. Framework-free on purpose
 * (Alpine only renders `snapshot()`), so its state handling can be unit-tested
 * with every browser API replaced by a mock.
 *
 * Principles:
 *  - The server is the source of truth. Every realtime event, reconnect, `online`,
 *    visibility return and poll tick simply triggers a refetch of the active
 *    calls; an event's payload is never treated as state. Refetches are
 *    serialized (never two in flight; a burst coalesces into one re-run), so
 *    duplicate and out-of-order events cannot corrupt the view.
 *  - Realtime is primary; polling is only a recovery net, and only while the tab is
 *    visible and online — but it never fully stops. It runs at one of two speeds
 *    (`POLL_MODE.HEALTHY` at `heartbeatMs`, `POLL_MODE.DEGRADED` at `pollMs`),
 *    never both at once. The healthy-speed poll is a plain, Reverb-independent
 *    HTTP check — it alone bounds how late a brand-new call can be discovered
 *    even with a fully dead realtime connection, which is what makes a call with
 *    zero prior active calls (nothing for realtime to ever have delivered)
 *    discoverable at all. `lastFreshAt` tracks the last successful refresh
 *    (poll- or event-triggered, never the event's mere arrival); the connection
 *    escalates to the faster degraded speed only once `staleMs` has passed with
 *    none succeeding — a broader "even the HTTP path is unreliable" condition,
 *    never inferred from Pusher's own `connection.state`, which can lie. Any
 *    successful refresh while degraded returns to the healthy speed immediately.
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

export const POLL_MODE = {
    HEALTHY: 'healthy',
    DEGRADED: 'degraded',
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
        heartbeatMs = 15000,
        staleMs = 20000,
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
        this.heartbeatMs = heartbeatMs;
        this.staleMs = staleMs;
        this.noticeMs = noticeMs;
        this.texts = { ...DEFAULT_TEXTS, ...texts };
        this.onChange = onChange;

        this.calls = [];
        this.dismissed = new Set();
        this.busy = new Set();
        this.notices = [];
        this.session = null;
        // WhatsApp Outbound Calling — the lead's display name/phone, carried
        // in purely so snapshot() can render this tab's own outbound card
        // the instant callOutbound() is clicked, before the server row has
        // ever been polled (see snapshot()'s own note). Cleared alongside
        // session in finishSession(); never read once this.session is null.
        this.pendingLead = null;
        this.loaded = false;
        this.offline = false;
        this.noticeSeq = 0;

        this.refreshing = null;
        this.refreshQueued = false;
        this.pollTimer = null;
        this.pollTimerMs = null;
        this.mode = POLL_MODE.HEALTHY;
        this.lastFreshAt = null;
        this.unsubscribe = null;
        this.stopped = false;
        this.listeners = [];
    }

    // ── Lifecycle ───────────────────────────────────────────────────────────

    start() {
        this.offline = this.win.navigator?.onLine === false;
        // A fair baseline: "just started" counts as fresh until a refresh
        // actually fails to keep up, not as already-stale before the first
        // one has even had a chance to run.
        this.lastFreshAt = this.now();

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
        // event / poll / reconnect tries again. lastFreshAt is deliberately NOT
        // touched here — only evaluateMode() re-checks whether enough time has
        // now passed since the last SUCCESS to escalate polling.
        this.loaded = true;
        this.evaluateMode();
        this.notify();
    }

    apply(rawCalls) {
        const receivedAt = this.now();
        this.lastFreshAt = receivedAt;
        const next = rawCalls.map((raw) => ({
            id: raw.id,
            status: raw.status,
            // WhatsApp Outbound Calling — only meaningfully consulted for a
            // call this tab does NOT own (see snapshot()'s ringing branch);
            // the tab's own card derives direction from its session instead.
            direction: raw.direction === 'outbound' ? 'outbound' : 'inbound',
            claimedByMe: raw.claimed_by_me === true,
            // WhatsApp Outbound Calling — Meta's own ACCEPTED call-status
            // webhook, surfaced by ListActiveLeadCalls. Always false for an
            // inbound call.
            accepted: raw.accepted === true,
            lead: { id: raw.lead?.id ?? null, name: raw.lead?.name ?? null, phone: raw.lead?.phone ?? null },
            // WhatsApp Call Backup / Overflow Receiver — both server-
            // computed (ListActiveLeadCalls); rendered as-is, never
            // recomputed here. assignee stays null exactly when the server
            // says so (a genuinely unassigned lead, or a stale/deleted
            // assignee row) — never synthesized.
            assignee: raw.assignee ? { id: raw.assignee.id ?? null, name: raw.assignee.name ?? null } : null,
            receivingAs: typeof raw.receiving_as === 'string' ? raw.receiving_as : 'assignee',
            expiresAt: typeof raw.expires_in_seconds === 'number' ? receivedAt + raw.expires_in_seconds * 1000 : null,
            connectedAt: raw.connected_at ? Date.parse(raw.connected_at) || null : null,
        }));

        const present = new Set(next.map((call) => call.id));
        this.dismissed = new Set([...this.dismissed].filter((id) => present.has(id)));
        this.calls = next;
        this.loaded = true;

        // The call this tab is handling is no longer active on the server: it
        // ended (caller hung up, Meta terminated it, a reject, a failure).
        // callId !== null excludes an OutboundSession still in its brief
        // INITIATING window (no server-side call to match against yet) —
        // never true for AnswerSession, whose callId is always known from
        // construction, so this guard is a no-op for every inbound call.
        if (this.session && this.session.callId !== null && !present.has(this.session.callId)) {
            this.finishSession(this.texts.ended);
        }

        // WhatsApp Outbound Calling — the controller-driven nudge
        // OutboundSession.applyAnswer() depends on: this call's status
        // reaching 'answering' (via a poll/realtime-triggered refresh,
        // never trusted from the realtime payload itself) is what tells the
        // session to go fetch the answer. Meta's SDP answer arriving is not
        // itself evidence the consumer answered — RecordOutboundCallEvent
        // only ever moves a row to 'answering'; ConfirmOutboundCallConnected,
        // driven by this same session once its own WebRTC connection is
        // real, is the only path to 'connected'. A no-op once already applied.
        if (this.session instanceof OutboundSession && this.session.callId) {
            const outboundCall = next.find((candidate) => candidate.id === this.session.callId);

            if (outboundCall && outboundCall.status === 'answering') {
                this.session.applyAnswer();
            }
        }

        this.notify();
        this.evaluateMode();
    }

    // ── Polling / visibility / connectivity ─────────────────────────────────

    isVisible() {
        return this.doc.visibilityState !== 'hidden';
    }

    /**
     * The interval the poll timer should be running at for the CURRENT mode.
     */
    currentPollInterval() {
        return this.mode === POLL_MODE.DEGRADED ? this.pollMs : this.heartbeatMs;
    }

    /**
     * Re-derives mode purely from `now() - lastFreshAt` (never from Pusher's own
     * `connection.state`) and, if visible/online, makes sure the poll timer is
     * running at whatever interval that mode now calls for. Called after every
     * refresh attempt, success or failure, from apply()/onRefreshError() — the
     * only two places a refresh can settle.
     */
    evaluateMode() {
        if (this.stopped) {
            return;
        }

        const age = this.now() - this.lastFreshAt;
        this.mode = age >= this.staleMs ? POLL_MODE.DEGRADED : POLL_MODE.HEALTHY;

        if (this.isVisible() && !this.offline) {
            this.rearmPollTimer();
        }
    }

    syncPolling() {
        if (this.isVisible() && !this.offline && !this.stopped) {
            this.rearmPollTimer();
        } else {
            this.pausePolling();
        }
    }

    /**
     * Starts (or restarts) the single poll timer at the current mode's
     * interval. A no-op if it is already running at that same interval, so a
     * realtime event succeeding mid-cycle doesn't keep resetting the healthy
     * heartbeat's phase — only an actual mode change ever re-arms it, and
     * pausePolling() first guarantees the old interval is never left running
     * alongside the new one.
     */
    rearmPollTimer() {
        const desired = this.currentPollInterval();

        if (this.pollTimer !== null && this.pollTimerMs === desired) {
            return;
        }

        this.pausePolling();
        this.pollTimerMs = desired;
        this.pollTimer = this.timers.setInterval(() => this.refresh(), desired);
    }

    pausePolling() {
        if (this.pollTimer !== null) {
            this.timers.clearInterval(this.pollTimer);
            this.pollTimer = null;
            this.pollTimerMs = null;
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

        // WhatsApp Outbound Calling — a defense-in-depth guard, not just the
        // hidden Answer button in snapshot(): an outbound call has no
        // cached offer server-side at all (ClaimLeadCall would fail it as
        // offer_unavailable), so this must never be reachable regardless of
        // how answer() ends up called — a stale reference, a replayed
        // click, or future code that doesn't go through the card's own
        // canAnswer flag.
        if (!call || call.status !== 'ringing' || call.direction === 'outbound' || this.isExpired(call) || this.busy.has(callId) || this.mediaBusy()) {
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
     * WhatsApp Outbound Calling — places a call to a Lead. Must be invoked
     * directly from the "Call" click, exactly like answer(): the microphone
     * permission prompt is requested from that user gesture, before
     * anything is sent to the server.
     *
     * `leadInfo` ({id, name, phone}), when the caller has it to hand (the
     * "Call" button does — see index.js), is stored as pendingLead purely so
     * snapshot() can render this tab's own card immediately, before the
     * server row has ever been polled. Display-only — never sent anywhere,
     * never trusted for anything but text.
     */
    async callOutbound(leadId, leadInfo = null) {
        if (this.mediaBusy() || this.busy.has(leadId) || this.stopped) {
            return;
        }

        const media = this.createMedia();

        if (!media.supported) {
            this.addNotice(this.texts.unsupported, 'error');

            return;
        }

        this.busy.add(leadId);
        this.notify();

        let stream;

        try {
            stream = await media.getMicrophone();
        } catch {
            this.busy.delete(leadId);
            this.addNotice(this.texts.micNeeded, 'error');
            this.notify();

            return;
        }

        if (this.session !== null || this.stopped) {
            stream.getTracks().forEach((track) => track.stop());
            this.busy.delete(leadId);
            this.notify();

            return;
        }

        this.pendingLead = leadInfo ?? { id: leadId, name: null, phone: null };
        this.session = new OutboundSession({
            leadId,
            api: this.api,
            stream,
            env: media.env,
            audioEl: media.audioEl,
            timers: this.timers,
            now: this.now,
            onChange: () => this.onSessionChange(),
        });
        this.busy.delete(leadId);
        this.notify();

        await this.session.initiate();

        if (this.session?.callId) {
            this.ownership.claim(this.session.callId);
        }

        this.afterOutboundInitiate();
    }

    /**
     * After initiate() or retry(): a failed attempt ends the local session —
     * either the server never created a row (refused before Meta), already
     * recorded its own Failed outcome, or a retry() exhausted itself. A
     * successful attempt needs no action here; applyAnswer()'s own CONNECTED
     * transition already notified.
     */
    afterOutboundInitiate() {
        const session = this.session;

        if (session instanceof OutboundSession && session.phase === 'failed') {
            this.finishSession(session.error?.message || this.texts.genericError);
            this.refresh();
        }
    }

    /**
     * Cancels this tab's own outbound call before it connects. While still
     * INITIATING (no callId yet — the connect() request hasn't resolved),
     * there is nothing server-side to cancel, so this only tears the local
     * session down; once Ringing, CancelOutboundLeadCall is called first,
     * local-first exactly like decline()/hangup().
     */
    async cancelOutboundCall() {
        const session = this.session;

        if (!(session instanceof OutboundSession) || session.phase === 'connected') {
            return;
        }

        const callId = session.callId;

        if (!callId) {
            this.finishSession();

            return;
        }

        if (this.busy.has(callId)) {
            return;
        }

        this.busy.add(callId);
        this.notify();

        try {
            await this.api.cancelOutbound(callId);
        } catch {
            // Local-first, never rolled back — the session is torn down
            // below regardless of whether Meta confirmed the terminate.
        } finally {
            this.finishSession(this.texts.ended);
            this.calls = this.calls.filter((call) => call.id !== callId);
            this.busy.delete(callId);
            this.notify();
            this.refresh();
        }
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

        if (this.session instanceof OutboundSession) {
            await this.session.retry();
            this.afterOutboundInitiate();

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
            this.pendingLead = null;
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
        let sessionRendered = false;

        for (const call of this.calls) {
            const own = this.session?.callId === call.id;

            if (own) {
                sessionRendered = true;
            }

            const base = {
                id: call.id,
                lead: call.lead,
                // WhatsApp Call Backup / Overflow Receiver — carried through
                // from apply()'s own call record unchanged; every card mode
                // below spreads `...base`, so this survives every state
                // transition (ringing → connecting → connected, etc.) the
                // same way `lead` already does, since it comes from the
                // same source and is never recomputed per-mode.
                assignee: call.assignee,
                receivingAs: call.receivingAs,
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
                const outbound = session instanceof OutboundSession;
                cards.push({
                    ...base,
                    direction: outbound ? 'outbound' : 'inbound',
                    mode: session.phase === 'connected' ? MODE.CONNECTED : session.phase === 'failed' ? MODE.FAILED : MODE.CONNECTING,
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
                    elapsedSeconds: session.phase === 'connected'
                        ? Math.max(0, Math.floor((now - (session.connectedAt ?? call.connectedAt ?? now)) / 1000))
                        : null,
                    // Inbound: Decline covers pre-connect states. Outbound has
                    // no decline concept at all (cancelOutboundCall() covers
                    // its pre-connect states instead, a different endpoint —
                    // see CancelOutboundLeadCall). Either way, once Connected,
                    // Hang Up (Step 7a, reused unchanged for both directions)
                    // is the only way this tab can end its own call.
                    canDecline: !outbound && session.phase !== PHASE.CONNECTED,
                    // CancelOutboundLeadCall (server) only allows Initiated/
                    // Ringing — cancellation once the consumer's acceptance
                    // and/or real WebRTC negotiation is in progress is an
                    // open product question the architecture change did not
                    // decide, so this stays scoped to what the backend
                    // actually allows today rather than offering a button
                    // that would be refused. Gated on the SERVER's own
                    // `call.status`, not local phase — the local phase
                    // deliberately stays RINGING (for ringback) well past
                    // the point the server has already moved to Answering,
                    // so local phase alone can no longer stand in for
                    // "still cancellable" here (unlike canHangup below,
                    // which is keyed off CONNECTED — a point local phase and
                    // server status always agree on, since goLive() only
                    // ever runs after the server has already confirmed it).
                    canCancel: outbound && call.status === 'ringing',
                    canHangup: session.phase === (outbound ? 'connected' : PHASE.CONNECTED) && !base.busy,
                    canAnswer: false,
                    canDismiss: false,
                });
            } else if (call.status === 'ringing' && call.direction !== 'outbound') {
                // WhatsApp Outbound Calling — an outbound call never belongs
                // in this inbound ringing/Answer card, direction-checked
                // here rather than relying on `own` alone: after a reload
                // this tab's own outbound session is gone (own is false),
                // so without this check a still-Ringing outbound call would
                // otherwise fall through to this branch and wrongly expose
                // an "Answer" button — clicking it would POST to the
                // inbound-only /claim endpoint, which has no offer cached
                // for an outbound call at all and would incorrectly fail a
                // perfectly healthy in-progress call. It falls through to
                // the claimedByMe branch below instead, same as a reloaded
                // Connected call already does — read-only, no inbound
                // action ever offered for it.
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
                    // An outbound call now also reaches 'answering' (Meta's SDP
                    // answer received, not yet browser-confirmed — see
                    // RecordOutboundCallEvent/ConfirmOutboundCallConnected), so
                    // direction is checked here too, same reasoning as the
                    // Ringing branch above: a reloaded outbound Answering call
                    // must never expose the inbound-only decline/reject action.
                    canDecline: call.status === 'answering' && call.direction !== 'outbound' && !otherTabs.has(call.id) && !base.busy,
                    canDismiss: false,
                });
            }
        }

        // WhatsApp Outbound Calling — the card must appear the INSTANT
        // callOutbound() is clicked, not only once the server row has been
        // polled at least once (a real, sometimes multi-second gap: mic
        // permission + createOffer()/setLocalDescription() + the initiate
        // request all happen before a callId even exists, and even after
        // that the row is only visible once the next active-calls refresh
        // returns it). Synthesized directly from local session state using
        // pendingLead for display — this only ever applies during
        // INITIATING/RINGING (or a FAILED that never got as far as the
        // first refresh): once the row has appeared in `this.calls` even
        // once, it stays there for the rest of the call's active lifetime,
        // so the loop above always renders it from then on and this never
        // fires again for the same call.
        if (this.session instanceof OutboundSession && !sessionRendered) {
            cards.push(this.buildPendingOutboundCard(now));
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
            primaryCallId: this.session?.callId ?? (this.session ? this.session.leadId : null) ?? cards.find((card) => card.mode === MODE.RINGING)?.id ?? null,
        };
    }

    /**
     * The card for this tab's own outbound session before its call row has
     * ever appeared in `this.calls` — see snapshot()'s own note. Keyed by
     * `session.callId ?? session.leadId` so it has a stable id even during
     * the brief INITIATING window with no call id at all yet.
     */
    buildPendingOutboundCard(now) {
        const session = this.session;
        const lead = this.pendingLead ?? { id: session.leadId, name: null, phone: null };

        return {
            id: session.callId ?? session.leadId,
            lead,
            // No server row exists yet for this instant-synthesized card
            // (see this method's own docblock), so the real assignee is not
            // yet known here — the moment the server row appears in
            // this.calls on the next refresh, the loop above renders the
            // real, server-computed value instead. This tab is inherently
            // the one placing the call, so receivingAs is the one fact
            // already knowable without a round-trip — the same category as
            // `direction: 'outbound'` two lines below, not a routing
            // decision made in JavaScript.
            assignee: null,
            receivingAs: 'agent_initiated',
            remainingSeconds: null,
            elapsedSeconds: null,
            busy: false,
            direction: 'outbound',
            mode: session.phase === 'failed' ? MODE.FAILED : MODE.CONNECTING,
            phase: session.phase,
            error: session.error?.message ?? null,
            retryable: session.error?.retryable === true,
            muted: session.muted,
            audioBlocked: session.audioBlocked,
            reconnecting: false,
            // The server row, once it exists at all, is always created
            // Ringing (InitiateOutboundCall) — so for as long as this
            // synthesized card applies at all (INITIATING/RINGING only, see
            // above), cancelling is always legitimate to offer.
            canCancel: true,
            canDecline: false,
            canHangup: false,
            canAnswer: false,
            canDismiss: false,
        };
    }
}
