# Lead Management — Session Handoff

This document exists so a fresh Claude CLI session can pick up Lead Management work without relying on any prior conversation history. It is a snapshot as of the end of the session that last updated it — **always verify against the actual current repository state before acting on anything here** (see §11). This file is fully rewritten at the end of each working session, unlike `docs/lead-management/PROGRESS.md`, which is append-only historical record.

**Staleness warning, reaffirmed every session**: a prior version of this file was found stale by 7 additional closures the next time it was checked. Treat every claim below — HEAD commit, closure list, "next step" — as a snapshot that must be independently re-verified against `git log`/`git status`/`PROGRESS.md`, never trusted at face value, no matter how recently it claims to have been written.

## 1. Project / Workflow Rules — unchanged, apply to every future finding without exception

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

- **No implementation before explicit user approval of the Architecture / Decision Brief.**
- **No `PROGRESS.md` closure update before Final Verification has passed AND the user has given explicit closure approval.**
- **No scope creep** — an adjacent issue discovered mid-work gets named as a separate, out-of-scope observation in the closure text, never silently folded into the current finding.
- **One finding at a time** — never bundle two findings' fixes into one implementation pass, even when their fix shape is nearly identical (every TOCTOU-family fix and every deletable-source-type-family fix in §4/§5 below, though structurally similar, was closed as its own fully separate pass).
- **Never reopen a closed finding or feature** unless a genuine regression is proven against current code — not merely because an older audit mentions it.
- **No Feature #16.** All 15 planned Lead Management features are permanently CLOSED; do not begin any new roadmap feature without it being explicitly requested.
- **Always verify the actual current codebase before trusting this document, `PROGRESS.md`, or any prior session's stated assumptions** — re-read the relevant file(s) directly; do not reconstruct a finding's substance from an ID alone.
- **Verify rollback/atomicity/fail-then-pass tests empirically in both directions** (genuinely fails against unfixed code, genuinely passes against fixed code) before trusting a reused test technique — this has caught real problems more than once in this module's history (see §9).

## 2. Current Repository State

- **Branch**: `main`
- **HEAD commit**: `72c764477cf9a07bb0729e3059569cd8bd3272fb`
- **HEAD == origin/main**: confirmed via `git fetch origin main` + `git rev-parse HEAD`/`git rev-parse origin/main` — both equal, independently re-verified at the moment this handoff was written.
- **`git status --short`**: clean, with exactly one exception — `docs/lead-management/SESSION_HANDOFF.md` itself, i.e. this file, shown as modified because this handoff-preparation pass is actively rewriting it. Once this write completes and is reviewed, that is the only intentional change in the working tree from this pass.
- **Important repository constraint**: this file (`SESSION_HANDOFF.md`) is deliberately **not committed** by the session that writes it, per this module's established convention — it is left as an uncommitted, working-tree-only document for the next session to read, and is only committed/pushed if a future session is explicitly told to do so. Do not assume it needs to be part of any commit.
- No other untracked or unexpected files were present at the time of this inspection.

## 3. Completed Roadmap (from `PROGRESS.md`, not reconstructed from memory)

All 15 planned Lead Management features (`PROGRESS.md` §3) are **COMPLETE / APPROVED / CLOSED**. **There is no Feature #16.**

Following Feature #15's closure, a full forensic audit of the entire Lead Management module was conducted (IDs C1–C6, M1–M10, L1–L4, plus QA/deferred items). Per `PROGRESS.md`'s own rollup paragraph (the authoritative source of truth — see §6), the following are **CLOSED**: C1, C2, C2 FOLLOW-UP, C3, C4, C5, C6, M8, M1 (both the atomicity and fairness-race sub-problems, fully merged), M2 (both the original `Show.php` finding and the separate `Index::moveLead()` finding), M3 (both `LeadScoringService::computeFor()` and the separate `Show::applyDetailChanges()` finding), M4 (both the query-optimization finding and the separate `CreateLeadFromImport` duplicate-matching finding), M5, plus a long list of freshly-discovered (not independently numbered) findings: SendTaskAlertNotifications PostgreSQL JSON-path fix, tenant `Leads\Index::deleteSource()` TOCTOU race, RecordInboundMessage Conversation/Lead identity mismatch, unredacted WhatsApp webhook logging, `DetectDuplicateLeads` scheduling gap, Platform LeadManagement Settings `deleteStatus()`/`deleteCurrency()` TOCTOU race, the `LeadStatusLog` missing-dependent-check gap, Tenant `LeadCustomFields\Index::delete()` TOCTOU race, `LeadReportingService::sourceBreakdown()`'s check-then-fetch race (M10), `Leads\Index::export()`'s CSV/formula-injection vulnerability, and — this session's final closure — `Leads\Index::delete()`/`Leads\Show::delete()`'s missing deletable-source-type enforcement.

## 4. Latest Closed Findings (this session)

**a) `Leads\Index::export()` — CSV/Formula Injection via Unsanitized Name/Email**
- **Status**: COMPLETE / CLOSED.
- **Commit**: `ffc2b5af6e7abdf3495b263b94c7d5bbe3587720`.
- Full closure record: `PROGRESS.md` §23, section titled "Leads\Index::export() — CSV/Formula Injection via Unsanitized Name/Email".

**b) `Leads\Index::delete()` and `Leads\Show::delete()` — Missing Server-Side Enforcement of Deletable-Source-Type Restriction**
- **Status**: COMPLETE / CLOSED — the closure WAS committed and pushed this session, verified HEAD == origin/main == this commit.
- **Commit**: `72c764477cf9a07bb0729e3059569cd8bd3272fb` (current HEAD).
- Full closure record: `PROGRESS.md` §23, section titled "`Leads\Index::delete()` and `Leads\Show::delete()` — Missing Server-Side Enforcement of Deletable-Source-Type Restriction".

Both findings are fully closed: implemented, tested, final-verified, `PROGRESS.md` updated, committed, and pushed. Do not reopen either without a genuine, freshly-proven regression.

## 5. Current Open State

- **No finding is currently in progress.** The most recent finding (§4b) completed the full workflow through closure, commit, and push.
- **Last completed phase**: CLOSE FINDING (for §4b) — the entire workflow ring is complete for both findings listed in §4.
- **Nothing is pending** — no implementation, no verification, no closure is outstanding for any finding.
- **Exact next action for the next session**: perform a fresh NEXT ITEM SELECTION + INSPECTION pass (per §1's workflow, starting at INSPECT) against the then-current codebase. Do not assume any specific candidate (see §7) is "next by default" — re-verify each freshly, exactly as every prior selection in this module's history has done.

## 6. VERY IMPORTANT — Current Next Action

Closure has already been committed and pushed for the most recent finding (§4b), and HEAD == origin/main was independently re-confirmed at the time this handoff was written.

**Next session must begin with fresh NEXT ITEM SELECTION + INSPECTION. Do not implement directly.**

## 7. Recent Audit / Finding History

**Already fixed/closed** (see §3 for the full list; do not reopen without a proven regression):
- Every C1–C6, M1–M5, M8 finding, and every M10 finding (`LeadReportingService::sourceBreakdown()`), plus every freshly-discovered (not independently numbered) finding listed in §3, are CLOSED.
- This session specifically closed the CSV/formula-injection export finding (§4a) and the deletable-source-type delete-enforcement finding (§4b).

**Deferred / requires a product decision (still open, per `PROGRESS.md`'s own rollup paragraph)**:
- **DE1** — a `LeadSource` with inbound `Conversation` history can become permanently undeletable (no delete/archive/detach path exists for `Conversation`/`Message`). Requires an explicit data-retention/archiving product decision before any implementation.
- **`messageTimeline()`'s blended-cutover edge case** — a narrow, accepted-as-technical-debt display ordering edge case from Feature #15's dual-write cutover. Long-standing, not scheduled.
- **The missing `isReferencedByWebsiteForm()` test-coverage gap** — a test-coverage observation, not a proven defect. Per this module's own standing rule, do not elevate a missing test into a "finding" without first confirming a real, reproducible risk.

**Still formally unconfirmed / no official finding text ever supplied** (do not reconstruct from the ID alone — see the epistemic caveat preserved from the prior handoff):
- **M6, M9, L2, L3, L4** — no finding text for these IDs has ever been supplied to any session; the original forensic audit report was never persisted as a file in this repo.
- Prior sessions' own code-level sanity checks (informational only, not an approved finding record) found: an **M9-shaped** gap (manual leads skip `LeadReceived`/auto-distribution/notification) that looks real but needs a product decision; an **L4-shaped** risk (`enum()`-backed columns compiling to fragile Postgres CHECK constraints) that is real but architectural, not an active bug; **L3-shaped** concerns that appear already safe (nullsafe access already used everywhere checked); and **L2-shaped** concerns (webhook payload-size limits) with weak evidence, matching standard Laravel practice.
- **Resolved since earlier handoffs — do NOT treat as still open**: `CreateLeadFromImport`'s duplicate-matching gap, `DetectDuplicateLeads`'s unscheduled status, and the `RecordInboundMessage`/webhook lead-resolution-continuity gap are all CLOSED (see §3).

**Not resolved by either of this session's closures**: DE1, the `messageTimeline()` edge case, and the `isReferencedByWebsiteForm()` test gap remain exactly as deferred as before — neither of this session's two closures touched them.

## 8. Technical Decisions That Must Not Be Lost

**CSV Formula Injection fix (§4a)**:
- The fix is **export-boundary-only** — a rendering-time transform applied immediately before `fputcsv()`, never touching stored `leads.name`/`email`/`phone` values, ingestion, or validation.
- The sanitizer (`Index::sanitizeCsvCell()`) applies to **`name` and `email` only** — `phone` is deliberately left unsanitized, because its own validation regex (`^(?=(?:\D*\d){7,})[0-9+\-\s()]{7,20}$`) structurally cannot carry a genuine executable formula (no letters/`@`/quotes permitted).
- Trigger set (characters that get a leading single-quote prefix when they are the *first* character of the value): `=`, `+`, `-`, `@`, a leading tab, a leading carriage return.
- `FacebookLeadAdsExport.php` (C6, XLSX export) was never modified — its mechanism (PhpSpreadsheet's `StringValueBinder`, a cell-type-metadata approach) does not transfer to plain CSV, which has no such metadata channel.

**Deletable-source-type enforcement fix (§4b)**:
- `LeadSourceType::isIndividuallyDeletable()` is the **canonical, single source of truth** for this business rule — do not reintroduce an inline `in_array(...)` literal anywhere for this rule; call the enum method instead.
- **Individually deletable**: `Email`, `WebsiteForm`, `Manual`. **Protected (not individually deletable)**: `FacebookMessenger`, `FacebookLeadAds`, `Whatsapp`, `Instagram`, `LinkedInLeadGen`, `CsvImport`.
- **Server-side `delete()` enforcement is the sole authoritative security boundary** — in both `Index::delete()` and `Show::delete()`, the check runs immediately after the existing `Gate::denies('delete', ...)` check and before the mutation.
- **`Show`'s UI gating (both the full detail page and the slide-over panel) is UX-only**, not a security boundary — it mirrors the Index table's pre-existing pattern of hiding the button, but the real protection is the server-side check above.
- **`LeadPolicy` remains permission-only** — the source-type rule was deliberately NOT folded into `LeadPolicy::delete()`, since that would conflate an authorization concern with a business-rule/data-state concern and would silently change what `Gate::allows('delete', ...)` means at every other call site.
- **`confirmDelete()`/`confirmDeleteLead()`/`deleteConfirmed()`/`deleteLeadConfirmed()` remain unchanged** — the source-type check was deliberately placed only in the actual mutation methods (`delete()`), not duplicated into the confirm-step, since `delete()` is independently callable and must be authoritative regardless.
- **Cascade/FK behavior for `Lead` deletion was explicitly NOT changed** by this finding — every FK referencing `leads.id` remains `cascadeOnDelete()` (or `nullOnDelete()` for `merged_into_lead_id`/`lead_import_rows`) exactly as before. This finding was a business-rule/authorization gap, not a data-integrity/cascade defect.

## 9. Test / Quality Baselines

- **Established PHPStan baseline: 425 errors**, unchanged across every closure in this module's history, including both of this session's. Any future closure must independently confirm the total stays at 425 and that zero new errors fall within its own changed files/line ranges — never assume, always re-run and compare.
- **Full `tests/Feature/Leads/` suite**, as of the most recent closure (§4b): **964/965 passed, 1 pre-existing skip, 0 failures** — clean, no flakiness observed on either of the two runs performed during this session's final verification.
- **`KanbanBoardTest.php` Faker-data-collision flake**: a documented, recurring, non-deterministic flake (a random Faker-generated field value coincidentally matching a search substring) with zero code-path overlap with whatever finding is being closed — has appeared intermittently across many past closures in this module (M2, M4, M10, the CSV-injection closure). It did **not** appear during either of this session's two closures' regression runs (both were clean). Still a known, standing risk for future sessions — if it appears again, follow the established discipline: investigate with repeated isolated/full-suite reruns rather than dismissing it as pre-existing without evidence, and only attribute it to this known pattern if the evidence actually supports that (zero code-path overlap, non-reproducing).
- **`tests/Feature/Broadcasting/LeadsIndexListenerTest.php`**: historically noted in `PROGRESS.md` as having 2 pre-existing, unrelated failures (a `'$refresh'` vs. actual listener-method-name mismatch) in full-application-suite runs from earlier in this module's history — not independently re-verified this session (only `tests/Feature/Leads/` was run, not the full application suite), so do not assume its current status without checking if a future session runs the full suite.

## 10. Do Not Touch

- **`PROGRESS.md`** — do not add/modify any closure section before a finding has passed Final Verification and received explicit user closure approval.
- **`SESSION_HANDOFF.md`** (this file) — do not modify except as part of an explicit end-of-session handoff-preparation instruction, exactly like the one that produced this version.
- **Every previously closed finding's implementation** — do not modify `LeadPolicy`, `ConfirmsLeadDeletionPassword`, any TOCTOU-fix's `DB::transaction()`/`lockForUpdate()` boundary, the CSV sanitizer, the deletable-source-type enum method, or any other closed finding's code, unless a genuine regression is proven against current code.
- **Cascade/FK migration definitions** — none of this module's closures have changed cascade behavior; do not do so as part of an unrelated finding.
- **DE1's underlying product question** (LeadSource retention/archiving policy) — this is explicitly a product-decision area, not something to resolve unilaterally in a code-only pass.

## 11. Next-Session Startup Instructions

A. Read this file (`SESSION_HANDOFF.md`) in full.
B. Independently inspect the actual current git state: `git branch --show-current`, `git rev-parse HEAD`, `git fetch origin main` + `git rev-parse origin/main`, `git status --short`, `git log --oneline -10` — do not trust §2's recorded HEAD/status without re-confirming them fresh.
C. Read `docs/lead-management/PROGRESS.md` in full (at minimum, its final rollup paragraph and the most recent 2-3 closure sections) — it is the authoritative historical record; this handoff is only a summary of it.
D. Verify the current phase matches §5/§6 above — confirm no finding is actually mid-workflow that this handoff failed to capture (e.g. re-check `git status` for any uncommitted implementation work beyond this file).
E. Continue **only** from the recorded next action (§6): perform a fresh NEXT ITEM SELECTION + INSPECTION pass. Do not implement anything until a new finding has gone through Impact Analysis, an Architecture/Decision Brief, and explicit user approval.
F. Do not assume any status from an older audit (M6/M9/L2–L4, or any candidate named in §7) is still accurate without fresh, current-code inspection — every one of this module's closures has required this discipline, and it has caught real staleness before.

## 12. Accuracy Rule

Every claim in this document is based on direct inspection performed at the time of writing: `git branch`/`git rev-parse`/`git fetch`/`git status`/`git log` output, and the current contents of `docs/lead-management/PROGRESS.md`. Nothing here was reconstructed from conversational memory or assumed to still be true from an earlier point in this session. The next session must perform the same discipline — re-verify, do not inherit.
