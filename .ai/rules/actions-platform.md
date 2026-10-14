---
paths:
  - 'app/Actions/Platform/{CreateCallPermissionRequestTemplate,DeleteCallPermissionRequestTemplate}.php,config/calling.php'
---

# Actions Platform

## Deleting a WhatsApp template locks its (name, language) for ~30 days — not immediately reusable
Confirmed live, 2026-10-14, directly contradicting what DeleteCallPermissionRequestTemplate's own success message used to claim: deleting a WhatsApp Business template does NOT free up its exact (name, language) pair for a new submission. Meta's real error on the next Create attempt: "New English content can't be added while the existing English content is being deleted. Try again in 4 weeks or consider creating a new message template." This is a WhatsApp-specific behavior — it does NOT apply to Messenger Utility templates (DeleteMessengerMessageTemplate's own "you can create a replacement right after" claim is correct for that different Meta product).

Fix in the moment: bumped config('calling.call_permission_request_template')['name'] from `call_permission_request` to `call_permission_request_v2` (env CALLING_PERMISSION_REQUEST_TEMPLATE_NAME) — the old name is locked until roughly mid-November 2026. DeleteCallPermissionRequestTemplate's success message and the Delete confirm modal's own warning text both now correctly say this instead of promising an immediate replacement. If this needs deleting again later, bump the suffix again (_v3, ...) rather than waiting out the lock.
