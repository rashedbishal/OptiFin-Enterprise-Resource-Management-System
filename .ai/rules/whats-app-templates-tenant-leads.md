---
paths:
  - 'app/{Models/WhatsAppMessageTemplate.php,Services/{OutboundEligibilityService,WhatsAppSender}.php,Livewire/Tenant/WhatsAppTemplates/**,Livewire/Tenant/Leads/Show.php}'
---

# Whats App Templates Tenant Leads

## WhatsApp Message Templates — scoped to sending only, never Meta template management
WhatsAppMessageTemplate is a tenant self-registered catalog (name/language/variable_count) of templates the Client Admin already created and got approved manually in Meta's own WhatsApp Manager. This app deliberately never creates/edits/lists templates via Meta's Message Templates API (`/{waba_id}/message_templates`) — that needs the separate `whatsapp_business_management` OAuth scope, which stored WhatsApp tokens don't carry. Sending an already-approved template (`type: template` to `/messages`) only needs `whatsapp_business_messaging`, the same scope every other outbound send already uses — confirmed against Meta's own docs before building this, so don't re-litigate the scope question.

OutboundEligibilityService::evaluateForTemplate() is the same checks as evaluate() minus the messaging-window check — used only to gate a template send, which exists specifically to work once that window is closed. A Message row's template_name/template_language/template_variables columns are a frozen snapshot taken at send time, never a live FK back to WhatsAppMessageTemplate (which a tenant can rename/deactivate later) — don't add a template_id FK. Body-text variables only, no header media/buttons, WhatsApp-only (Messenger/InstagramSender::sendTemplate() just return SendResult::failure()).
