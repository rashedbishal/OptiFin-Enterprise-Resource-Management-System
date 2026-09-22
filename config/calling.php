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
    | WhatsApp Inbound Calling — Softphone Realtime Fallback
    |--------------------------------------------------------------------------
    |
    | softphone_poll_seconds: how often the browser re-fetches its active
    | calls as a fallback when realtime (Reverb/websocket) doesn't deliver an
    | event — a dropped connection, a missed broadcast, or a server restart
    | the browser's socket didn't notice (it can report itself as still
    | "connected" even after the underlying connection has died). Realtime is
    | the primary, near-instant path; this only bounds the worst case. Kept
    | short because a ring only lasts ring_ttl_seconds (60) — a slow fallback
    | here is the difference between "the phone rang and nobody could answer
    | in time" and "briefly late but still answerable."
    |
    */

    'softphone_poll_seconds' => (int) env('CALLING_SOFTPHONE_POLL_SECONDS', 5),

];
