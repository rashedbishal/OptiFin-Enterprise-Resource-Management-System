---
paths:
  - 'resources/views/livewire/tenant/leads/partials/{messenger-chat-card,outbound-activity-log-card}.blade.php,resources/views/livewire/tenant/leads/show.blade.php,app/Livewire/Tenant/Leads/Show.php'
---

# Tenant Leads Livewire Tenant Leads

## Full-page Overview tab: Messenger-style chat card + separate Activity Log card
Shipped 2026-10-11, full-page (show.blade.php, Overview tab) only — show-panel.blade.php's Kanban flyout is untouched and still uses detail-card.blade.php/message-composer-card.blade.php exactly as before (both files are UNCHANGED, still shared with the flyout).

For a conversational lead ($this->messagingChannel present), the old side-by-side "Messenger Reply" + "Conversation" cards are replaced by:
- `messenger-chat-card.blade.php` — one card: header, conversation thread, composer (text/image/PDF or template-send when the window's closed) all merged, fixed-bottom-composer Messenger feel. Content is copied verbatim from detail-card.blade.php's premium branch + message-composer-card.blade.php — including the `$lead->message || $lead->html_body || $lead->attachments->isNotEmpty()` fallback branch (catches a case $hasConversation's own `Lead::messageTimeline()` check doesn't, e.g. an attachment with no recognized text message — a real regression caught by LeadAttachmentDisplayTest, not a hypothetical).
- `outbound-activity-log-card.blade.php` — the Sent/Failed/Resend log that used to live at the bottom of message-composer-card.blade.php, now its own card, with a new per-row "hide from this list" (X) button, Client-Admin-only (`$this->canDelete()`).

Non-conversational leads (Email/Website Form/FB Lead Ads/Manual) keep the original pair exactly as-is in show.blade.php's `@else` branch.

**New Message.activity_log_hidden_at**: hides one row from `Show::outboundMessages()` ONLY — the message itself, and its bubble in the merged chat card's conversation thread, are never touched (not a delete; this app never soft-deletes). Set via `Show::hideOutboundMessage()`'s own `DB::table('messages')->update(...)`, deliberately NOT in Message's `#[Fillable(...)]` list — same "only one code path may ever set this" pattern as `resend_message_id`/`merged_into_lead_id`. A plain `$message->update([...])` or `->forceFill()` without `save()` silently no-ops on this column; this bit both the action's first draft AND a test's setup in the same session — use `forceFill(...)->save()` or a raw DB update in tests too.
