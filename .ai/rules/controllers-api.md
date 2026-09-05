---
paths:
  - app/Http/Controllers/Api/LeadWebhookController.php
---

# Controllers Api

## Webhook dedup checks are lock-protected; Messenger/Instagram always return 200
Every "does a lead already exist for this identifier" check (Messenger/Instagram by PSID, WhatsApp by phone, Facebook Lead Ads by leadgen_id) is now wrapped in withDedupeLock($sourceId, $identifier, $callback, $lockSeconds, $blockSeconds) — a Cache::lock() around the whole check+create, closing a real race where two near-simultaneous Meta redeliveries could both pass the existence check before either finished creating the lead. The Facebook Lead Ads path passes a longer lockSeconds/blockSeconds (30/15) than the default (10/5) since it chains two 10s-timeout Graph API calls before creating anything — the default would risk the lock expiring mid-processing. WhatsApp's identifier is senderPhone specifically, and locking is skipped entirely when it's null/empty (nothing to dedupe against, matches pre-existing behavior for that edge case).

facebookMessenger()/instagram() now wrap their whole body in try/catch and always return 200, mirroring whatsapp()'s existing reasoning (Meta disables a subscription after enough non-200s) — this also protects against a lock-acquisition timeout under contention surfacing as an uncaught 500.
