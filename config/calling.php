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
    | WhatsApp Call Backup / Overflow Receiver — Assignee Presence
    |--------------------------------------------------------------------------
    |
    | softphone_presence_stale_seconds: how long tenant_users.
    | softphone_last_seen_at may go untouched before App\Services\
    | SoftphonePresence considers that user unreachable, which
    | App\Services\CallRouter then treats as ineligible for routing_mode=
    | Assignee (falling through to BackupFallback instead — see its own
    | docblock). The timestamp is refreshed by the softphone's own existing
    | GET calls/active poll, so its cadence is what this has to tolerate:
    | softphone_heartbeat_seconds (15) in the normal/healthy state, as fast
    | as softphone_poll_seconds (5) once degraded — 3x the healthy
    | heartbeat (45) forgives up to two consecutive missed cycles (a
    | backgrounded tab being throttled, one slow request, a brief network
    | blip) without misreading a present agent as gone, while still leaving
    | most of ring_ttl_seconds's 60-second window for a backup receiver to
    | notice and answer once the assignee genuinely has gone stale.
    |
    */

    'softphone_presence_stale_seconds' => (int) env('CALLING_SOFTPHONE_PRESENCE_STALE_SECONDS', 45),

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Call Backup / Overflow Receiver, Phase 2 — Reverb Presence
    |--------------------------------------------------------------------------
    |
    | reverb_presence_enabled: the rollout gate for App\Services\
    | ReverbPresenceQuery. Defaults FALSE deliberately — enabling it is a
    | two-sided deploy (a `presence-tenant-softphone.{tenantId}` channel the
    | softphone frontend must actually be joining) and turning this on
    | before the frontend ships would make CallRouter ask Reverb about a
    | channel nobody has joined yet, read every assignee as Absent, and
    | silently overflow every assigned call to backups. While false,
    | App\Services\SoftphonePresence never calls Reverb at all — every
    | routing decision uses the pre-existing softphone_last_seen_at
    | heartbeat unchanged, exactly as before this phase shipped. Flip this
    | to true only once the frontend join is confirmed live in that
    | environment.
    |
    | reverb_presence_timeout_seconds / reverb_presence_connect_timeout_
    | seconds: this query runs synchronously inside CallRouter, on the
    | inbound WhatsApp webhook's hot path — the one path in this whole
    | calling module that previously made zero synchronous external calls
    | (RecordInboundCall never touches Meta's Graph API; only the agent-
    | triggered claim/answer/accept/reject actions do, at the much more
    | generous api_timeout_seconds/api_connect_timeout_seconds above, which
    | are sized for a real internet round-trip to Meta). Reverb is this
    | app's own server, typically same-host or same-network, so a healthy
    | response is milliseconds, not seconds — these are kept far tighter
    | than the Meta API timeouts on purpose, so a Reverb hiccup can add at
    | most ~1.5s to webhook processing before ReverbPresenceQuery reports
    | QueryFailed and SoftphonePresence falls back to the heartbeat check.
    | Never raise these to "wait out" a slow Reverb — that defeats the
    | point of having a fallback at all.
    |
    */

    'reverb_presence_enabled' => (bool) env('CALLING_REVERB_PRESENCE_ENABLED', false),

    'reverb_presence_timeout_seconds' => (float) env('CALLING_REVERB_PRESENCE_TIMEOUT_SECONDS', 1.5),

    'reverb_presence_connect_timeout_seconds' => (float) env('CALLING_REVERB_PRESENCE_CONNECT_TIMEOUT_SECONDS', 0.5),

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
    | body/footer are only the PRE-FILLED DEFAULTS Platform Admin's "Create
    | Call Permission Template" form (Lead Sources page, WhatsApp source —
    | see Platform\Clients\LeadSources::confirmCreateCallPermissionTemplate()/
    | saveCallPermissionTemplate()) starts from — the admin can edit both
    | before submitting, since Meta doesn't care what the wording says
    | (unlike name/language above, which MUST stay fixed — see
    | App\Actions\Platform\CreateCallPermissionRequestTemplate's own
    | docblock for why). Added after a real production gap: this template
    | is per-WABA, so every tenant needs their own copy actually created
    | and approved on Meta's side before RequestLeadCallPermission can
    | ever succeed for them — nothing creates it automatically. Footer has
    | Meta's own hard 60-character limit (confirmed live: a longer footer
    | is rejected outright, not truncated) — kept as the submitted default
    | specifically because it already fits.
    |
    | The default `body` MUST keep exactly one {{1}} and one {{2}} —
    | RequestLeadCallPermission::sendTemplate() always sends exactly those
    | two text parameters (lead name, lead inquiry) when the approved
    | template is actually used, REGARDLESS of what its own body text
    | says. A body approved with a different variable count (confirmed
    | live, 2026-10-14: a body with zero {{n}} placeholders got through
    | Meta's own, much looser review and was APPROVED) silently fails the
    | first time it's actually sent — Meta rejects a parameter-count
    | mismatch at send time, not at template-approval time. The Livewire
    | form's own validation (Platform\Clients\LeadSources::
    | saveCallPermissionTemplate()) enforces this for anything the admin
    | submits; this default must satisfy the same rule for the same
    | reason, since it's what a brand-new form starts from.
    |
    | `name` was bumped to _v2 after a real Meta platform behavior
    | discovered live, 2026-10-14: deleting a WhatsApp template does NOT
    | free up its (name, language) for immediate reuse — Meta locks that
    | exact pair for roughly 30 days ("New English content can't be added
    | while the existing English content is being deleted. Try again in
    | 4 weeks or consider creating a new message template."). The
    | original `call_permission_request`/en was deleted (after an earlier
    | version of this template was mistakenly approved with zero {{n}}
    | placeholders — see the body/footer comment above) and is now
    | locked until roughly mid-November 2026 — don't reuse that name
    | before then. If you ever need to delete-and-replace again, bump
    | this suffix (_v3, ...) rather than waiting out the lock — see
    | DeleteCallPermissionRequestTemplate's own docblock, which now warns
    | about this instead of claiming an immediate replacement is possible.
    |
    */

    'call_permission_request_template' => [
        'name' => env('CALLING_PERMISSION_REQUEST_TEMPLATE_NAME', 'call_permission_request_v2'),
        'language' => env('CALLING_PERMISSION_REQUEST_TEMPLATE_LANGUAGE', 'en'),
        'body' => env('CALLING_PERMISSION_REQUEST_TEMPLATE_BODY', 'Hi {{1}}, we would like to call you to discuss your inquiry about {{2}}. Do you give us permission to call you on WhatsApp?'),
        'footer' => env('CALLING_PERMISSION_REQUEST_TEMPLATE_FOOTER', 'You can change call permissions anytime in your profile.'),
    ],

];
