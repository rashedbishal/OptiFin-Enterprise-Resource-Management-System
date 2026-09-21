/**
 * WhatsApp inbound calling — the realtime side of the softphone: one listener on
 * the user's OWN private channel for `.call.updated`, and a hook for "Reverb
 * reconnected". The event is only a nudge — the payload (call id + status) is
 * never trusted as state; the caller refetches the active calls instead — so a
 * duplicate, delayed or out-of-order event is harmless.
 *
 * The channel is shared with the notification bell (same Echo connection), so
 * this only stops ITS listener on teardown and never leaves the channel.
 */
export function subscribeToCalls({ echo, userId, onEvent, onReconnect }) {
    const channel = echo.private(`tenant-user.${userId}`);
    channel.listen('.call.updated', onEvent);

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

    return () => {
        channel.stopListening?.('.call.updated', onEvent);
        connection?.unbind?.('state_change', onStateChange);
    };
}
