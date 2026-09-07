---
paths:
  - 'app/Http/Controllers/Api/LeadWebhookController.php,app/Enums/LeadSourceType.php,app/Livewire/Platform/Clients/LeadSources.php,resources/views/livewire/platform/clients/lead-sources.blade.php'
---

# Platform Clients

## Website Form: api_key display and allowed_domains enforcement
Website Form lead capture is push-based: the client's own site (server-side handler, ideally — see below) POSTs to POST /api/webhook/website-form/{tenant_code} with an X-Api-Key header. The api_key is auto-generated (UUID) in LeadSources::save() when a WebsiteForm source is first created — it isn't a configFields() entry (admin never types it), so it wasn't shown anywhere in the UI until now.

api_key display: lead-sources.blade.php's Webhook URL column now shows a readonly `<flux:input copyable>` with the api_key beneath the URL, only for LeadSourceType::WebsiteForm rows. `copyable` is Flux's own built-in <flux:input> prop (backed by resources/views/flux/input/copyable.blade.php, already published in this app) — no bespoke clipboard JS needed, unlike the hand-rolled implementation in settings/security.blade.php for the 2FA manual setup key.

allowed_domains enforcement: LeadWebhookController::assertAllowedWebsiteFormDomain() checks the request's Origin (preferred — browser-set, JS can't override it) or Referer header's host against config['allowed_domains'], opt-in exactly like VerifyMetaWebhookSignature's app_secret check — an empty/unset list (the common case for a pure server-to-server integration, which never sends either header) skips the check entirely and only the API key gates the request. Once at least one domain is configured, a request with neither header, or a non-matching host, gets a 403. A configured domain matches as a parent of the request host too (e.g. "example.com" matches "www.example.com"). Rejections reuse LeadSource's existing last_signature_rejection_at/_reason columns (not new ones) — LeadSources::flashSignatureRejections() toasts whatever reason string is stored regardless of which check produced it, so the admin sees a domain rejection the same way they'd see a Meta signature mismatch, with zero changes to that flashing logic.
