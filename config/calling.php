<?php

return [

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Inbound Calling — Step 2 Timings
    |--------------------------------------------------------------------------
    |
    | ring_ttl_seconds: how long an inbound call is considered to be ringing,
    | measured from Meta's own event timestamp (never later than "now"). Meta
    | documents "about 30 to 60 seconds" to accept a call before it ends with
    | "Not Answered", so 60 is the upper end of that window. A `connect`
    | webhook that arrives when this window has already elapsed (a delayed
    | delivery — Meta retries for up to 7 days) is treated as stale.
    |
    | offer_ttl_seconds: how long the (encrypted) SDP offer stays in the cache.
    | Longer than the ring window so a later answer request never races the
    | expiry, short enough that a call's ICE credentials don't linger.
    |
    | tombstone_ttl_seconds: how long a `terminate` that arrived before its
    | `connect` (Meta guarantees no ordering) is remembered, so a delayed
    | `connect` for a call that already ended can't ring or resurrect a lead.
    |
    | seen_ttl_seconds: how long a call id that was durably recorded is
    | remembered AFTER its row may have been deleted with its lead. Meta retries
    | undelivered webhooks "for up to 7 days" (webhooks overview), and a retry
    | can be a duplicate of a `connect` we already handled — if the lead was
    | erased in the meantime, this marker is what stops that redelivery from
    | recreating the lead. It is never the call's identity (that stays the
    | unique (lead_source_id, external_call_id) key) and it is only ever written
    | after a call row has committed.
    |
    */

    'ring_ttl_seconds' => (int) env('CALLING_RING_TTL_SECONDS', 60),

    'offer_ttl_seconds' => (int) env('CALLING_OFFER_TTL_SECONDS', 120),

    'tombstone_ttl_seconds' => (int) env('CALLING_TOMBSTONE_TTL_SECONDS', 600),

    'seen_ttl_seconds' => (int) env('CALLING_SEEN_TTL_SECONDS', 604800),

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Inbound Calling — Step 3 Meta Calling API
    |--------------------------------------------------------------------------
    |
    | graph_version: the Graph API version used ONLY by the calling client
    | (settings + call actions). Deliberately separate from the versions the
    | message senders use, so raising one never moves the other.
    |
    | api_timeout_seconds / api_connect_timeout_seconds: total and connect
    | timeouts for every Calling API request.
    |
    | enable_action_available: the safe-rollout gate. While false (the default
    | everywhere except an environment that sets it) a Platform Admin cannot
    | ENABLE calling for a source — Checking and Disabling stay available. It
    | must stay false in production until answering/ringing exists, otherwise
    | Meta would start routing customers' calls to a number nobody can answer.
    |
    */

    'graph_version' => env('CALLING_GRAPH_VERSION', 'v25.0'),

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Outbound Calling — Meta API Version
    |--------------------------------------------------------------------------
    |
    | Used ONLY by WhatsAppCallingClient::connect() (the outbound `/calls`
    | connect action) — every existing, already-live-validated inbound
    | Calling API action (settings, pre_accept, accept, reject, terminate,
    | and the new checkCallPermission()) keeps using graph_version above,
    | completely unchanged. Kept as a separate key specifically so a future
    | correction to this one number can never risk inbound calling, which
    | has no dependency on it at all.
    |
    */

    'outbound_graph_version' => env('CALLING_OUTBOUND_GRAPH_VERSION', 'v26.0'),

    'api_timeout_seconds' => (int) env('CALLING_API_TIMEOUT_SECONDS', 10),

    'api_connect_timeout_seconds' => (int) env('CALLING_API_CONNECT_TIMEOUT_SECONDS', 3),

    'enable_action_available' => (bool) env('CALLING_ENABLE_ACTION_AVAILABLE', false),

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Inbound Calling — Step 4 Claim / Answer / Accept / Reject
    |--------------------------------------------------------------------------
    |
    | answer_ttl_seconds: how long the claimant's (encrypted) SDP answer stays
    | cached between `pre_accept` and `accept`. Meta requires the accept SDP to
    | equal the pre_accept SDP, so accept reads this cached answer and never a
    | newly submitted one. Longer than the ring window, like the offer's TTL.
    |
    | action_lock_seconds: the short per-call cache lock taken around the Meta
    | request of `answer` / `accept`, so two browser tabs of the same claimant
    | cannot send duplicate Meta operations. It expires by itself, so a crashed
    | request can never wedge a call; it is never a database lock.
    |
    */

    'answer_ttl_seconds' => (int) env('CALLING_ANSWER_TTL_SECONDS', 120),

    'action_lock_seconds' => (int) env('CALLING_ACTION_LOCK_SECONDS', 20),

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Inbound Calling — Step 5 Softphone
    |--------------------------------------------------------------------------
    |
    | active_window_minutes: how long an Answering or Connected call stays in the
    | softphone's "my active calls" read (measured from when it was claimed /
    | connected). A call whose terminate webhook never arrived would otherwise be
    | listed forever; the read never changes a call, so this only bounds what is
    | shown until the later reconciliation step ends such rows properly.
    |
    */

    'active_window_minutes' => (int) env('CALLING_ACTIVE_WINDOW_MINUTES', 120),

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Inbound Calling — Step 6 Stale-Call Reconciliation
    |--------------------------------------------------------------------------
    |
    | stale_grace_seconds: how long past `ringing_expires_at` a Ringing or
    | Answering call is left alone before `calls:reconcile-stale` ends it. Meta's
    | own `terminate` normally arrives within seconds of the ring window and
    | carries the real outcome, so the sweep waits for it; the grace also outlasts
    | any in-flight Meta request of an `answer` / `accept` (action_lock_seconds
    | plus api_timeout_seconds), so the sweep can never race one. A Connected
    | call goes stale after `active_window_minutes` (Step 5) — one number for
    | what the softphone hides and what the server ends.
    |
    | reconcile_batch_limit: the most rows one `calls:reconcile-stale` run will
    | examine. The rest are picked up by the next run (it runs every minute).
    |
    */

    'stale_grace_seconds' => (int) env('CALLING_STALE_GRACE_SECONDS', 120),

    'reconcile_batch_limit' => (int) env('CALLING_RECONCILE_BATCH_LIMIT', 500),

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Outbound Calling — Stale Initiated Reconciliation
    |--------------------------------------------------------------------------
    |
    | outbound_initiate_grace_seconds: how long a row may stay Initiated (no
    | wacid ever applied) before `calls:reconcile-stale` fails it. Distinct
    | from stale_grace_seconds/ringing_expires_at, which an Initiated row
    | never has — InitiateOutboundCall's own connect() POST is synchronous,
    | so under normal operation this state is momentary; this bounds the
    | abnormal case (the process died mid-request, or a transient failure
    | left the row unresolved) rather than leaving it stuck forever.
    |
    */

    'outbound_initiate_grace_seconds' => (int) env('CALLING_OUTBOUND_INITIATE_GRACE_SECONDS', 120),

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Inbound Calling — Softphone Realtime Fallback (Adaptive)
    |--------------------------------------------------------------------------
    |
    | The browser polls its active calls at one of two speeds, never both at
    | once — never inferred from the websocket's own reported connection
    | state, which can lie (it can report itself as still "connected" even
    | after the underlying connection has died, e.g. an abrupt server
    | restart the browser's socket never noticed).
    |
    | softphone_heartbeat_seconds: the HEALTHY-mode interval. A plain HTTP
    | check, entirely independent of the websocket — it alone bounds how
    | late a brand-new call can be discovered even with a fully dead
    | realtime connection, since it does not depend on realtime having
    | delivered anything. This is what makes a call arriving with zero
    | prior active calls (nothing for realtime to ever have nudged)
    | discoverable at all, and is the number that actually matters against
    | ring_ttl_seconds (60): 15s leaves 45s of the ring window for an agent
    | to notice and answer even in the worst case.
    |
    | softphone_staleness_seconds: how long the app tolerates zero
    | successful refresh (poll- or realtime-event-triggered — an event's
    | mere arrival is never itself "success") before escalating from the
    | heartbeat to the faster degraded-mode poll. A broader "even the HTTP
    | path is unreliable" condition than "just the websocket is unhealthy",
    | since the heartbeat above is unaffected by the websocket's health on
    | its own.
    |
    | softphone_poll_seconds: the DEGRADED-mode interval — the fast retry
    | rate used only once softphone_staleness_seconds has elapsed with no
    | successful refresh. Any single successful refresh while degraded
    | returns the browser to the heartbeat rate immediately. Kept short
    | because a ring only lasts ring_ttl_seconds (60) — a slow fallback here
    | is the difference between "the phone rang and nobody could answer in
    | time" and "briefly late but still answerable." This was the app's
    | ONLY polling rate before the adaptive heartbeat/staleness split above
    | was introduced, so it remains the worst-case ceiling: degraded mode
    | never polls slower than the app already did before this existed.
    |
    */

    'softphone_heartbeat_seconds' => (int) env('CALLING_SOFTPHONE_HEARTBEAT_SECONDS', 15),

    'softphone_staleness_seconds' => (int) env('CALLING_SOFTPHONE_STALENESS_SECONDS', 20),

    'softphone_poll_seconds' => (int) env('CALLING_SOFTPHONE_POLL_SECONDS', 5),

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Outbound Calling — Call Permission
    |--------------------------------------------------------------------------
    |
    | A business-initiated call requires the consumer's explicit call
    | permission (Meta's verified `GET .../call_permissions` /
    | `call_permission_reply` webhook contract — see
    | App\Actions\Tenant\{CheckLeadCallPermission,RequestLeadCallPermission,
    | RecordCallPermissionReply}). Rate limits are never read from config —
    | they come live from Meta's own actions[].limits[] on every check.
    |
    | call_permission_request_template: the approved WhatsApp Business
    | template (Meta Business Manager, per WABA) used to ask a consumer for
    | call permission. name/language and the body's two variables (lead
    | name, lead inquiry) are the confirmed, approved contract; the
    | `call_permission_request` component itself remains
    | `{"type": "call_permission_request"}` with no further fields, since
    | Meta's public documentation does not confirm any more than that shape
    | — see RequestLeadCallPermission. The template was In Review, not yet
    | Active, when this was built: real Meta test-number validation is still
    | pending and not claimed here.
    |
    */

    'call_permission_request_template' => [
        'name' => env('CALLING_PERMISSION_REQUEST_TEMPLATE_NAME', 'call_permission_request'),
        'language' => env('CALLING_PERMISSION_REQUEST_TEMPLATE_LANGUAGE', 'en'),
    ],

];
