# Lead Management — Session Handoff

This document exists so a fresh Claude CLI session can pick up Lead Management work tomorrow without relying on today's (or any prior day's) conversation history. It is a snapshot as of the end of the session that last updated it — **always verify against the actual current repository state before acting on anything here** (see §7). This file is fully rewritten at the end of each working session, unlike `docs/lead-management/PROGRESS.md`, which is append-only historical record.

## 1. Current Repository State

- **Branch**: `main`
- **HEAD commit**: `6697a597a5503df4de5ac82a1fdf197d525117db`
- **HEAD == origin/main**: confirmed at end of session.
- **Working tree**: clean, no untracked files.

## 2. Project / Architecture Context (read `CLAUDE.md` first — this is only a summary)

A multi-tenant SaaS ERP built on the Laravel Livewire starter kit (Laravel 13, PHP 8.3, Livewire 4, Flux UI 2, Tailwind v4), with a Platform side (`/admin/*`) and a Tenant side (`/*`), plus a Lead Management module ported from a sibling project. Dual-guard auth (`PlatformUser`/`TenantUser`, never a generic `User`), all IDs are ULIDs. **Before editing anything under `app/` or `resources/views/livewire/`, read `.ai/rules/index.md` and every rule file whose glob matches the path in scope** — these are settled decisions and traps, not optional background.

Load-bearing conventions this session's work relied on repeatedly:
- `App\Concerns\BelongsToTenant`'s global scope only activates when `app()->bound('currentTenant')` — every query in webhook/job/command contexts must filter `tenant_id` explicitly.
- A background/event-driven write to a `Lead` column that must not touch `updated_at` uses a raw query builder update (`DB::table('leads')->where(...)->update(...)`), never `$lead->save()`.
- `queue.default = database`, and the queue connection resolves to the app's own default DB connection (no `DB_QUEUE_CONNECTION` override) — this means a queued job/broadcast row inserted while inside an open `DB::transaction()` is invisible to any other connection until that transaction actually commits, which is why several of this session's atomicity fixes fire their events **from inside** the transaction rather than deferring via `DB::afterCommit()` (verified directly against Laravel's transaction-manager source and against the real PostgreSQL connection, not assumed).
- `DB::transaction()` nests safely via SAVEPOINTs — a method that opens its own transaction can be safely called from inside another already-open transaction (already relied on in production by `Index::applyBulkAssign()`/`applyBulkStatusChange()` before this session started, and now also by `LeadAssignmentService::autoAssign()` and `LeadScoringService::computeFor()`'s documented nesting).
- This app's real dev database is PostgreSQL (`optifin_erp`, reachable via `.env`'s `DB_CONNECTION=pgsql`), while the automated test suite runs on SQLite (`phpunit.xml`) — SQLite silently tolerates some things Postgres rejects (JSON-path operators against `text` columns) and drops `FOR UPDATE` entirely, which is why several fixes this session required a one-off manual verification against the real `pgsql` connection in addition to the automated SQLite-based test suite, following a precedent already established for M8's `lockForUpdate()` fix.
- Production-safety expectation established and repeatedly reinforced this session: **a rollback/atomicity test must be empirically verified to fail against the unfixed code and pass against the fixed code, not merely assumed correct because a similar test worked elsewhere.** Twice this session (`Index::moveLead()`'s original test draft, and the general principle checked again for `Show::applyDetailChanges()`), the "obvious" reuse of an established test technique was checked this way before being trusted — once it revealed the reused technique was actually a tautology (passed against both fixed and unfixed code) and had to be redesigned. Continue this discipline for every future atomicity/rollback test.

## 3. Roadmap Status

All 15 planned Lead Management features (§3 of `PROGRESS.md`) are **COMPLETE / APPROVED / CLOSED**. **There is no Feature #16.** Do not begin any new roadmap feature without it being explicitly requested, and do not reopen any of Features #1–#15 unless a genuine regression is discovered.

## 4. Latest Completed Work: Show::applyDetailChanges() — Lead + LeadDetailUpdateLog Atomicity

**Not a new roadmap feature, and not itself an independently-numbered forensic finding** — a separate, distinct issue discovered during the original M3 closure's own work (`LeadScoringService::computeFor()` score/score-log atomicity) and explicitly left unfixed there. Tracked and closed under this exact descriptive name in `PROGRESS.md` §23.

**Original problem**: `Show::applyDetailChanges()` (shared by `updateContact()`/`updateCompany()`/`updateDeal()`) performed `$this->lead->save()` and `LeadDetailUpdateLog::create()` as two separate, unguarded statements with no transaction — the same bug class already closed for M2's original three `Show.php` locations and for `Index::moveLead()`, but never named in either of those findings' own text. A failure between the two writes could leave a lead's contact/company/deal-field change persisted with no matching audit-log row.

**Fix implemented**: the lead save, the `LeadDetailUpdateLog` write, the conditional `LeadScoringService::computeFor()` recompute (+ `$this->lead->refresh()` + `unset($this->latestScoreLog)`), and `event(new LeadUpdated($this->lead))` are now wrapped in one `DB::transaction()` — mirroring the identical boundary already shipped for this same shape in `Show::updateStatus()`. `unset($this->activity)`, the panel dispatch, and `Flux::toast()` remain **outside** the transaction, unchanged. `LeadScoringService::computeFor()` needed zero changes — its own docblock (from its earlier M3 fix) already documents `Show::updateStatus()` nesting inside an open transaction exactly this way. `updateContact()`, `updateCompany()`, `updateDeal()`, and `notifyIfDateScheduled()` were not modified.

**Rollback test — empirically verified in both directions, not assumed**: `applyDetailChanges()`'s write order (save-then-log) matches `Show::updateStatus()`'s own already-fixed order, so the established never-persisted-`TenantUser` FK-violation technique (`LeadDetailUpdateLog.changed_by`'s foreign key to `tenant_users`) applied directly — the second write is what's made to fail, strictly after the first (`$this->lead->save()`) has already run.
- Run against the **unfixed** implementation (production fix temporarily stashed): the test **failed** — the lead's `name` became `'New Name'` even though the call threw, proving the contact-detail change leaked through with no rollback.
- Run against the **fixed** implementation: the test **passed**, 5/5 assertions — a genuine, deterministic atomicity proof, not a tautology.

**Verification results**:
- `LeadDetailEditingTest.php`: 26/26 passed (25 pre-existing + 1 new), 91 assertions.
- `LeadScoringTriggersTest.php`: 12/12. `LeadCustomFieldEditTest.php`: 9/9. `LeadMeetingFollowUpAlertActionsTest.php`: 18/18. `LeadDetailPanelTest.php`: 5/5.
- Full `tests/Feature/Leads/`: **943 passed, 1 pre-existing skip, 0 failures**, reproduced across multiple runs.
- Pint: clean. PHPStan: **425 errors** — exact match to the established baseline, zero new errors in either changed file (independently confirmed via a raw non-truncated run).
- `git diff --check`: clean.

**Commit**: `6697a597a5503df4de5ac82a1fdf197d525117db` — pushed to `origin/main` (`233693c..6697a59 main -> main`), HEAD == origin/main confirmed, working tree clean at close.

## 5. Prior Completed Work This Session (chronological, all CLOSED — do not reopen without a real regression)

| Order | Item | Commit |
|---|---|---|
| 1 | M5 — `Leads\Index::export()` unbounded/unpaginated CSV export (chunked via `chunkById(200)` + mandatory re-select-free single-execution, inside `streamDownload()`) | `e108bb1` |
| 2 | SendTaskAlertNotifications PostgreSQL JSON-path compatibility fix (not an independently-numbered finding — `data->task_id` → portable `LIKE` match, mirroring the already-fixed `SendLeadAlertNotifications`/`NurtureInactiveLeads` pattern) | `21122b5` |
| 3 | M1 — LeadAssignmentService fairness race (round-robin/least-loaded auto-assignment selection under concurrency): `lockForUpdate()` + a mandatory **second** execution of the ranking query after the lock (empirically proven necessary — a single `lockForUpdate()` + `ORDER BY ... LIMIT 1` does NOT auto-advance to the next candidate after unblocking, verified against real PostgreSQL), `assign()`'s `LeadAssigned` dispatch moved to `DB::afterCommit()`, `attempts: 3` for deadlock retry | `a3737f8` |
| 4 | M2 (separate finding) — `Index::moveLead()` status + `LeadStatusLog` atomicity — the rollback test required a redesign after the standard technique was proven (empirically) to be a tautology here, since this method's write order is reversed (log-then-update) vs. the other M2 locations' save-then-log | `233693c` |
| 5 | Show::applyDetailChanges() — Lead + LeadDetailUpdateLog atomicity (this session's final closure — see §4) | `6697a59` |

`M1`'s **atomicity** sub-problem, the original **M2** (`Show.php`'s three locations), **M3** (`LeadScoringService::computeFor()`), **M4** (duplicate-detection query optimization), the **Meta access-token queue-serialization** issue (C5), the **Facebook Lead Ads XLSX formula-injection** issue (C6), and the **concurrent-merge race** (M8) were all closed in earlier sessions, before today — see `PROGRESS.md` §23 for their full closure records. None of these were touched this session.

## 6. Open Findings — source of truth is `PROGRESS.md` §23's own rollup line, reproduced verbatim below; do not reorder or reinterpret it

> C5 — CLOSED. C1 — CLOSED. C2 — CLOSED. C2 FOLLOW-UP — CLOSED. C3 — CLOSED. C4 — CLOSED. C6 — CLOSED. M8 — CLOSED. M1 (atomicity AND fairness race) — fully CLOSED. M2 (`Show.php`, 3 locations) — CLOSED. M2 — `Index::moveLead()` (separate finding) — CLOSED. M3 — CLOSED. Show::applyDetailChanges() (separate finding) — CLOSED. M4 — CLOSED; **`CreateLeadFromImport.php`'s narrower duplicate-matching inconsistency and `DetectDuplicateLeads`'s unscheduled status both remain separate OPEN observations, not resolved by M4.** M5 — CLOSED. SendTaskAlertNotifications fix — CLOSED. **DE1 (permanently undeletable LeadSource with conversation history), the `RecordInboundMessage` Conversation/Message identity-mismatch gap, and the `messageTimeline()` blended-cutover edge case remain explicitly deferred pending separate decisions — not implemented, not scheduled. No other audit finding (M6–M7, M9–M10, L1–L4) has been approved for implementation.**

**Confirmed-open items with real, documented finding text already in `PROGRESS.md`**:
- `CreateLeadFromImport::checkExistingLeadDuplicate()`'s narrower, non-normalized-aware duplicate check (discovered during M4's work).
- `DetectDuplicateLeads` (`leads:detect-duplicates`) confirmed **not scheduled** anywhere in `routes/console.php` (discovered during M4's work).
- `RecordInboundMessage`'s `Conversation` lookup identity-mismatch gap (discovered during C2 FOLLOW-UP's work).
- `messageTimeline()`'s blended-cutover edge case (discovered during C2's work; accepted technical debt).
- **DE1** — permanently undeletable `LeadSource` with conversation history — explicitly requires a **product decision** (data-retention/archiving policy) before any implementation; not a pure code fix.

**M6, M7, M9, M10, L1, L2, L3, L4 — important epistemic caveat, unchanged from every prior session's documentation**: these IDs are named in `PROGRESS.md`/this file with **no finding text ever supplied by the user to any session** — the original forensic audit report was never persisted as a file in this repo. **Do not reconstruct or guess their content from the ID alone.** In a prior session-internal prioritization exercise (not an approved implementation pass), several of these were independently code-verified against the current repository as a sanity check when a user message supplied descriptions for them, with these results (informational only, not an official finding record until the user confirms/re-supplies the finding text and it goes through the full workflow):
  - **M7-shaped** (tenant-side `Index::deleteSource()` check-then-act race, mirroring the already-closed C1 platform-side equivalent): confirmed present in code (`findOrFail()` → `leads()->exists()`/`costs()->exists()` checks → `delete()`, no transaction/lock).
  - **M9-shaped** (manual/CSV leads never fire `LeadReceived`, so they skip auto-distribution and the "new lead" admin notification): confirmed present; the CSV half is **explicitly by design** (own code comment: avoids notification fan-out during bulk import) — the manual-lead half looks like a genuine, narrower gap, but this needs a **product decision** (should manual leads participate in auto-distribution/notifications?) before implementation.
  - **M10-shaped** (`LeadReportingService::sourceBreakdown()`'s two unguarded queries): confirmed present, low severity, narrow window.
  - **L1-shaped** (raw WhatsApp inbound webhook payload — full request body + headers, including message text and phone numbers — logged unconditionally to `storage/logs/whatsapp.log`, a `'single'`-driver channel that is **never rotated or truncated**): confirmed present, flagged as the most consequential of the un-sourced items (indefinite plaintext PII retention + unbounded disk growth), notable given this app already ships a dedicated Consent/GDPR feature. Needs at least a light decision on redaction scope before implementation.
  - **L4-shaped** (enum-backed `$table->enum()` columns compiling to fragile Postgres CHECK constraints): confirmed real and well-evidenced — this exact class of problem already required 5 separate follow-up migrations for `LeadSourceType` alone, and is why `LeadStatus` was migrated to a catalog-table pattern. Architectural/process risk, not a currently-active bug.
  - **L2-shaped** (webhook payload-size limits) and **L3-shaped** (unassigned-lead nullsafe access): L3 was checked and appears **already safe everywhere** (all Blade accesses use `?->`; the one `??`-based access is PHP-safe through the whole property chain) — likely not a real gap. L2 has weak/no evidence beyond "no bespoke app-level limit exists," which is standard practice for most Laravel webhook endpoints, not clearly a gap.

**The next finding has not been selected.** Do not assume M6/M7/M9/M10/L1–L4, or any specific item above, is "next by default" — the next session must run its own INSPECT + prioritization pass (weighing production/business impact, frequency, data-integrity/security risk, performance impact, ease/safety of remediation, whether a product decision is required, existing precedent, and blast radius) against the then-current codebase before selecting and briefing the next item, exactly as was done for each of this session's five closures.

## 7. Required Workflow — unchanged, apply to every future finding without exception

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
  → COMMIT
  → PUSH
  → CLOSE FINDING
```

- **One finding at a time** — never bundle two findings' fixes into one implementation pass, even when their fix shape is nearly identical (this was explicitly reaffirmed this session when `Index::moveLead()` and `Show::applyDetailChanges()` — both textbook copies of the same transaction pattern — were still closed as two fully separate passes).
- **No scope creep** — an adjacent issue discovered mid-work gets named as a separate, out-of-scope observation in the closure text, never silently folded into the current finding.
- **Do not touch a completed/closed finding or feature** unless a genuine regression requires it.
- **Do not update `PROGRESS.md`'s closure text for a finding** before implementation, testing, regression, final verification, and explicit user closure approval are all complete.
- **Do not start the next finding** until the current one is fully closed (implemented, verified, documented, committed, pushed) and the user has explicitly said to proceed.
- **Always verify the actual current codebase** before trusting this document, `PROGRESS.md`, or any prior session's stated assumptions — re-read the relevant file(s) directly; do not reconstruct a finding's substance from an ID alone.
- **Verify rollback/atomicity tests empirically in both directions** (fails against unfixed code, passes against fixed code) before trusting a reused test technique, per §2's note above.

## 8. Tomorrow's Starting Instruction

Tomorrow, start by reading:
- `docs/lead-management/PROGRESS.md`
- `docs/lead-management/SESSION_HANDOFF.md` (this file)
- `CLAUDE.md`
- the relevant `.ai/rules/` files for whatever path ends up in scope

Then verify the actual current git state (`git status`, `git log`, `HEAD` vs `origin/main`) before trusting anything above. Only then perform a fresh INSPECT + prioritization pass over the open findings in §6 to select the next item — do not assume the ordering above is still correct, and do not begin implementing anything until that finding's architecture/decision brief has been explicitly approved.
