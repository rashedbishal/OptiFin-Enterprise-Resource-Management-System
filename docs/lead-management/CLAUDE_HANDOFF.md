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
| 1 | Duplicate Detection + Lead Merge | COMPLETE / APPROVED / CLOSED |
| 2 | Lead Scoring Phase 2a | COMPLETE / APPROVED / CLOSED |
| 3 | Lead Tagging | COMPLETE / APPROVED / CLOSED |
| 4 | Deal / Opportunity | COMPLETE / APPROVED / CLOSED (Phase 1) |
| 5 | General Tasks / Reminders | COMPLETE / APPROVED / CLOSED (Phase 1) |
| 6 | Bulk Actions | COMPLETE / APPROVED / CLOSED (Phase 1) |
| 7 | CSV Import (+ Reverb Integration) | COMPLETE / APPROVED / CLOSED |
| 8 | Reporting & Analytics | COMPLETE / APPROVED / CLOSED (Phase 1) |
| 9 | Workflow / Nurturing | COMPLETE / APPROVED / CLOSED (Phase 1) |
| 10 | SLA / Response Tracking | COMPLETE / APPROVED / CLOSED (Phase 1) |
| 11 | Custom Fields | COMPLETE / APPROVED / CLOSED (Phase 1) |
| 12 | Source Cost / ROI | COMPLETE / APPROVED / CLOSED (Phase 1) |
| 13 | Website Form Builder | COMPLETE / APPROVED / CLOSED (Phase 1) |
| 14 | Consent / GDPR | COMPLETE / APPROVED / CLOSED (Phase 1) |
| 15 | In-App Communication | **COMPLETE / APPROVED / CLOSED (Phase 1)** |

**There is no Feature #16 on the approved roadmap.** All 15 features are closed. Do not invent or begin one without an explicit user request, and do not skip the mandatory Inspect → Architecture → Approval sequence in §2 even for a request that "sounds obviously useful."

## 4. Feature #15 — Final Closed State (last completed feature)

**Feature #15 — In-App Communication Phase 1** — CLOSED. Full detail in `PROGRESS.md` §19; do not duplicate that prose here — this is a pointer, not a replacement.

**Scope**: normalized `conversations`/`messages` tables with additive inbound dual-write (legacy `Lead.source_data` JSON untouched); WhatsApp/Messenger/Instagram outbound sending via a per-channel `MessageSenderInterface` implementation; 24-hour messaging-window + Privacy-consent (Feature #14) eligibility gate; queued sending (`SendOutboundMessage`) with same-row status updates and manual-resend-only on confirmed failure; webhook-driven, monotonic status reconciliation (`ReconcileOutboundMessageStatus`) added to the existing always-200 webhook handlers; a lead-detail/Kanban-flyout composer card; 30/user/min + 100/tenant/min rate limiting via `Illuminate\Cache\RateLimiter` inside the Livewire component (an accepted, explicitly-approved deviation from route-level throttle middleware). Templates, proactive/marketing messaging, and a unified inbox are explicitly deferred (full list in `PROGRESS.md` §19).

**Two real issues were found and fixed during the Final Verification Audit** (before closure): (1) `Lead::messageTimeline()` could silently hide pre-existing legacy message history once a lead received its first post-cutover dual-written message — fixed with a per-entry legacy/normalized timestamp boundary. (2) `ReconcileOutboundMessageStatus::applyStatus()` had a concurrent-write race that could downgrade an already-more-advanced status — fixed with `DB::transaction()` + `lockForUpdate()`. A **third**, same-category issue (`App\Support\SendResult`'s `$rawResponse` param missing a PHPDoc value type) was found post-closure during the 2026-09-12 end-of-day handoff's pre-commit PHPStan validation and fixed the same way — see `PROGRESS.md` §19's post-closure note.

**Final verification baseline** (after all three fixes): 1053 tests, 1050 passed, 2 pre-existing `LeadsIndexListenerTest` failures (unrelated — see §5 below), 1 pre-existing skip, zero new failures. Pint: clean. PHPStan: 425 project-wide, zero attributable to any Feature #15 file.

**Five documented residual risks, carried forward as technical debt, not blockers** (full detail in `PROGRESS.md` §19): timeout-after-platform-acceptance ambiguity (no idempotency key exposed by any of the three platforms); per-tenant Meta permission readiness is checked manually via `MetaPermissionsController`, not automated; Instagram's delivery/read-receipt reliability is platform-documented as weaker than Messenger's; no real browser/manual QA pass was performed (Livewire full-component-render tests only); a narrow, payload-malformation-only timestamp edge case in `messageTimeline()`'s legacy/normalized boundary.

`PROGRESS.md` was updated **only after** final verification passed and the user gave explicit closure approval — not before.

## 5. Feature #7–#10 Important Technical Context (historical — still accurate, not restated per-feature below)

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

## 6. No Active Feature — Wait for Explicit Instruction

**Features #1–#15 are all COMPLETE / APPROVED / CLOSED.** There is no Feature #16 and no other roadmap item currently approved or defined. Do not:
- Start any new feature, including a hypothetical "Feature #16," without it first being explicitly requested.
- Reopen Feature #15 (or any earlier closed feature) without a demonstrated real defect/regression — a residual risk documented in `PROGRESS.md` §19 is not itself grounds to reopen.
- Assume a feature should be built merely because it sounds useful or a natural extension.

If and when a new feature is explicitly requested, follow the exact same mandatory workflow every prior feature followed (§2 above): begin with **INSPECTION ONLY** of the actual current repository/schema/UI/permission state — do not choose a data model or approach before inspecting — then produce a Decision Brief, then **WAIT FOR EXPLICIT USER APPROVAL** before any Architecture Proposal, and wait again before any implementation.

## 7. How to Start the Next Session

1. Read `docs/lead-management/PROGRESS.md` in full — it is the authoritative, continuously-updated state.
2. Read this file, `docs/lead-management/CLAUDE_HANDOFF.md`, in full.
3. Run `git status` and `git log -5 --oneline` to confirm the actual current repository state (should be clean, `main` up to date with `origin/main`, immediately after the commit that closed Feature #15).
4. Confirm current branch (`git branch --show-current`).
5. Do **not** implement anything yet — there is no approved next feature (see §6).
6. Wait for the user to explicitly name the next feature, then begin its inspection per §2/§6.

**If this handoff document and `PROGRESS.md` ever conflict**: inspect the actual repository code and treat the real current code state plus the latest `PROGRESS.md` content as authoritative over this document. Do not guess which is right — report the conflict to the user before proceeding with any implementation.

## 8. Commit / Branch / Git Safety

As of the 2026-09-12 end-of-day handoff commit (which closes Feature #15):
- **Branch**: `main`.
- **Remote**: `origin` → `git@github.com:rashedbishal/OptiFin-Enterprise-Resource-Management-System.git`.
- **Working tree**: clean immediately after that commit was pushed — run `git log -1 --oneline` and `git status --short` to confirm the exact current commit hash and tree state, since this document is not updated per-commit.
- That commit contains all of Feature #15's implementation, migrations, tests, the `PROGRESS.md` closure update, and this file's own update — nothing from any other in-progress or unrelated work.

Before running any command that could discard uncommitted work (`git checkout`, `git reset --hard`, `git clean`, etc.), always verify with `git status` first and never run those without explicit user instruction.
