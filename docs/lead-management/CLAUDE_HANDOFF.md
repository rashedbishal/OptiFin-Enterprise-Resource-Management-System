# Lead Management — Claude CLI Handoff

This document exists so a completely fresh Claude CLI session — on a different machine, with no memory of prior conversations — can safely continue Lead Management work. It is a snapshot, not a replacement for `docs/lead-management/PROGRESS.md`, which remains the authoritative, continuously-updated handoff state. Read both; if they ever disagree, `PROGRESS.md` plus the actual repository state wins (see §6).

## 1. Current Project State

**Application**: Laravel 13, PHP 8.3, Livewire 4, Flux UI 2, Tailwind v4 — a multi-tenant ERP with a Platform side (`/admin/*`, vendor-run) and a Tenant side (`/*`, client-run), plus a Lead Management module ported from a sibling project (`OpticoreLeadManagementHub`).

**Lead Management implementation style**: Blade/Livewire (no React/Inertia in this port), built on this app's own Tenant/TenantUser/Role/Module/Permission stack. Class-based Livewire components under `app/Livewire/Tenant/**` and `app/Livewire/Platform/**`; views under `resources/views/livewire/**`. A class-based full-page component named `Index` inside a subfolder resolves its view with the trailing `index` segment stripped (e.g. `App\Livewire\Tenant\LeadReports\Index` → `resources/views/livewire/tenant/lead-reports.blade.php`).

**Database/tenant architecture**: Two separate people tables and guards — `PlatformUser` (guard `platform`, `/admin`) and `TenantUser` (guard `web`, tenant-scoped) — never a shared generic `User` model. All IDs are ULIDs. `App\Concerns\BelongsToTenant` adds a global scope that **only activates when `app()->bound('currentTenant')`** — not true in webhook/job/scheduled-command contexts — so every cross-lead query in this module filters `tenant_id` explicitly rather than relying on the trait alone. This is the single most important correctness rule for any new Lead Management feature.

**Important testing/quality commands**:
```bash
composer run dev                         # artisan serve + queue + pail + vite
vendor/bin/pint --dirty --format agent   # format PHP after any change
phpstan analyse                          # composer run types:check
php artisan test --compact               # full suite
php artisan test --compact --filter=testName
vendor/bin/pest path/to/FileTest.php
composer run test                        # config:clear + lint:check + types:check + full suite (CI gate)
```

**Important development constraints** (see `CLAUDE.md` and `.ai/rules/index.md` for the full, authoritative list):
- `.ai/rules/index.md` maps file globs to rule files under `.ai/rules/` — read it and every matching rule file before editing anything under `app/` or `resources/views/livewire/`.
- `PlatformUser`/`TenantUser`/`Lead` use a `#[Fillable([...])]` PHP attribute instead of `protected $fillable` — a new mutable column needs adding there too, or writes silently drop it.
- A background/event-driven write to a `Lead` column that must not touch `updated_at` uses a raw `DB::table('leads')->where(...)->update(...)`, never `$lead->save()` — otherwise it spuriously trips `Lead::hasUnseenUpdate()`'s "recently updated" highlight for every viewer.
- `Auth::user()` is typed `PlatformUser|TenantUser|null` throughout the app — a pre-existing, accepted PHPStan noise source, not something to "fix" in new code.
- Tests run on SQLite (`phpunit.xml`); production runs PostgreSQL — any DB-engine-specific SQL (`date_trunc`, JSON path operators, `percentile_cont`) needs a driver check or, better, a fully portable alternative (LIKE instead of JSON path, PHP-side computation instead of SQL-side).

## 2. Mandatory Development Workflow

Every Lead Management feature — no exceptions — follows this exact process:

```
INSPECT
→ IMPACT ANALYSIS
→ ARCHITECTURE / DECISION BRIEF
→ USER APPROVAL
→ IMPLEMENTATION
→ TESTING
→ REGRESSION
→ FINAL VERIFICATION
→ USER APPROVAL
→ UPDATE PROGRESS.md
→ CLOSE FEATURE
```

Rules:
- One feature at a time — never bundle multiple roadmap items into one change.
- Inspection before implementation — always. Never skip directly to coding a new feature.
- No speculative changes, no scope creep — build exactly what was approved, nothing more.
- Preserve all existing functionality — additive-only wherever possible.
- Do not touch a completed feature unless a real regression requires it.
- Do not update `PROGRESS.md` until final verification is complete and the user has explicitly approved closure.
- Never start the next feature before the current one is closed.
- When a scope/decision ambiguity or conflict surfaces mid-feature, explicitly flag it and present options — do not silently resolve it.

## 3. Feature Roadmap and Current Status

Exact current state, per `docs/lead-management/PROGRESS.md` §3 (authoritative — this table mirrors it):

| # | Feature | Status |
|---|---|---|
| 1 | Duplicate Detection + Lead Merge | COMPLETE / APPROVED |
| 2 | Lead Scoring Phase 2a | COMPLETE / APPROVED / CLOSED |
| 3 | Lead Tagging | COMPLETE / APPROVED |
| 4 | Deal / Opportunity | COMPLETE / APPROVED (Phase 1) |
| 5 | General Tasks / Reminders | COMPLETE / APPROVED (Phase 1) |
| 6 | Bulk Actions | COMPLETE / APPROVED / CLOSED (Phase 1) |
| 7 | CSV Import (+ Reverb Integration) | COMPLETE / APPROVED / CLOSED |
| 8 | Reporting & Analytics | COMPLETE / APPROVED / CLOSED (Phase 1) |
| 9 | Workflow / Nurturing | COMPLETE / APPROVED / CLOSED (Phase 1) |
| 10 | SLA / Response Tracking | **COMPLETE / APPROVED / CLOSED (Phase 1)** |
| 11 | Custom Fields | **NEXT / NOT STARTED** |
| 12 | Source Cost / ROI | Future |
| 13 | Website Form Builder | Future |
| 14 | Consent / GDPR | Future |
| 15 | In-App Communication | Intentionally deferred — do not build until explicitly requested |

Features #1, #3, #4, #5, #6 show slightly different exact wording in `PROGRESS.md` than a plain "CLOSED" (e.g. "COMPLETE / APPROVED" without "CLOSED" for #1/#3, "(Phase 1)" qualifiers for #4/#5/#6) — that wording is intentional and authoritative; don't normalize it away.

## 4. Feature #10 — Final Closed State

**Feature #10 — SLA / Response Tracking Phase 1** — CLOSED. Full detail in `PROGRESS.md` §14.

**Scope**: first-response-time measurement only, read-only reporting, live-computed, added to the existing `/leads/reports` page (Feature #8). Explicitly excludes: SLA targets, deadlines, breach tracking/persistence/alerts, any new scheduler or queue job, a new response-capture event, assignment-response metric, stage-duration metric.

**Response signal**: the existing `Lead.first_contacted_at` column, used exactly as it already behaves — set the first time a lead transitions away from the tenant's initial status (in `Leads\Show::updateStatus()` and its two bulk-action equivalents in `Leads\Index.php`). Feature #10 does not write to this column and does not invent a second definition of "first response."

**Metrics** (four stat tiles): Responded, Not Responded, Average First Response Time, Median First Response Time — durations in hours.

**Key semantics**:
- Reporting window = lead `created_at`, matching Feature #8's exact boundaries: `created_at >= startOfDay(from)`, `created_at < startOfDay(to + 1 day)`.
- A response landing after the window closes still counts, with its full actual duration — never clipped to the window boundary.
- Closed leads are **included**, not excluded.
- A lead with no `first_contacted_at` is Not Responded, regardless of closed status.
- Not-Responded leads are excluded from average/median.
- Merged-away leads (`merged_into_lead_id` not null) are excluded.
- Tenant isolation is explicit (`tenant_id` filter), not relied on via `BelongsToTenant` alone.
- Client Admin only — same gate as the rest of the reports page (`Gate::authorize('viewAny', Lead::class)` + `isClientAdmin()`), no new `PermissionSlug`.
- Average/median are `null` — never `0` — when no lead in the window has a response; the UI renders "No data," never "0 hours."

**Implementation files** (exactly these four, plus this closure's `PROGRESS.md` update):
- `app/Services/LeadReportingService.php` — `firstResponseSummary()` method added (one query, `whereNotNull('first_contacted_at')` on the existing `baseQuery()`, `get(['created_at', 'first_contacted_at'])`, average/median computed in PHP).
- `app/Livewire/Tenant/LeadReports/Index.php` — `#[Computed] firstResponseSummary()` wrapper; derives `not_responded_count` from the already-computed `volumeCount()` rather than a second query.
- `resources/views/livewire/tenant/lead-reports.blade.php` — 4-tile stat block inserted after "Lead Volume," before "Daily Lead Volume," plus a one-line note on the creation-date-window semantics.
- `tests/Feature/Leads/LeadResponseTimeReportingTest.php` — 14 new tests.

**Test/quality results** (final verification pass):
- `LeadResponseTimeReportingTest`: 14/14 passed.
- `LeadReportingTest` (Feature #8, re-run unmodified): 17/17 passed.
- `tests/Feature/Leads` + `Notifications` + `Broadcasting`: 765/768 passed.
- Full application suite: 877/880 passed.
- 2 known pre-existing `LeadsIndexListenerTest` failures (broadcasting listener name mismatch — `'$refresh'` expected vs. actual `onLeadReceived`/`onLeadUpdated`), confirmed unrelated (that test targets `Leads\Index.php`, a file Feature #10 never touches).
- 1 known pre-existing skip.
- Pint: clean.
- PHPStan: 356 vs. the Feature #9 baseline of 352 — the +4 traced individually to already-accepted categories already present throughout `LeadReports\Index.php` (Livewire `#[Computed]` magic-property access, `Auth::user(): PlatformUser|TenantUser|null` union-type noise). No new error category, no suppressions, no `@phpstan-ignore` comments.

`PROGRESS.md` was updated **only after** final verification passed and the user gave explicit closure approval — not before.

## 5. Feature #7–#10 Important Technical Context

**Feature #7 — CSV Import (+ Reverb Integration)**: max 10 MB / 10,000 rows, rejected outright (never truncated) before queuing. Row-level partial success. Duplicate detection (existing-Lead + in-file) never merges/overwrites. Queued + chunked via Laravel Excel's `ShouldQueue`/`WithChunkReading` (real job chaining, not `Bus::batch()`). `LeadReceived`/`LeadUpdated` never fire for an imported row. Real-time import status uses **Reverb as primary** (`App\Events\LeadImportStatusChanged`, `PrivateChannel('lead-import.{id}')`) plus a **single one-time** Alpine `setTimeout` reconciliation at 5s (not `wire:poll`, which was found during browser QA to throttle heavily in a backgrounded tab). Full detail in `docs/lead-management/FEATURE-07-CSV-IMPORT-HANDOFF.md`.

**Feature #8 — Reporting & Analytics**: `App\Services\LeadReportingService` — one method per metric, each building its own tenant-scoped, merge-excluded query from scratch (`baseQuery()`). Metrics: `volumeCount`, `dailyVolumeBreakdown` (zero-filled, driver-aware `date_trunc`/SQLite `date()`), `statusBreakdown` (delegates to `Lead::countsByStatus()`), `sourceBreakdown` (inactive sources still included, badged), `assignmentBreakdown` (explicit "Unassigned" bucket, queried separately to avoid NULL-key `GROUP BY` ambiguity). Date range: last-30-days default, 92-day max, rejected not truncated. `leads(tenant_id, created_at)` composite index added for this feature — Feature #10 reuses it, no new index needed. Client Admin only, `/leads/reports`.

**Feature #9 — Workflow / Nurturing**: `App\Console\Commands\NurtureInactiveLeads` (`leads:nurture-inactive-leads`, daily 08:00, `withoutOverlapping()`). One condition (`LeadScoringField::DaysSinceLastStatusChange`, reused unmodified), two independently-toggleable actions (notify, create follow-up task). New tenant settings: `lead_nurturing_notify_enabled`, `lead_nurturing_task_enabled`, `lead_nurturing_inactive_days`. Idempotency via same-day existence checks; the task action's dedup column `leads.nurturing_task_created_at` is deliberately excluded from `Lead`'s `#[Fillable]` (same treatment as `lead_score`) and written via raw query builder update.

**`App\Enums\LeadScoringField` / `LeadScoringOperator`**: a reusable, generic condition-evaluation engine (field resolution + operator evaluation) originally built for Feature #2 (Lead Scoring), reused as-is by Feature #9's nurturing condition. Treat as a stable, general-purpose primitive — do not modify it casually for a new feature; extend it only if a real new field/operator is needed, and check who else depends on it first.

**Existing tenant authorization pattern for Lead Management sub-pages** (Scoring Rules, Tags, Reports, and now SLA reporting all follow it): no new `PermissionSlug`, inline guard in `mount()` — `Gate::authorize('viewAny'|'update'|'delete', Lead::class)` (or the parent Lead for a child resource) followed by `abort_unless(Auth::user()->isClientAdmin(), 403)` where the capability is admin-only. `PermissionSlug` itself is a **fixed, global 4-value enum** (View/Create/Update/Delete) — not extensible per-module without a much larger cross-cutting change, so new capabilities are layered onto the existing 4, not given their own slug.

**Known technical debt** (see `PROGRESS.md` §15 for the full list, one bullet per feature's deferred items):
- **Confirmed, pre-existing, NOT fixed**: `App\Console\Commands\SendLeadAlertNotifications::alreadyNotifiedToday()` and `SendTaskAlertNotifications::alreadyNotifiedToday()` both query `->where('data->lead_id'/'data->task_id', ...)` — a JSON-path operator against `notifications.data`, which is a plain `text` column (Laravel's default notifications migration), not `json`/`jsonb`. Confirmed to throw `SQLSTATE[42883]: operator does not exist: text ->> unknown` against real Postgres every time (SQLite, the test driver, doesn't catch this, which is why the test suite never has). Explicitly left untouched per direct instruction across Features #9 and #10 — recommended as its own separate, explicitly-scoped future fix. **Do not copy this JSON-path pattern into any new code**; `NurtureInactiveLeads::alreadyNotifiedToday()` already demonstrates the portable `LIKE`-based alternative.
- Duplicate-detection query performance (Feature #1) and merge budget/currency pairing (Feature #1) remain deferred.
- PHPStan baseline has grown feature-by-feature (258 → … → 352 → 356) — every increment individually traced to already-accepted categories (`Auth::user()` union type, Livewire `#[Computed]` magic-property access, pre-existing generics gaps). This is documented, accepted debt, not something to "clean up" incidentally while building a new feature.
- The 2 `LeadsIndexListenerTest` broadcasting failures and 1 skip have been present and unchanged since Feature #2's closure — always re-verify they're still exactly these 2/1 before attributing any new failure to a new feature.

## 6. Feature #11 — Custom Fields

**Status: NEXT / NOT STARTED.**

**No Feature #11 implementation has been approved.** No code, migration, model, Livewire component, or test for Custom Fields exists anywhere in this repository as of this handoff.

The next session must begin with **INSPECTION ONLY** — do not choose a data model before repository inspection, do not write any code.

The inspection should investigate:
- Lead schema (existing columns, what's already flexible vs. rigid)
- JSON/JSONB/dynamic-metadata precedent anywhere in this codebase
- Existing custom-attribute or configurable-field patterns elsewhere in the app (e.g. how `LeadStatus`/`Currency`/`LeadTag` catalogs are modeled — shared catalog vs. tenant-owned — is directly relevant prior art)
- Lead creation/update paths (manual creation, webhook capture, CSV import, merge)
- Livewire forms currently used for Lead create/edit
- Validation architecture
- Bulk Actions (Feature #6) — would custom fields need a bulk-edit path?
- CSV Import (Feature #7) — would custom fields need import-column support?
- Duplicate merge (Feature #1) — how would a custom field value be resolved on merge?
- Reporting/filtering (Feature #8) — would custom fields need to be filterable/reportable?
- Tenant isolation requirements
- Client Admin vs. Client User permission boundaries for defining vs. filling in custom fields
- Possible data-model approaches (EAV table, JSON/JSONB column, per-tenant dynamic schema, etc.) and their tradeoffs specifically in this repository's context (Postgres in production, SQLite in tests — JSONB portability is a real concern, same class of issue Feature #8/#9/#10 already had to navigate)
- Field types to support, validation architecture per type
- Performance/index implications
- Audit/history requirements
- UI placement
- Existing testing patterns to follow
- Future reporting/import implications

The inspection must produce a Decision Brief covering:

```
A. Existing infrastructure
B. Reusable patterns
C. Missing infrastructure
D. Data-model options
E. Pros/cons based on this repository
F. Tenant isolation
G. Authorization
H. Validation
I. Create/edit/import/merge/reporting impact
J. Performance/index considerations
K. Audit/history
L. Security risks
M. Scope risks
N. Recommended Phase 1 scope
O. Deferred items
P. Open product questions
```

Then **WAIT FOR EXPLICIT USER APPROVAL** before any Architecture Proposal, and wait again before any implementation — per the mandatory workflow in §2.

## 7. How to Start the Next Session

1. Read `docs/lead-management/PROGRESS.md` in full.
2. Read this file, `docs/lead-management/CLAUDE_HANDOFF.md`, in full.
3. Run `git status` and `git log -5 --oneline` to confirm the actual current repository state.
4. Confirm current branch (`git branch --show-current`).
5. Do **not** implement anything yet.
6. Begin Feature #11 inspection only, per §6 above.

**If this handoff document and `PROGRESS.md` ever conflict**: inspect the actual repository code and treat the real current code state plus the latest `PROGRESS.md` content as authoritative over this document. Do not guess which is right — report the conflict to the user before proceeding with any implementation.

## 8. Commit / Branch / Git Safety

At the time this handoff was written:
- **Branch**: `main`
- **Remote**: `origin` → `git@github.com:rashedbishal/OptiFin-Enterprise-Resource-Management-System.git`, branch up to date with `origin/main`.
- **Recent commits on `main`** (most recent first): `bbb9069` "Add Lead Management development progress handoff doc", `f9ae290` "Add Lead duplicate detection/merge and rule-based Lead Scoring", `6ffe4d5` "Document Exports and audit-logging conventions in CLAUDE.md", `fe55c9d` "Fix stale ?lead= URL after closing the Kanban lead detail flyout", `a3c9b3a` "Show a Website Form lead's arbitrary extra fields on its detail page".
- **Working tree state**: substantial uncommitted work sits in the tree — the implementation for Features #3 through #10 (CSV Import, Reporting, Nurturing, SLA tracking, Deals, Tasks, Bulk Actions, Lead Tagging, and their supporting migrations/tests/docs) has **not yet been committed to git**, despite being functionally complete and closed per `PROGRESS.md`. This is a pre-existing condition of this repository as of this handoff, not something this handoff session introduced or resolved. A future session should not assume "closed in `PROGRESS.md`" means "committed" — verify with `git status` before doing anything that could discard uncommitted work (`git checkout`, `git reset --hard`, `git clean`, etc.), and do not run those commands without explicit user instruction.

This handoff document's own commit was created as an isolated, minimal commit containing **only** `docs/lead-management/CLAUDE_HANDOFF.md` — it does not commit, stage, or otherwise touch any of the pre-existing uncommitted Feature #3–#10 work described above.
