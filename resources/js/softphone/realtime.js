/**
 * WhatsApp inbound calling — the realtime side of the softphone: one listener on
 * the user's OWN private channel for `.call.updated`, and a hook for "Reverb
 * reconnected". The event is only a nudge — the payload (call id + status) is
 * never trusted as state; the caller refetches the active calls instead — so a
 * duplicate, delayed or out-of-order event is harmless.
 *
 * The channel is shared with the notification bell (same Echo connection), so
 * this only stops ITS listener on teardown and never leaves the channel.
 *
 * WhatsApp Call Backup / Overflow Receiver, Phase 2 — alongside that private
 * channel, this also JOINS the tenant's softphone presence channel
 * (`tenant-softphone.{tenantId}`, wire name `presence-tenant-softphone.
 * {tenantId}`) whenever `tenantId` is given. This channel carries no events
 * this tab listens for — its only purpose is establishing membership, which
 * is what App\Services\ReverbPresenceQuery asks Reverb about server-side
 * (see that class's own docblock). The frontend makes no routing decision
 * here and reads nothing back from `here()`/`joining()`/`leaving()` — the
 * join call itself is the entire contribution. Left explicitly on teardown
 * (`echo.leave`), unlike the shared private channel above, since this one is
 * exclusively this softphone instance's own membership, not shared with
 * anything else on the page.
 *
 * Best-effort connection nudge: a connection can go "zombie" — Pusher keeps
 * reporting `state: 'connected'` even though the underlying socket is dead
 * (this happens whenever the server process it was talking to restarts
 * abruptly, without a clean close). Nothing then ever fires a `state_change`,
 * so `onReconnect()` never runs, and Pusher's own ping/pong health check can
 * itself be delayed by the browser throttling a backgrounded tab's timers.
 * Calling `connection.connect()` whenever the tab becomes visible again is a
 * safe no-op if the connection is genuinely healthy, but gives Pusher a
 * prompt, unthrottled trigger to notice and recover a dead one rather than
 * waiting on its own internal timeout. This is a best-effort nudge, not a
 * guarantee — `SoftphoneController`'s own poll is what actually bounds the
 * worst case regardless of what the websocket is doing. The same reconnect
 * also re-establishes the presence channel automatically — Echo/Pusher
 * resubscribe every channel a connection was on once it comes back up, so no
 * extra handling is needed here for that case.
 */
export function subscribeToCalls({ echo, userId, tenantId, onEvent, onReconnect, doc = document }) {
    const channel = echo.private(`tenant-user.${userId}`);
    channel.listen('.call.updated', onEvent);

    const presenceChannelName = tenantId ? `tenant-softphone.${tenantId}` : null;

    if (presenceChannelName) {
        echo.join?.(presenceChannelName);
    }

    const connection = echo.connector?.pusher?.connection;
    let everConnected = connection?.state === 'connected';

    const onStateChange = (states) => {
        if (states?.current !== 'connected') {
            return;
        }

        if (everConnected) {
            onReconnect();
        }

        everConnected = true;
    };

    connection?.bind?.('state_change', onStateChange);

    const onVisibility = () => {
        if (doc.visibilityState === 'visible') {
            connection?.connect?.();
        }
    };

    doc.addEventListener?.('visibilitychange', onVisibility);

    return () => {
        channel.stopListening?.('.call.updated', onEvent);

        if (presenceChannelName) {
            echo.leave?.(presenceChannelName);
        }

        connection?.unbind?.('state_change', onStateChange);
        doc.removeEventListener?.('visibilitychange', onVisibility);
    };
}
