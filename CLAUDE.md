# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

A multi-tenant SaaS ERP built on the Laravel Livewire starter kit (Laravel 13, PHP 8.3, Livewire 4, Flux UI 2, Tailwind v4). It has a Platform side (`/admin/*`, run by the vendor) and a Tenant side (`/*`, run by each client), plus a Lead Management module ported from a sibling project.

**Before editing or planning anything under `app/`, `resources/views/livewire/`, read `.ai/rules/index.md` and every rule file whose glob matches the path in scope, and grep `.ai/rules` for related keywords.** These are settled decisions and traps, not optional background — see summaries below but treat the files themselves as authoritative.

## Commands

```bash
composer run dev          # artisan serve + queue + pail + vite, all at once (primary local dev loop)
npm run dev                # vite only
npm run build
npm test                   # vitest run — browser-side tests (currently the WhatsApp softphone logic, jsdom + mocked browser APIs)

vendor/bin/pint --dirty --format agent   # format PHP after any change (run before finishing, per Boost rules)
phpstan analyse                          # composer run types:check
php artisan test --compact               # full suite
php artisan test --compact --filter=testName
vendor/bin/pest path/to/FileTest.php     # single file
composer run test                        # config:clear + lint:check + types:check + full test suite (CI gate)

php artisan route:list
php artisan admin:patch-mother-admin-credentials  # bootstrap the single Platform mother_admin, or update its name/email/password if it already exists (nothing else creates/patches one; the account itself can never be deleted — see PlatformUserObserver)
php artisan schema:verify-enum-constraints  # manual, Postgres-only: live CHECK constraints vs PHP enum cases (not in CI)
php artisan leads:fetch-emails           # poll IMAP lead sources, create Lead + ProcessEmailLeadSource job
php artisan leads:retry-failed-emails    # retry durably-recorded failed email lead-processing messages that are due
php artisan leads:cleanup-attachments    # delete orphaned lead attachment files
php artisan leads:send-alert-notifications  # daily sweep: bell notifications for active meeting/follow-up alerts (scheduled 08:00)
php artisan tasks:send-alert-notifications  # daily sweep: bell notifications for active Task due-date alerts (scheduled 08:00)
php artisan leads:nurture-inactive-leads # daily sweep: notify + optionally create a follow-up Task for leads inactive past a tenant threshold (scheduled 08:00)
php artisan leads:detect-duplicates      # backfill sweep: catch cross-channel duplicate pairs nobody has opened yet
php artisan leads:recalculate-scores     # daily sweep: recompute every lead's score for time-derived scoring rules
php artisan meta:subscribe-leadgen {leadSourceId?}  # subscribe a Facebook Page to the leadgen webhook field (omit id to subscribe every active source)
php artisan leads:fetch-test {leadgen_id} --source={leadSourceId}  # manually fetch one Facebook Lead Ad by id, no real webhook needed
php artisan calls:reconcile-stale        # every-minute sweep: end WhatsApp calls whose terminate webhook never arrived (see WhatsApp Calling below)
```

**Test vs. dev database differ:** `phpunit.xml` runs the suite on in-memory SQLite (`QUEUE_CONNECTION=sync`, `BROADCAST_CONNECTION=null`), while `.env.example` targets PostgreSQL + `database` queue + Reverb. CI (`.github/workflows/tests.yml`: `composer setup` → `composer ci:check`) is SQLite-only, so Postgres-specific behavior (partial unique indexes, enum `CHECK` constraints) is not exercised by tests — verify it by hand, e.g. with `schema:verify-enum-constraints` after changing an enum-backed column such as `LeadSourceType`. Queue workers must be running locally for IMAP polling, notifications and CSV imports (`composer run dev` starts one).

`phpstan analyse` runs at `level: 7` against `phpstan-baseline.neon` (~436 pre-existing errors, e.g. `Auth::user()` being typed `PlatformUser|TenantUser|null` throughout) — `types:check` only fails on errors *not* already in that baseline, so don't chase baseline-covered noise in unrelated code, but never let a new diagnostic slip in uncaught. Regenerate the baseline deliberately, not to silence a real new error.

**Deploy pipeline:** a `deploy` job in `.github/workflows/tests.yml` runs after `ci` passes on every push to `main`, SSHing into the production VPS (deploy key restricted server-side to only run `deploy.sh`) to build the new release in an isolated `releases/<timestamp>/` directory and atomically swap the `current` symlink — only after dependencies, build, migrations and caches all succeed, with auto-rollback to the previous release if the post-deploy `/up` health check fails. `deploy.sh` itself lives on the VPS, not in this repo.

`README.md` covers only a runbook for testing Facebook Lead Ads (Instant Forms) ingestion — no general project docs there.

## Architecture

### Dual-guard auth — two people tables, never a shared `User`
- `App\Models\PlatformUser` (table `platform_users`, guard `platform`, routes under `/admin`, login `admin/login`) — exactly one `mother_admin` (enforced by a DB partial unique index, bypasses all authorization via `Gate::before`) plus Platform Admins.
- `App\Models\TenantUser` (table `tenant_users`, guard `web`, default Fortify `/login`) — Client Admins/Users, always scoped to `tenant_id`.
- Both share one Role → Module → Permission engine (`roles`/`modules`/`permissions`/`role_modules`/`role_permissions`/`tenant_modules` — see `App\Services\RoleService` and `App\Concerns\HasModulePermissions`). `modules.scope` (platform|tenant) gates which catalog a role can draw from.
- All IDs are ULIDs. Extend `PlatformUser`/`TenantUser` and their existing policies/observers — don't reintroduce a generic `User` model.
- `PlatformUser`/`TenantUser` use a `#[Fillable([...])]` PHP attribute instead of `protected $fillable`. Adding a mutable column requires adding it there too, or `create()`/`update()` silently drops it with no error.

### Route structure
- `routes/web.php` — tenant area, behind `auth`, `verified`, `tenant.identify`, `tenant.active`; module-gated routes (e.g. Lead Management) additionally behind `module:{slug}` (see `EnsureModuleAccess` middleware).
- `routes/admin.php` — platform area, prefixed `/admin`, guard `platform`.
- `routes/api.php` — public webhook intake for lead capture (Facebook Messenger, WhatsApp, Instagram, website form), keyed by `{tenant_code}` (not a slug column) and protected by `VerifyMetaWebhookSignature`.
- `routes/channels.php` — Reverb broadcast auth channels (`tenant.{tenantId}`, `lead.{lead}`).
- `App\Events\LeadReceived` (new lead — also drives auto-assignment and the "new lead" notification, so it must fire exactly once, on creation) vs `App\Events\LeadUpdated` (broadcast-only, for follow-up changes to an existing lead) are deliberately separate events despite both broadcasting to the same channels — don't collapse them.

### Livewire conventions (see `.ai/rules/livewire.md`, `.ai/rules/views-livewire.md`)
- A class-based full-page component named `Index` inside a subfolder resolves its view with the trailing `index` segment stripped: `App\Livewire\Tenant\Users\Index` → `resources/views/livewire/tenant/users.blade.php`, **not** `tenant/users/index.blade.php`. Non-`Index` class names keep their full path.
- `Route::livewire()` page components are auto-wrapped in a layout by Livewire — the component's Blade view must be only its single root element's inner content, with no `<x-layouts::*>` wrapper, or Livewire throws "Multiple root elements detected". Page `<title>` comes from `#[Title('...')]` on the class. Platform components use `#[Layout('layouts.admin')]`; tenant components use the default `layouts::app`; guest/auth pages use `#[Layout('layouts.auth')]`.

### Lead Management module
Ported from a sibling project (`OpticoreLeadManagementHub`, Inertia/React) onto this app's Blade/Livewire + Tenant/TenantUser/Role/Module/Permission stack, then extended in-place through 15 sequential, individually-closed features (see below). Everything lives under the `module:lead-management` route group, prefixed `/lead-management` with `App\Livewire\Tenant\LeadManagement\Dashboard` as the hub page (`lead-management.index`) linking into every nested page — e.g. tenant-side lead sources is `lead-management/lead-sources` (`tenant-lead-sources.index`, read-only; Platform Admin configures sources at `/admin/clients/{tenant}/lead-sources`), scoring rules is `lead-management/leads/scoring-rules`. Core models: `Lead`, `LeadSource`, `LeadAssignment`, `LeadStatus` (Platform-Admin-managed catalog, not an enum — see Multi-currency below), `LeadStatusLog`, `LeadNote`, `LeadAttachment`, `LeadDetailUpdateLog`, `LeadDeletionAudit`. Enums: `LeadSourceType`, `LeadPriority`, `LeadDistributionMode`, `AutoDistributionMethod`. Key pieces: `App\Services\LeadAssignmentService`, webhook capture in `App\Http\Controllers\Api\LeadWebhookController`, IMAP polling via `leads:fetch-emails` → `App\Jobs\ProcessEmailLeadSource` (durable per-message failure tracking in `FailedEmailMessage`, retried via `leads:retry-failed-emails`). If extending this module or building another "external capture + per-client config" feature, check the reference project's approach first.

Facebook Lead Ads (Instant Forms) submissions land on the same webhook URL as Messenger (`/api/webhook/facebook/{tenant_code}`) as a `leadgen` change event rather than a `messaging` event, and — unlike attachment downloads — are processed synchronously inside the webhook request, not queued. See `README.md` for the full setup/debugging runbook (`meta:subscribe-leadgen`, `leads:fetch-test`, and the `/admin/clients/{tenant}/lead-sources/{leadSource}/meta-permissions` scope-check route).

`docs/lead-management/PROGRESS.md` is the authoritative, feature-by-feature architecture record for everything below — final approved decisions, explicitly deferred scope, and known technical debt per feature. **All 15 features are COMPLETE/CLOSED; do not reopen or re-litigate a closed feature's decisions without a real regression.** No Feature #16 is planned — don't start new Lead Management scope without an explicit request, and follow the same Inspect → Impact Analysis → Architecture Proposal → wait-for-approval → Implement → Test → Regression-verify process every prior feature used (see PROGRESS.md §1, "Development Workflow / Rules").

1. **Duplicate detection & merge**: `App\Services\LeadDuplicateDetectionService` matches same-tenant leads by normalized email/phone across channels; `App\Livewire\Tenant\Leads\Show` runs detection live on every detail-page view, and `leads:detect-duplicates` is only a backfill sweep for pairs nobody has opened. `App\Services\LeadMergeService` merges a chosen duplicate into a survivor inside one transaction — reparenting child records (`CHILD_MODELS`: notes, attachments, status logs, assignments, detail logs, `Deal`, `Task`, `LeadConsentEvent`, `Conversation`/`Message`) and applying only explicitly-chosen conflicting fields — and marks the loser `merged_into_lead_id` rather than deleting it (identifier never reused). `App\Models\LeadDuplicateLink` is the audit trail. Merged-away leads are hidden from list views/exports/reporting and excluded from least-loaded assignment counts everywhere.
2. **Lead scoring (Phase 2a)**: Tenant-configurable rule-based scoring — each `LeadScoringRule` matches a Lead attribute via field/operator/value/points, managed by Client Admins at `lead-management/leads/scoring-rules` (`App\Livewire\Tenant\LeadScoring\Index`). `App\Services\LeadScoringService` always recomputes from scratch (current state + active rules + current time), clamps 0-100, writes `LeadScoreLog` only when score/breakdown actually changes. Recomputed on `LeadReceived`, manual creation, status/priority changes, scoring-relevant detail edits, a merge, and the daily `leads:recalculate-scores` sweep for time-derived rules.
3. **Lead tagging**: `LeadTag` is a tenant-owned catalog (not Platform-Admin-shared), attached via `Lead::tags()` (`BelongsToMany`, pivot `lead_lead_tag`). Catalog CRUD is Client-Admin-only at `lead-management/leads/tags`; attach/detach on a lead reuses the lighter `LeadPolicy::update()` bar. See `.ai/rules/lead-tags.md`.
4. **Deal / Opportunity**: `App\Models\Deal` — a Lead may have many Deals over its lifetime (`Lead::deals()`/`currentDeal()`), status is the plain 3-value `DealStatus` enum (`Open`/`Won`/`Lost`, deliberately independent of `LeadStatus`), never `Won⇄Lost` directly. `DealStatusLog` is keyed by `deal_id` only — never add it to `LeadMergeService::CHILD_MODELS`. Detail-page/flyout card only, no Deal Kanban/list route. See `.ai/rules/app-models.md`.
5. **Tasks**: `App\Models\Task` mirrors Deal's shape (`TaskStatus`: `Pending`/`Completed`/`Cancelled`, `TaskStatusLog` keyed by `task_id` only), complementary to (never replacing) Lead's own `next_follow_up_at`/`meeting_date`. `Task.assigned_to` is copied once at creation, never synced with Lead's assignment. Deletion: Client Admin deletes any task in an authorized lead, a non-admin only their own. Own daily sweep `tasks:send-alert-notifications` (not folded into the Lead alert command, since recipients are keyed off the Task's own assignee). See `.ai/rules/models-commands.md`.
6. **Bulk actions**: `App\Livewire\Tenant\Leads\Index::eligibleSelectedLeads()` is the single choke point re-deriving the real, authorized, capped (100) selection from the tenant's own scoped query — 7 actions (status/priority/assign/unassign/tag-attach/tag-remove/delete), each replicating the single-Lead path's exact business logic per-row inside its own transaction, never a raw mass update.
7. **CSV import**: `App\Models\LeadImport`/`LeadImportRow`, queued/chunked via Laravel Excel (`ShouldQueue`+`WithChunkReading`, chunk 200, real job chaining), max 10MB/10,000 rows, partial-success per row, dedup against both existing leads and in-file duplicates. `LeadReceived`/`LeadUpdated` never fire for imported rows. Full field-level reference: `docs/lead-management/FEATURE-07-CSV-IMPORT-HANDOFF.md`.
8. **Reporting & analytics** + **10. SLA/response tracking**: `App\Services\LeadReportingService` (one method per metric: volume, daily breakdown, status/funnel, source performance, assignment/team, `firstResponseSummary()`) powers `lead-management/leads/reports` (`App\Livewire\Tenant\LeadReports\Index`), Client-Admin-only, live-queried (no caching), reusing `FiltersLeadsQuery`'s date-range semantics. No monetary aggregation, no charts, current-state snapshots only (not historical transition analysis).
9. **Workflow / nurturing**: `leads:nurture-inactive-leads` (scheduled 08:00) — single condition (inactive ≥ tenant threshold, reusing `LeadScoringField::DaysSinceLastStatusChange` unmodified), two independently-toggleable actions (notify, create a follow-up `Task`). No visual builder, no multi-step sequences, no per-lead workflow-state table.
11. **Custom fields**: pure EAV — `LeadCustomFieldDefinition` (tenant catalog) + `LeadCustomFieldValue` (one row per lead+definition, blank value = row deleted, never stored empty); `leads` table gained zero columns. `type`/`options` lock once any value exists. Managed at `lead-management/leads/custom-fields`.
12. **Source cost / ROI**: `LeadSourceCost` — one tenant-recorded spend row per (source, calendar month), one consistent currency per source enforced via a `lockForUpdate()` on the parent `LeadSource` row. Powers a "Cost per Lead" report card; no revenue/ROI or currency conversion yet.
13. **Website Form Builder**: `WebsiteForm`/`WebsiteFormField` — a tenant may build any number of forms; every form shares one lazily-created `LeadSource` (`type = WebsiteForm`) for attribution. This app never hosts the form — the tenant's own site POSTs to `LeadWebhookController::websiteFormSubmit()`, authenticated by `token_hash`. Managed at `lead-management/leads/website-forms`.
14. **Consent / GDPR**: `LeadConsent` (current effective state, one row per lead+`ConsentType`, upserted) + `LeadConsentEvent` (permanent append-only history) via the single `App\Actions\Tenant\RecordLeadConsent` primitive. `ConsentType`: `Privacy`/`Marketing`. A Website Form field can be typed `Consent`; manual grant/withdraw lives on the Lead detail Consent card. Merge is bespoke (`LeadMergeService::mergeConsents()`): `Withdrawn` always wins, nothing is ever silently downgraded.
15. **In-app communication**: normalized `Conversation`/`Message` tables (additive dual-write alongside the pre-existing `Lead.source_data` JSON append — never a replacement, no historical backfill). Outbound sending (WhatsApp/Messenger/Instagram, free-form reply only, no templates/campaigns) gated by `App\Services\OutboundEligibilityService` (not merged, Privacy consent not withdrawn, source credentials present, channel's messaging window still open — Marketing consent is deliberately NOT checked for a within-window reply). Composer lives on the Lead detail/flyout only — no unified inbox.

`App\Livewire\Tenant\Leads\Index` serves two different views from one component depending on role, not two separate Livewire components: `render()` returns `livewire.tenant.leads` (table) for Client Admins and `livewire.tenant.leads-kanban` (board, via `boardLeads()`/`boardLeadCounts()`) for Client Users, both built on the same `filteredQuery()`. Facebook Lead Ads leads are excluded from the admin table (aggregated into a "View All" row pointing at `SourceLeads` instead) but appear as normal cards on a Client User's board.

### WhatsApp Calling — Inbound + Outbound (not a numbered Lead Management feature)
An explicitly-requested capability — WhatsApp calls in the browser via the Meta WhatsApp Business Calling API on the existing `whatsapp` lead source — built as **sequential, individually-approved Build Steps/fixes** (same Inspect → Impact Analysis → Architecture Proposal → wait-for-approval → Implement → Test → Regression-verify process as the 15 numbered features, tracked in the same `docs/lead-management/PROGRESS.md`, §"WhatsApp Inbound Calling — Build Step N" / §"WhatsApp Outbound Calling — ..."). **Inbound Steps 1–6, Step 7A (agent hangup), Step 7 Phase 1 (real-Meta-test-number validation) and the Adaptive Realtime Fallback (Reverb-primary softphone polling) are all CLOSED, as are three Outbound Calling fixes (template payload, Meta error 132018 parameter sanitization, Client Admin UI visibility) — outbound has itself been real-Meta-validated end to end.** Don't reopen a closed step/fix without a real regression, and don't start further calling work beyond what's already landed without an explicit request. In production `calling.enable_action_available` (env `CALLING_ENABLE_ACTION_AVAILABLE`) still defaults `false` so no source can even enable calling until explicitly turned on.
- **Data (Step 1)**: `LeadCall` (`lead_calls`, no `BelongsToTenant` — every query filters `tenant_id` explicitly, matching `Conversation`/`Message`), unique `(lead_source_id, external_call_id)`, a frozen routing snapshot (`assignee_id_at_arrival`/`routing_mode`/`routed_user_ids`) captured once and never touched by later auto-assignment, `CallStatus`/`CallEndReason`/`CallRoutingMode`/`CallMetaStage` enums. `lead_sources` gained real `calling_enabled`/`calling_meta_status`/`calling_meta_checked_at` columns (not keys in the encrypted `config` blob). `LeadCall` is a `lead_id`-keyed child in `LeadMergeService::CHILD_MODELS`; an active (Ringing/Answering/Connected) call blocks its Lead's deletion via `LeadDeletionEligibilityService`, terminal calls cascade-delete with it.
- **Inbound webhook (Step 2)**: an additive `calls[]` branch in `LeadWebhookController::whatsapp()` alongside the untouched `messages[]`/`statuses[]` code; `App\Support\WhatsAppCallEvent` parses `connect`/`terminate` events; `App\Actions\Tenant\RecordInboundCall` is the single writer of `lead_calls` (reuses the message path's phone lock and lead-lookup order); `App\Services\CallRouter` (read-only) computes the routing snapshot. The SDP offer is cached encrypted (`Crypt::encryptString`, key `wa-call-offer:{tenant}:{source}:{call id}`), never persisted to the database or logs.
- **Meta API client + admin controls (Step 3)**: `App\Services\WhatsAppCallingClient` (bound to one `LeadSource`, `getSettings`/`setCallingStatus`/`preAccept`/`accept`/`reject`/`terminate`) and `App\Actions\Platform\ManageWhatsAppCalling` (`enable`/`disable`/`check`) let a Platform Admin toggle Meta's calling switch per WhatsApp source at `/admin/clients/{tenant}/lead-sources`; `calling_enabled` (local intent) and `calling_meta_status` (last observed) are deliberately separate, reconciled via conditional (never-stale-overwriting) updates.
- **Claim/answer/accept/reject/hangup (Steps 4 & 7A)**: `App\Policies\LeadCallPolicy` — only a user named in the call's frozen routing snapshot may act. `ClaimLeadCall`/`AnswerLeadCall`/`RejectLeadCall`/`HangupLeadCall` are each one compare-and-set conditional UPDATE committed **before** Meta is ever asked (never a DB lock across a Meta or cache call); `answer` = Meta `pre_accept`, `accept` = Meta `accept`, kept as separate operations; `HangupLeadCall` ends a Connected call (claimant-only, idempotent on an already-terminal call) via a single, never-retried `terminate()`.
- **Realtime, softphone, WebRTC (Step 5, later re-architected)**: one generic `App\Events\LeadCallUpdated` (`call.updated`, payload `{id, status}` only) broadcasts to each routed user's own `tenant-user.{id}` channel; a global softphone lives in the tenant shell (persists across `wire:navigate`; a mobile full-screen layout was added for Ringing/Connecting/Connected/Failed), ring → Answer/Decline → connecting → connected, client-only mute, no STUN/TURN. Polling (`GET calls/active`) was later made Reverb-**primary**, not parallel: a 15s HTTP heartbeat (`calling.softphone_heartbeat_seconds`) in the healthy state, escalating to a 5s degraded interval (`calling.softphone_poll_seconds`) only once `calling.softphone_staleness_seconds` (20s) passes with no successful refresh — see `resources/js/softphone/controller.js`'s `POLL_MODE` state machine.
- **Self-healing + notifications (Step 6)**: `calls:reconcile-stale` (`App\Console\Commands\ReconcileStaleLeadCalls`, registered `->everyMinute()->withoutOverlapping(10)` through the `$monitored()` helper) ends calls whose `terminate` webhook never arrived via `App\Actions\Tenant\FinalizeStaleLeadCalls` (one conditional UPDATE per stale rule); a resulting Missed call dispatches `App\Notifications\MissedCallNotification` through `App\Jobs\SendMissedCallNotification`/`App\Support\LeadCallMissedNotifier` to the tenant's active Client Admins (deduped like `SendTaskAlertNotifications`).
- **Outbound calling**: agent-initiated calls to a lead's WhatsApp number, gated behind Meta's live call-permission contract. `LeadCallPermission` (model, one row per lead, states via `LeadCallPermissionStatus`: NoPermission/Requested/Temporary/Permanent/Rejected) is upserted from `CheckLeadCallPermission`'s live Meta check and `RecordCallPermissionReply`'s webhook handler; `RequestLeadCallPermission` sends the approved `call_permission_request` template, always gated on a live check, never blind. `InitiateOutboundCall`/`ConfirmOutboundCallConnected`/`CancelOutboundLeadCall`/`FetchOutboundCallAnswer` drive the lifecycle — real browser ICE/WebRTC confirmation (`ConfirmOutboundCallConnected`) is the sole Connected gate; Meta's own webhook status (`RecordOutboundCallStatusEvent`) is informational only. `CallRoutingMode::AgentInitiated` records just the initiating agent, bypassing `CallRouter` entirely (outbound is never extended to backup receivers — placing a call stays assignee-or-Client-Admin only, via `LeadPolicy::update()`). `LeadCallPermission` gets bespoke merge behavior (`LeadMergeService::mergeCallPermissions()` — a current-state, unique-`lead_id` table, never a plain `CHILD_MODELS` reparent target).
- **WhatsApp Call Backup / Overflow Receiver**: `tenant_users.is_whatsapp_backup_receiver` opts a user into inbound overflow coverage. `CallRouter` now has three routing tiers — `CallRoutingMode::Assignee` (the lead's assignee, if eligible and available), `BackupFallback` (assignee ineligible/busy/unavailable → every backup-receiver user who is active, has lead-management `update` permission, and isn't already busy, rung simultaneously), `AdminFallback` (unassigned lead, or no eligible backup either → every active Client Admin with access), `None` (nobody eligible, recorded Missed without ringing). Never touches `Lead.assigned_to` — call-level overflow only, decided once at arrival, never re-evaluated mid-ring.
- **Assignee presence (softphone availability signal)**: `App\Services\SoftphonePresence::isAvailable()` is the single thing `CallRouter` asks whether a user's softphone is reachable — there is otherwise no online/offline signal anywhere in the app. Two-tier: first ask `App\Services\ReverbPresenceQuery` (a `presence-tenant-softphone.{tenantId}` Reverb channel, via `ReverbChannelUsersGateway`/`PusherReverbChannelUsersGateway`) whether the user is a live channel member — gated behind `calling.reverb_presence_enabled` (env `CALLING_REVERB_PRESENCE_ENABLED`, **defaults false** — a deliberate no-op until the frontend's presence-channel join is confirmed live and the flag is explicitly turned on); when disabled, unreachable, or timed out, fall back unchanged to the pre-existing `tenant_users.softphone_last_seen_at` heartbeat (refreshed by the softphone's own poll; `NULL` — never seen — is treated as available, only a stale timestamp downgrades a user). A Reverb outage can never be read as "everyone offline."
- **Config**: `config/calling.php` — all TTLs/timeouts per step/fix, each documented inline with the Meta behavior or product decision it encodes; read this file before touching any timing-related decision rather than re-deriving it.
- **Web Push for incoming calls**: `laravel-notification-channels/webpush` wakes a routed recipient even with every tab closed — `App\Notifications\IncomingWhatsAppCallNotification` → `App\Jobs\SendIncomingCallPushNotification` → `App\Support\LeadCallPushNotifier` mirror the existing missed-call-notification shape exactly, fired from `RecordInboundCall::connect()` alongside the realtime broadcast, only for a fresh Ringing call, only to `CallRouter`'s routed recipients. `App\Listeners\DeleteExpiredPushSubscription` self-heals a subscription a push service reports gone. The `push_subscriptions` migration is patched to `ulidMorphs()` (the package's stock `morphs()` assumes an auto-increment PK; `TenantUser` is ULID-keyed). A web app manifest + iOS meta tags are required alongside it — Safari only grants push to a site launched from an installed home-screen icon, never a bookmarked tab. Client JS: `resources/js/softphone/push.js` + `public/sw.js`.
- **Per-tenant custom ringtone + audio output**: a Client Admin can upload a tenant-wide MP3 (≤1MB) at `lead-management/leads/calling-settings`, streamed back by `App\Http\Controllers\WhatsAppCallRingtoneController` (tenant-scoped like `LeadAttachmentController`); `resources/js/softphone/ringtone.js` only switches to it when set, otherwise using the same synthesized default tone as before — plus Media Session registration, autoplay pre-unlock, and pause/visibility retry hardening so a backgrounded tab still rings reliably. `resources/js/softphone/audio-output.js` adds an earpiece/loudspeaker toggle on the Connected call UI via `HTMLMediaElement.setSinkId()` — Chrome/Edge on Android only (no iOS Safari equivalent); the toggle simply doesn't render when the device/browser can't support it, same "a missing capability is never a gate" pattern as the rest of this section.
- **Docs drift**: `docs/lead-management/PROGRESS.md`'s closure entries stop at the Client Admin Call Permission UI Visibility fix — everything from backup receivers / offline-softphone routing fallback / Reverb presence onward (including the Web Push, custom ringtone, and audio-output work above) landed under the project's convention of excluding `CLAUDE.md`/`PROGRESS.md` from feature commits ("own protected diffs") and has **no** `PROGRESS.md` closure entry yet. Verify current behavior against the code (`CallRouter`, `SoftphonePresence`, `config/calling.php`, `resources/js/softphone/*`) rather than assuming `PROGRESS.md` is exhaustive for this area — and expect this gap to keep growing until someone does a doc-catch-up pass.

### Real-time notifications
The notification bell (`App\Livewire\Tenant\NotificationBell`, embedded once in `layouts/app.blade.php` and once in `layouts/app/sidebar.blade.php` — never per-page, and never on the Platform side) surfaces Laravel's standard "database" notification channel and updates live via Reverb rather than polling: `App\Events\UserNotified` broadcasts to a user's own private channel (`tenant-user.{id}`) the instant any notification is created, fired by `App\Listeners\BroadcastUserNotification` listening on Laravel's internal `NotificationSent` event — not dispatched manually from each call site — so any future notification type gets real-time delivery for free. The broadcast carries no payload; the bell just re-queries on receipt. Clicking a notification routes through `NotificationRedirectController` (`App\Services\NotificationUrlResolver` resolves the target) rather than a raw link, so "mark read" and "redirect" happen as one atomic request instead of a `wire:navigate`/`wire:click.renderless` race. `App\Services\LeadAdminNotifier` fans a notification out to every *other* active Client Admin in a lead's tenant (never the actor). The daily `leads:send-alert-notifications` command is the bell's counterpart to the Kanban card's live meeting/follow-up shimmer (`Lead::meetingAlertActive()`/`followUpAlertActive()`, recomputed on read, nothing persisted) — it persists one notification per lead per recipient per day for as long as that shimmer is active.

### Multi-currency
`App\Models\Currency` is a Platform-Admin-managed catalog (same shape as `LeadStatus`): not tenant-scoped itself, shared across tenants that opt in via the `tenant_currencies` pivot (`Tenant::currencies()`). Tenants pick which currencies they support at `/admin/clients/{tenant}/lead-sources` (`LeadSources::toggleCurrency()`); those choices populate the per-field currency dropdowns on `estimated_budget`/`deal_value`. A currency in use (enabled by any tenant, or referenced by a Lead's `estimated_budget_currency_id`/`deal_value_currency_id`) can't be deleted — enforced in `App\Livewire\Platform\LeadManagement\Settings::deleteCurrency()`. The currencies-catalog migration unconditionally seeds a "USD" row, so it's a permanent baseline fixture in every test (same trap as `LeadStatus`'s legacy-seeded statuses) — see `.ai/rules/migrations-models.md`.

### Scheduler & ops monitoring
Every scheduled command in `routes/console.php` must be registered through the `$monitored(...)` helper (not bare `Schedule::command()`), so a non-zero exit is logged/emailed by `App\Services\ScheduledTaskMonitor`; `leads:fetch-emails` (every minute) doubles as the scheduler heartbeat. IMAP outage alerting lives in `ProcessEmailLeadSource`. Config is `config/ops.php` (`OPS_ALERT_EMAIL`, `OPS_SCHEDULER_HEARTBEAT_URL`, both optional); see `docs/lead-management/OPS-MONITORING.md` for what is and isn't detected (notably a dead queue worker is not).

### Exports & audit logging
`app/Exports` holds `maatwebsite/excel` export classes, one per download — currently only `FacebookLeadAdsExport`, streamed via `Excel::download()` from `App\Livewire\Tenant\Leads\SourceLeads` and mirroring that view's dynamic per-Instant-Form-field columns. Add new exports here as sibling classes rather than inlining spreadsheet-building logic into a Livewire component.

`spatie/laravel-activitylog` (`LogsActivity` trait, `logOnly([...])->logOnlyDirty()`) is wired into `TenantUser`, `PlatformUser`, `Role`, and `Tenant` to record field-level change history into the `activity_log` table. It's write-only today — no UI reads it back yet — so treat it as an audit trail to extend (same `logOnly`/`logOnlyDirty` pattern) rather than a feature to wire a viewer for unless asked.

### Skills
Domain skills exist under `**/skills/**` (Livewire, Flux UI, Fortify, Echo/broadcasting, Tailwind, testing, Laravel best practices). Activate the matching skill proactively when working in that domain rather than waiting to get stuck.

## Laravel Boost Guidelines

The block below is maintained by `composer post-update-cmd` (`artisan boost:update`) — don't hand-edit it; if it drifts, regenerate it rather than patching it manually.

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.3. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.
- Record durable rules with `record-rule` so the next agent or teammate inherits them instead of working them out again. Pass a `glob` (e.g. `app/Http/Controllers/**`), a short `title`, and a few-line `note`. Always use `record-rule`, never your native memory or notes tool — native memory is personal and session-scoped; only `.ai/rules` is shared with the team and persists in the repo.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Test every code change by adding or updating a test.
- Run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== livewire/core rules ===

# Livewire

- Livewire allow to build dynamic, reactive interfaces in PHP without writing JavaScript.
- You can use Alpine.js for client-side interactions instead of JavaScript frameworks.
- Keep state server-side so the UI reflects it. Validate and authorize in actions as you would in HTTP requests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.
- After the feature tests pass, ask the user to run the complete suite with `php artisan test --compact`.

</laravel-boost-guidelines>
