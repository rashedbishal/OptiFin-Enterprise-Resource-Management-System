---
paths:
  - 'app/{Actions/Platform/CreateCallPermissionRequestTemplate.php,Livewire/Platform/Clients/LeadSources.php},resources/views/livewire/platform/clients/lead-sources.blade.php'
---

# Views Livewire Platform Clients

## Call permission template body/footer are now admin-editable, name/language stay fixed
Changed 2026-10-14, right after the Create/Delete pair shipped. "Create Call Permission Template" is now a form, not a one-click button — confirmCreateCallPermissionTemplate() opens it pre-filled from config('calling.call_permission_request_template')'s body/footer defaults (name/language shown read-only, not editable); saveCallPermissionTemplate() validates before calling the Action, which now takes $bodyText/$footerText as plain string params instead of reading config directly.

The one load-bearing validation rule: the body must contain EXACTLY one {{1}} and one {{2}} placeholder, nothing more/fewer (substr_count check + a preg_match_all diff against ['1','2']) — RequestLeadCallPermission::sendTemplate() always sends exactly those two parameters (lead name, lead inquiry) when the template is actually used later, so a body approved with a different variable shape would pass Meta's (much looser) review and then silently fail every real send afterward. This check happens in the Livewire component, not the Action — same separation as every other Platform Action in this app (Gate/shape checks in the Action, field-content validation in the component's own validate()). Footer keeps the existing hard 60-char Meta limit.

Blade trap hit again building this form: a literal {{1}}/{{2}} anywhere in this file's raw source — even inside a quoted PHP string passed to a `:description="..."` attribute — gets misparsed as a Blade echo by the compiler, which doesn't understand PHP string boundaries. Fixed the same way as this file's existing openBraces/closeBraces JS workaround: build the display string in a @php block from non-adjacent concatenated pieces ($openBrace.$openBrace.'1'.$closeBrace.$closeBrace) and reference the resulting variable, never write {{1}} literally outside a {{-- --}} comment.
