---
paths:
  - 'app/Actions/{Platform/{CreateCallPermissionRequestTemplate,DeleteCallPermissionRequestTemplate}.php,Tenant/RequestLeadCallPermission.php}'
---

# App Actions Platform

## Call permission template name is per-source, auto-bumps on delete
Shipped 2026-10-14, right after the ~30-day Meta delete-lock discovery — the user asked "every time I delete, create with a new name automatically." A naive GLOBAL auto-bump (changing config('calling.call_permission_request_template')['name'] itself) would have broken every OTHER tenant whose own template is still correctly approved under the old name on THEIR OWN WABA, since Meta's delete-lock and template identity are scoped per-WABA, not global.

Fix: the name now lives per-source, inside LeadSource.config[CreateCallPermissionRequestTemplate::TEMPLATE_NAME_CONFIG_KEY] ('call_permission_template_name') — same established pattern as WebsiteForm's auto-generated api_key (system-managed state, never a configFields() entry). CreateCallPermissionRequestTemplate::resolveTemplateName(LeadSource) is the single shared resolver (falls back to the global config default when a source has never had one stored) — DeleteCallPermissionRequestTemplate and RequestLeadCallPermission::sendTemplate() both call it too, so creation/deletion/actual-sending always agree on the same name for a given source.

CreateCallPermissionRequestTemplate::nextTemplateName() (moved here from DeleteCallPermissionRequestTemplate 2026-10-14 so both share one implementation) bumps the trailing _vN suffix (or appends _v2 if absent) and persists it onto JUST that source's own config — on a successful delete (DeleteCallPermissionRequestTemplate), AND on a synchronously-REJECTED create (CreateCallPermissionRequestTemplate's own cleanup path, since its best-effort delete of the rejected object re-triggers the same ~30-day name lock). The next Create click for that source picks up the bumped name automatically, no admin action needed, and no other source's stored name is ever touched. The Lead Sources page shows "Current name: X" next to the buttons so the admin can see it without opening the modal.

## Call permission template body/footer are static-only — see .ai/rules/config-views-livewire-platform-clients.md
REVERSED 2026-10-14: this template's body/footer must contain NO {{n}} placeholder at all (not "exactly {{1}}/{{2}}" as an earlier same-day rule here said) — a real Meta content review REJECTED a personalized version while an identical-but-static one was APPROVED. RequestLeadCallPermission::sendTemplate() sends no `body` component when using the template. See the other rule file for the full reasoning; this note exists so a reader of this file doesn't miss it.
