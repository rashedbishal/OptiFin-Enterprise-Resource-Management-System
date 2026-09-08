# Lead Management Development Progress

This document is the authoritative handoff state for ongoing Lead Management enhancement work in this repository. It exists so a new Claude CLI session (or a new engineer) can pick up the work tomorrow without needing this conversation's history.

## 1. Development Workflow / Rules

Every Lead Management enhancement — no exceptions — follows this process:

1. **Inspect** — read the actually-relevant models, migrations, controllers, services, routes, policies/permissions, jobs/events/listeners, notifications, frontend components, existing tests, and DB relationships for the feature at hand.
2. **Impact Analysis** — explicitly reason about what existing behavior the change could touch.
3. **Architecture Proposal** — explain the planned approach (data model, triggers, UI, permissions, risks) before writing any code.
4. **Wait for User Approval** — do not proceed past the proposal until the user explicitly approves it. Open decision points get asked as explicit questions, not silently assumed.
5. **Implementation**
6. **Testing** — comprehensive tests for the new feature, covering success paths, edge cases, permission boundaries, and tenant isolation.
7. **Regression Verification** — Pint, PHPStan, the new feature's own tests, the full `tests/Feature/Leads/` suite, and the full application suite. Confirm no pre-existing behavior changed.

**Never skip directly to implementation for a new feature.**

Core principles:

- Preserve all existing functionality. Additive-only wherever possible.
- Implement one feature at a time — do not bundle multiple roadmap items into one change.
- Avoid scope creep. Build exactly what was approved, nothing more.
- Do not modify unrelated code, routes, permissions, or UI.
- Proactively identify risks and architectural conflicts before they become bugs — surface them in the proposal, not after implementation.
- Do not reopen a completed/approved feature unless a real blocker or regression is discovered by a later feature. A completed feature's own backlog items (see §7) are deferred, not reopened, until the user asks for them.

## 2. Current Lead Management Baseline

The Lead Management module (pre-existing, before Features #1/#2 in this document) is a **Blade/Livewire** module — no React, no separate frontend framework — built on this app's Tenant/TenantUser/Role/Module/Permission stack, ported from a sibling project (`OpticoreLeadManagementHub`).

Implemented baseline capabilities:

- **Multi-channel lead capture**: Facebook Messenger, Instagram, WhatsApp, Facebook Lead Ads, Website Form, Email (IMAP polling via `leads:fetch-emails`), LinkedIn Lead Gen (Phase 1, manual token entry). All via `App\Http\Controllers\Api\LeadWebhookController::createLead()` (5 call sites) or `App\Jobs\ProcessEmailLeadSource`. Each fires `App\Events\LeadReceived` exactly once, on creation.
- **Manual lead creation**: `App\Actions\Tenant\CreateManualLead` — lazily provisions a per-tenant "Custom" `LeadSource`. Does **not** fire `LeadReceived` (a pre-existing, known gap — see §5's manual-lead scoring workaround for how Feature #2 handled this without fixing it).
- **Status pipeline**: `App\Models\LeadStatus` — Platform-Admin-managed shared catalog, attached per-tenant via a pivot with per-tenant `position` ordering; has an `is_closed` flag. Full history in `LeadStatusLog`.
- **Kanban**: `App\Livewire\Tenant\Leads\Index` renders either the table (Client Admin) or the Kanban board (Client User) from the same `filteredQuery()`/`FiltersLeadsQuery` concern.
- **Lead assignment/distribution**: `App\Services\LeadAssignmentService` — manual assign/unassign, plus automatic round-robin / least-loaded / random distribution, driven by `Tenant::lead_distribution_mode`/`auto_distribution_method` (configured Platform-Admin-side). Full history in `LeadAssignment`.
- **Notes**: `App\Models\LeadNote`, freeform, tied to a `TenantUser`.
- **Attachments**: `App\Models\LeadAttachment`, downloaded async via `App\Jobs\DownloadLeadAttachment`, stored on the private `local` disk.
- **Activity timeline**: `App\Livewire\Tenant\Leads\Show::activity()` aggregates `LeadStatusLog`, `LeadAssignment`, `LeadNote`, `LeadDetailUpdateLog` (+ now `LeadDuplicateLink` merge events, +Feature #1) into one chronological feed.
- **Meetings/follow-ups**: `meeting_date`/`meeting_time` (time is record-only, never drives the alert), `next_follow_up_at`, live non-persisted Kanban-card shimmer alerts (`meetingAlertActive()`/`followUpAlertActive()`), tenant-configurable enable + days-before threshold, daily `leads:send-alert-notifications` sweep persists bell notifications.
- **Notifications**: `NewLeadNotification`, `LeadAssignedNotification`, `MeetingUpdatedNotification`, `MeetingCancelledNotification`, `MeetingAlertNotification`, `FollowUpUpdatedNotification`, `FollowUpCancelledNotification`, `FollowUpAlertNotification` — all real-time via Reverb through `App\Listeners\BroadcastUserNotification` on Laravel's internal `NotificationSent` event.
- **Multi-currency budget/deal fields**: `estimated_budget`/`estimated_budget_currency_id`, `deal_value`/`deal_value_currency_id`, `win_probability` — `App\Models\Currency` is a Platform-Admin-managed shared catalog, tenants opt in via `tenant_currencies`.
- **Excel export**: `App\Exports\FacebookLeadAdsExport` (`maatwebsite/excel`) — currently the only export, mirrors the Facebook Lead Ads spreadsheet view's dynamic per-form-field columns.
- **Permissions**: `App\Policies\LeadPolicy` (`viewAny`/`create`/`view`/`update`/`delete`/`assign`, +`merge` from Feature #1). `PermissionSlug` is a **fixed, global 4-value enum** (`View`/`Create`/`Update`/`Delete`) — not extensible per module without a much larger cross-cutting change. Every new capability added by Features #1/#2 reuses the existing `lead-management` module's `update` permission + `isClientAdmin()`, never a new slug.
- **Tenant isolation**: `App\Concerns\BelongsToTenant` adds a global scope, but it **only activates when `app()->bound('currentTenant')`** — which is **not** the case in public webhook/job/command contexts. Every new query in Features #1/#2 filters `tenant_id` explicitly rather than relying on the trait alone. This is the single most important correctness rule for any future Lead Management feature touching cross-lead queries.
- **Architecture discovered during inspection** (load-bearing, apply to any future feature):
  - `Lead::identifier` is monotonic and never reclaimed (`{tenant.lead_id_prefix}-LEAD-{n}`, generated in `Lead::booted()`'s `creating` hook) — never mass-assignable, never reused even after deletion/merge.
  - A background/event-driven write to a `Lead` column must use a **raw query builder update** (`DB::table('leads')->where(...)->update(...)`), not `$lead->save()`, when it must not touch `updated_at` — otherwise it spuriously trips `Lead::hasUnseenUpdate()`'s "recently updated" highlight for every viewer. Established by `Lead::markUpdateSeen()`, reused by Feature #1's merge (loser update) and Feature #2's score writes.
  - `App\Livewire\Tenant\Leads\Index` excludes Facebook Lead Ads leads from the Client Admin's table (aggregated into `SourceLeads` instead) but shows them as normal Kanban cards for Client Users.
  - No reporting/dashboard exists anywhere for leads — `App\Livewire\Tenant\Dashboard` shows only tenant/module/user/role counts, zero lead data. The only existing aggregation is `Lead::countsByStatus()` for Kanban column headers.
  - No `EventServiceProvider` exists — Laravel 11+ auto-discovers listeners from their `handle()` method's type-hinted event parameter.
  - `Auth::user()` is typed `PlatformUser|TenantUser|null` by Larastan throughout the whole app (a pre-existing, accepted, unfixed limitation — confirmed identical in pre-existing files like `Roles/Index.php`) — don't try to "fix" this pattern in new code; it's baseline noise, not a real bug.

## 3. Feature Roadmap

| # | Feature | Status |
|---|---|---|
| 1 | Duplicate Detection + Lead Merge | **COMPLETE / APPROVED** |
| 2 | Lead Scoring Phase 2a | **COMPLETE / APPROVED** |
| 3 | Lead Tagging | **NEXT FEATURE — NOT STARTED** |
| 4 | Deal / Opportunity | Future |
| 5 | General Tasks / Reminders | Future |
| 6 | Bulk Actions | Future |
| 7 | CSV Import | Future |
| 8 | Reporting & Analytics | Future |
| 9 | Workflow / Nurturing | Future |
| 10 | SLA / Response Tracking | Future |
| 11 | Custom Fields | Future |
| 12 | Source Cost / ROI | Future |
| 13 | Website Form Builder | Future |
| 14 | Consent / GDPR | Future |
| 15 | In-App Communication | Intentionally deferred to later — do not build until explicitly requested |

Feature #3 (Lead Tagging) was **not** implemented or scoped during the creation of this document — only this handoff document was written.

## 4. Feature #1 — Duplicate Detection & Merge

Final approved architecture and decisions (do not re-litigate without a real blocker):

- Cross-channel duplicate detection by normalized email/phone match, same-tenant only — `App\Services\LeadDuplicateDetectionService`. Writes/reuses `App\Models\LeadDuplicateLink` rows (`pending`/`dismissed`/`merged`), canonically ordered by ULID pair (`LeadDuplicateLink::pairKeys()`) so a pair is never re-flagged after a dismissal.
- **Merge behavior**: `App\Services\LeadMergeService::merge()` reparents the loser's child records (notes, attachments, status logs, assignments, relevant `LeadDetailUpdateLog` entries) onto the survivor, then marks the loser `merged_into_lead_id` — **no hard delete, no identifier reuse**. The loser stays fully queryable but is excluded from normal list views (`Index::filteredQuery()`, `SourceLeads::baseQuery()`); opening the loser's own detail page redirects to the survivor.
- The whole merge runs inside **one `DB::transaction()`** — verified with a real mid-transaction FK-failure test proving full rollback (no partial state).
- **Conflict values require explicit selection** — `LeadMergeService::conflictingFields()` only flags fields where the loser has a real, differing, non-empty value; the default resolution is always "keep survivor" unless the admin explicitly picks "use loser's value." Fields not in the mergeable allow-list (pipeline/relationship columns: `status_id`, `assigned_to`, `source_id`, `priority`, dates, etc.) are **never** touched by a merge.
- Audit trail: `LeadDuplicateLink` stores `resolved_by`, `resolved_at`, `status = merged`, and `field_resolutions` (a snapshot of every conflicting field's chosen side plus both leads' values at merge time).
- **Duplicate badge is detail-page only** — never on the leads table or Kanban card (a deliberate scope decision, not a limitation to "fix").
- Permissions: `LeadPolicy::merge()` reuses `update` + `isClientAdmin()` — no new permission slug. This became the template every later capability (including Feature #2) copies.

**Explicit backlog, not a current blocker** (see also `.claude`/memory: `project_lead_merge_backlog.md`):
- Duplicate-detection query performance — `LeadDuplicateDetectionService::detectFor()` scans all of a tenant's contactable leads in PHP; confirmed via `EXPLAIN` against real Postgres that the `(tenant_id, email)`/`(tenant_id, phone)` indexes aren't effectively used for this. Future fix: Postgres functional/expression indexes + SQL-level normalized matching.
- Merge budget/currency pairing — `estimated_budget`/`estimated_budget_currency_id` (and the `deal_value` pair) are resolved as fully independent choices in the merge UI, allowing a mismatched amount+currency. Future fix: pair them into one selection.
- **Intentional, documented UX trade-off (not a bug)**: when the survivor's field is empty and the loser has a value, the conflict still defaults to "keep survivor" (stays empty) rather than auto-filling — uniform, non-destructive-by-default behavior, at the cost of requiring the admin to notice and act.

**Do not modify Feature #1 unless a future feature causes a real regression.**

## 5. Feature #2 — Lead Scoring Phase 2a

Final approved implementation:

- Score is a **nullable integer, 0–100**. No active rules for the tenant → `lead_score = NULL` ("Not scored"). At least one active rule → always a real, deterministic 0–100 integer (including a legitimate computed 0).
- Rules carry **signed integer points** (no per-rule min/max) — the final total is hard-clamped to [0, 100], not scaled/normalized (scaling would make unrelated leads' scores drift whenever a rule is added elsewhere, which breaks explainability).
- **Raw total (pre-clamp) is preserved** in `LeadScoreLog.raw_total`, alongside the clamped `score`.
- **Every recomputation is from scratch**: current Lead state + current tenant's active rules + current time — `LeadScoringService::computeFor()` never increments/decrements the previously stored score. Verified deterministic (same inputs → same output across repeated calls) and non-accumulating.
- A `LeadScoreLog` row is written **only when `(score, breakdown)` actually differs** from the lead's last log — trigger reason is metadata, not part of that comparison. This keeps the log table's growth proportional to real change, not event volume.
- `breakdown` is a **point-in-time snapshot** (rule name/field/operator/value/matched value/points for every matched rule) — not a live join, so history stays readable after a rule is edited or deleted.
- Malformed/unsupported rules (bad field, bad operator, invalid field/operator pairing) are **safely skipped and logged server-side** (`Log::warning`) — never break lead creation, editing, merge, or recalculation.
- `lead_score_computed_at` is maintained on every compute run (even a no-op one). The score write is a **raw query builder update**, never touching `updated_at` (see §2's architecture note).

**Scoring fields** (extensible registry, `App\Enums\LeadScoringField`): `priority`, `source_type`, `status`, `estimated_budget`, `deal_value`, `win_probability`, `has_email`, `has_phone`, `days_since_created`, `days_since_last_status_change`.

**Supported operators** (`App\Enums\LeadScoringOperator`): `>=`, `<=`, `>`, `<`, `=`, `!=`, `in`, `not_in` — each field's kind (numeric/enum/boolean) constrains which operators are valid, enforced both in the UI and server-side in `save()`.

**Trigger points** (`App\Services\LeadScoringService::SCORING_RELEVANT_DETAIL_FIELDS` is the single source of truth for which detail fields count):
- `LeadReceived` (new listener `RecomputeLeadScoreOnReceipt`)
- `CreateManualLead::handle()` (manual leads never fire `LeadReceived` — additive workaround, not a fix to that gap)
- `Show::updateStatus()` — status and/or priority changed
- `Show::applyDetailChanges()` — only when changed fields intersect `estimated_budget`, `deal_value`, `win_probability`, `email`, `phone`
- `LeadMergeService::merge()` — only when the merge's applied field resolutions intersect the same scoring-relevant set
- Daily `leads:recalculate-scores` sweep (scheduled, excludes merged-away leads) — the only thing that re-evaluates time-derived fields

**Deliberately does NOT trigger scoring**: assignment/unassignment, notes, attachments, generic `LeadUpdated` broadcasts, company-only edits, message-timeline appends, meeting/follow-up schedule/cancel/done actions.

**UI**:
- Score + per-rule breakdown shown on the Lead detail page and the Kanban flyout (`score-breakdown.blade.php`), read-only on view (never triggers a recompute itself).
- Manual "Recalculate" action, gated to Client Admin (`Show::canManageScoring`).
- Tenant-side Scoring Rules management page at `/leads/scoring-rules` (`App\Livewire\Tenant\LeadScoring\Index`), full CRUD, Client-Admin-only, with a soft warning callout when active rules could total over 100.
- The pre-existing hero stat box mislabeled "Lead Score" (it was actually `win_probability`) was corrected to **"Win Probability"** — `win_probability`'s own meaning/behavior/validation is completely unchanged, display label only.
- Priority remains a manual field; Score is a computed field — kept visually/conceptually distinct in the UI copy.
- **No manual score override** — out of scope for Phase 2a by explicit decision.

**Permissions**: no new permission slug — `App\Policies\LeadScoringRulePolicy` and `Show::canManageScoring` both reuse the existing `lead-management` `update` permission + `isClientAdmin()`. Tenant isolation enforced explicitly in every query (not relying on the `BelongsToTenant` trait alone, per §2).

**Explicitly deferred from Feature #2** (Phase 2b or later, not started): score badge in list/Kanban, score filtering, score sorting, dashboard/reporting, score-aware assignment (`LeadAssignmentService` was **not** made score-aware), ML/predictive scoring, workflow/nurturing integration, manual override, In-App Communication.

## 6. Feature #2 Verification

- 77 new scoring tests, all passing:
  - `LeadScoringServiceTest`: 27/27
  - `LeadScoringTriggersTest`: 12/12
  - `LeadScoringRuleManagementTest`: 6/6
  - `LeadScoringDisplayTest`: 5/5
- Full `tests/Feature/Leads/`: 449 passed, 1 pre-existing skip, 0 failures.
- Full application suite: 604 passed, 2 failures, 1 skip.
- The 2 failures are pre-existing `tests/Feature/Broadcasting/LeadsIndexListenerTest.php` failures (expects `Index::getListeners()` to map to `'$refresh'`, but the actual code already maps to `onLeadReceived`/`onLeadUpdated`) — reproduced identically on a clean `git stash` of `main` with none of this session's changes applied. Confirmed pre-existing, not caused by this work.
- Pint: clean.
- PHPStan: baseline was 258 errors; current full-project output is 269. Every additional error was individually traced to either the pre-existing `Auth::user()` union-type limitation (confirmed byte-for-byte identical in kind to pre-existing `Roles/Index.php`) or pre-existing `Lead.php` return-type gaps in code this work never touched — not scoring logic.
- Two real implementation bugs were found (by test failures, not phpstan) and fixed during this pass:
  1. `updateStatusAndAssignment()`'s internal `$this->updateStatus()` call broke once `updateStatus()` gained a method-injected `LeadScoringService` parameter (method injection only works for Livewire's own request-level action dispatch, not a plain internal PHP call) — fixed by resolving the service via `app()` inside `updateStatus()` instead.
  2. `LeadScoringService` used `loadMissing(['source', 'status'])`, which silently skipped reloading an already-cached, now-stale relation right after `Show::updateStatus()` changed it — fixed by switching to `load()` (force reload).
- Migrations and the `leads:recalculate-scores` command were both run successfully against the real Postgres dev database.
- Existing lead creation (all 3 paths), per-channel dedupe, merge, assignment/distribution, status pipeline, and notification behavior all remain passing, unmodified.

## 7. Known Technical Debt / Risks

- PHPStan baseline cleanup (the pre-existing `Auth::user()` union-type gap, the pre-existing `Lead.php` return-type gaps, etc.) is **separate technical debt**, not something to fix incidentally while building a feature.
- The Scoring Rules UI's numeric value input is free text with basic `is_numeric` tolerance — a tenant typing e.g. `"1,000"` doesn't error, it just never matches numerically. A UX rough edge, not a correctness bug.
- Time-derived scores (`days_since_created`, `days_since_last_status_change`) only refresh via the daily sweep (or an unrelated status/detail edit that happens to also trigger a recompute) — by design, not a bug.
- Feature #2 Phase 2b (badge/filter/sort/dashboard/etc., see §5) remains intentionally deferred.
- Feature #1's two backlog items (duplicate-detection index performance, merge budget/currency pairing — see §4) remain intentionally deferred.
- **Do not reopen any of the above unless a real production correctness issue appears.**

## 8. NEXT ACTION

The next development task is **Feature #3 — Lead Tagging**.

Tomorrow, **do not start coding immediately**. First perform:

1. Repository inspection
2. Existing Lead tagging-related code/data discovery (confirm nothing already exists — Features #1/#2's own inspections found zero hits for scoring/duplicate concepts before building them; do the same check for tagging)
3. Database/schema inspection
4. Existing UI/Livewire architecture inspection
5. Creation/edit/import/channel paths inspection
6. Permission/tenant isolation inspection (remember: no new `PermissionSlug` is realistically available — see §2)
7. Existing filters/search/Kanban architecture inspection (`FiltersLeadsQuery` concern)
8. Impact analysis
9. Proposed Feature #3 architecture
10. Risks and edge cases
11. Implementation scope
12. Testing strategy

Then **STOP and wait for explicit user approval**. Do not implement Feature #3 until the user approves the architecture.

## 9. Important Instruction

A future Claude CLI session must treat this document as the authoritative Lead Management handoff state, **while still inspecting the actual current codebase before making implementation decisions**.

Never assume the codebase exactly matches this document — verify the current code first (files may have shifted, tests may have been added, a human may have made changes outside this workflow).

Do not reopen completed features merely because they are documented here.

---

**Last completed feature: Feature #2 — Lead Scoring Phase 2a**
**Next feature: Feature #3 — Lead Tagging**
**Next required action: Inspect and propose architecture; do not implement until approved.**
