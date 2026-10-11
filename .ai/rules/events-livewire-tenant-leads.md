---
paths:
  - 'app/Events/{LeadReceived,LeadUpdated}.php,app/Livewire/Tenant/Leads/Index.php'
---

# Events Livewire Tenant Leads

## Lead broadcast events use ShouldBroadcastNow, not ShouldBroadcast
LeadReceived/LeadUpdated both implement ShouldBroadcastNow (not ShouldBroadcast) — fixed 2026-10-11 after a real reported delay: ShouldBroadcast queues the broadcast job, and production's queue worker runs `queue:work --sleep=3` (confirmed via Supervisor on the VPS), so a new/updated lead's live board refresh could lag up to ~3s+ (worse under any queue backlog) before this fix. ShouldBroadcastNow dispatches synchronously instead, with no queue dependency for the broadcast itself.

This only affects how THESE events' own broadcast fires — LeadReceived's other listeners (AutoAssignLead, SendNewLeadNotification, RecomputeLeadScoreOnReceipt) each implement ShouldQueue independently and stay queued regardless. Don't revert to ShouldBroadcast without addressing the underlying queue-worker sleep interval first, or the same delay comes back.

Also fixed in the same pass: `Index::onLeadReceived()` was checking `$payload['lead']['id']` but `LeadReceived::broadcastWith()` sends a flat `{id: ...}` (same shape as LeadUpdated's) — the mismatch meant the "recently created" shimmer highlight silently never fired for a real live-arrived lead (the board still refreshed correctly either way, since that happens unconditionally; only the highlight was missing). If you ever change `broadcastWith()`'s payload shape, keep `onLeadReceived()` in sync — `tests/Feature/Broadcasting/LeadsIndexListenerTest.php` asserts the real flat shape now, not the old broken nested one.
