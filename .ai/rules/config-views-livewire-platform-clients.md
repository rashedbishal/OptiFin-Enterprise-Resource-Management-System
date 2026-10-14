---
paths:
  - 'config/calling.php,resources/views/livewire/platform/clients/lead-sources.blade.php'
---

# Config Views Livewire Platform Clients

## Call permission template body/footer must be fully STATIC — no {{n}} placeholder at all
REVERSED 2026-10-14, same day the previous rule here (body must have exactly {{1}}/{{2}}, inserted via buttons) was shipped and then disproven by a real, live Meta content review: a personalized body ({{1}} lead name, {{2}} lead inquiry) was REJECTED outright, while a byte-for-byte-identical-except-static version was APPROVED on its very first submission. Meta's review for this specific, specialized `call_permission_request` component type appears to reject a personalized body, even though the Cloud API's generic template-parameter mechanism would otherwise allow one — confirmed with two real, live data points (0-variable APPROVED; 2-variable REJECTED), not a guess.

config('calling.call_permission_request_template')['body']/['footer'] are back to plain static text (no {{1}}/{{2}}). `App\Livewire\Platform\Clients\LeadSources::saveCallPermissionTemplate()` now validates the OPPOSITE of before — any `{{\d+}}` in the submitted body is a validation error, not a requirement. `App\Actions\Tenant\RequestLeadCallPermission::sendTemplate()` sends only `[['type' => 'call_permission_request']]` as the template's `components` — no `body` component at all — so any {{n}} that slipped into an approved template would be permanently unfillable anyway.

The Create form's old "Insert Lead's Name" / "Insert Lead's Inquiry" buttons (and their ✓/missing placeholder indicators) have been removed entirely — there is nothing to insert any more. The body field is now a plain `<flux:textarea>` with no Alpine `x-data` block.

`App\Actions\Platform\CreateCallPermissionRequestTemplate::handle()` also now explicitly checks for `$response->json('status') === 'REJECTED'` in an otherwise-2xx response — Meta can reject a template's content SYNCHRONOUSLY, in the very same create response, not only asynchronously hours later. A REJECTED create is treated as a failure (reports Meta's `rejected_reason` via a best-effort follow-up GET, cleans up the rejected object via a best-effort DELETE, and bumps this source's own stored template name the same way a real delete does) — reporting a REJECTED status as "success" was the original bug caught here.
