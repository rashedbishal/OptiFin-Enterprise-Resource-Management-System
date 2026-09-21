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

];
