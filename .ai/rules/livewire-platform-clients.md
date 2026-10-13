---
paths:
  - app/Livewire/Platform/Clients/LeadSources.php
---

# Livewire Platform Clients

## Facebook/Lead Ads test-connection: page-name lookup is cosmetic, never blocking
Fixed 2026-10-13 after a real production failure: testFacebookConnection()/testFacebookLeadAdsConnection() used to call `GET /{page-id}?fields=id,name` FIRST and abort on any failure before ever reaching subscribeFacebookPageToApp(). That name lookup needs `pages_read_engagement` (or the "Page Public Content Access"/"Page Public Metadata Access" feature) — a separate, unrelated permission from what the actual subscribe call needs (`pages_messaging` for Messenger, `pages_manage_metadata` for Lead Ads). A Page Access Token can be entirely valid for real messaging/lead-ads use and still lack that one read scope, producing Meta's exact (#100) error — and because the old code aborted first, the Page never got subscribed to receive webhook events at all, even though the token worked.

Fix: extracted `fetchFacebookPageName()` as a best-effort helper (returns null on any failure, never throws/aborts) — both test methods now call it, fall back to the raw Page ID as the display name on failure, and always proceed to the real subscribe call regardless. See the two regression tests in tests/Feature/Leads/LeadSourceConnectionTest.php that fake the name lookup failing with this exact Meta error message while the subscribe call succeeds, asserting a success toast.

If a future Graph API call in this file follows the same "fetch something cosmetic, then do something that actually matters" shape, apply the same split — never let an optional display-only lookup gate a functionally required call.
