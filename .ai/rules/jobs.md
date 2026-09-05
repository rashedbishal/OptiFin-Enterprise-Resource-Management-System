---
paths:
  - app/Jobs/ProcessEmailLeadSource.php
---

# Jobs

## Email leads now thread by sender, isolate per-message failures, and can't overlap
Three behavior changes, all deliberate: (1) createLeadFromEmail() now looks up an existing lead by (source_id, email) first and appends via appendEmailMessage() instead of always creating a new Lead — mirrors LeadWebhookController::appendFacebookMessage(), except html_body is simply replaced with the newest email's content (no per-message timeline exists for email the way Lead::messageTimeline() covers Messenger/WhatsApp). Skipped when the sender address couldn't be parsed at all. (2) handle()'s per-message loop now wraps createLeadFromEmail() in try/catch — a message is still flagged \Seen even on failure (logged, lead lost) specifically so one malformed email can't permanently wedge the whole mailbox (it would otherwise never advance last_synced_at, making FetchEmailLeads::isDue() re-dispatch the same failing message forever). (3) The job now declares middleware() returning WithoutOverlapping($this->source->id) (releaseAfter 30s, expireAfter = $timeout) plus $tries = 3 (bumped from the framework default of 1) specifically to tolerate a couple of overlap-releases without the job being marked permanently failed.
