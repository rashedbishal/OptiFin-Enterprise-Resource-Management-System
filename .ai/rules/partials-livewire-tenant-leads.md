---
paths:
  - 'resources/views/livewire/tenant/leads/partials/message-composer-card.blade.php,app/Livewire/Tenant/Leads/Show.php'
---

# Partials Livewire Tenant Leads

## Template-message composer: picker/variable fields are Alpine-driven, not wire:model.live
Fixed 2026-10-11 after a reported "variable fields take time to appear" complaint. The template picker used to be `wire:model.live="selectedTemplateId"`, forcing a full server round-trip just to decide how many variable inputs to draw — unnecessary, since `<x-dropdown-select>` (resources/views/components/dropdown-select.blade.php) already supports a fully client-reactive, zero-round-trip mode via a plain `wire:model` (its own internal `$wire.entangle()`; `.live` only adds the extra server round-trip on top).

Now: the picker is plain `wire:model="selectedTemplateId"`, and the whole templates list (id/name/language/variableCount/bodyText) is embedded via `@js()` into one `x-data` scope wrapping the picker + variable inputs + a live preview box. Field count, labels ("Placeholder {{n}}" instead of "Variable n"), and — for a Messenger template (which stores body_text; WhatsApp templates never do, see MessengerMessageTemplate vs WhatsAppMessageTemplate) — a real-time preview of the message with the typed values substituted in, are all computed client-side with zero network calls.

`Show::updatedSelectedTemplateId()` (the old `.live`-triggered resize hook) is gone — resizing `$templateVariableValues` to match the picked template's `variable_count` is now the Alpine `x-init="$watch('selectedId', ...)"` watcher's job, not testable from Pest (no JS engine). The real safety net is still server-side: `sendTemplateMessage()`'s own `validate(['templateVariableValues' => ['size:'.$template->variable_count]])` rejects any mismatch regardless of why the client sent one — see "submitting a variable-count mismatch is rejected by validation" in LeadTemplateMessageComposerTest.php for the test that now covers this instead of the old resize-hook test.

`@js()` on an array (not a bare string) goes through `Illuminate\Support\Js::from()`'s `JSON.parse('...')` wrapping path, which re-encodes the whole JSON text as a JS string literal — so a value's own `'`-escaped apostrophe gets its backslash escaped too, landing as a literal `\\u0027` (double backslash) in the rendered HTML. This is correct and safe (verified directly against Js.php's source — `JSON_HEX_APOS|JSON_HEX_QUOT` means no literal quote character ever reaches the page, regardless of single- vs double-quoted outer attribute), not a bug — don't "fix" it to single-escaped. See the apostrophe-rendering test in LeadMessengerTemplateMessageComposerTest.php for a worked example of the exact expected escaping.
