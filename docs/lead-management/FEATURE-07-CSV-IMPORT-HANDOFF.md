# Feature #7 — CSV Import: Handoff Document

**NEXT ACTION:**
Read `docs/lead-management/PROGRESS.md` and this handoff file first.
Do NOT implement anything.
Do NOT update `PROGRESS.md`.
Wait for the user to perform/report interactive browser QA.
If the user reports all browser checks PASS, review the result and prepare a final approval/closure step.
If the user reports a failure, inspect only that failure, make the smallest production-safe fix, rerun relevant tests/regressions, and wait for approval.
Do not mark Feature #7 COMPLETE/APPROVED/CLOSED without explicit user approval.

---

## 1. Current Status

- Feature #7 CSV Import implementation is **complete from a coding perspective**.
- Final verification is **PARTIAL PASS**: all automated tests, a real Postgres + real-queue-worker + real-private-storage smoke test, and HTTP-level route/auth checks all pass. **Interactive browser QA is still pending** — the assistant has no interactive browser tool available and could not perform it.
- **Feature #7 is NOT approved, NOT closed.** `docs/lead-management/PROGRESS.md` has **not** been modified for Feature #7 and must remain unchanged until the user gives explicit final approval after their own browser QA.

## 2. Approved Architecture Reference (Architecture v2)

- Max file size: **10 MB**. Max data rows: **10,000** (header row excluded from the count). Both enforced **before** anything is queued — the whole upload is rejected outright, never truncated.
- Exact allowed CSV headers — required: `name`. Optional: `email`, `phone`, `company`, `industry`, `location`, `service_interest`, `budget`, `budget_currency`, `deal_value`, `deal_value_currency`, `status`, `priority`, `assignee_email`, `tags`.
- Header matching is case-insensitive and whitespace-trimmed. A missing `name` column or any unknown column **rejects the entire upload** before a `LeadImport` row is ever created.
- Row-level validation failures are **partial-success**: an invalid row fails on its own; every other valid row in the same file still imports.
- **Existing-Lead duplicate**: match by normalized email OR normalized phone (never name-only). If email matches Lead A and phone matches a *different* Lead B, the row is rejected as a duplicate conflict. Never merges or overwrites an existing Lead.
- **In-file duplicate**: within the same CSV, the first row (by CSV row number) that validates and passes duplicate checks wins; a later matching row is rejected as a duplicate, distinctly attributed as "duplicate of an earlier row in this CSV" (not the generic existing-Lead message).
- **Tenant isolation** is explicit everywhere, including inside queued jobs (never assumes `BelongsToTenant`'s request-bound scope is active).
- Status / priority / assignee / tags / currency are resolved **only** against this tenant's own existing catalogs/users — never auto-created. An unresolvable value fails only that row.
- Assignment (`assignee_email`, if supplied) goes through the real `LeadAssignmentService::assign()` — real `LeadAssigned` event, real assignment notification. `autoAssign()`/round-robin distribution is **never** invoked during import.
- Scoring is explicit: `LeadScoringService::computeFor($lead, 'lead_received')` is called synchronously per imported row.
- `LeadReceived` and `LeadUpdated` **never** fire for a CSV-imported row (avoids per-row notification/auto-assignment fan-out at import scale).
- Processing is **queued and chunked** via Laravel Excel 4.0.2's native `ShouldQueue` + `WithChunkReading` — implemented as Laravel job **chaining** (verified against the installed package source), never `Bus::batch()`/`job_batches`.
- **Sequential chunk ordering is guaranteed structurally**, not by a lock: a chain's jobs run strictly one after another (the next link isn't even inserted into the `jobs` table until the current one finishes), so no two chunks of the same import can ever run concurrently, and rows within one chunk are processed in file order.
- Import history (`LeadImport`) and row-level results (`LeadImportRow`) are persisted with accurate counts (imported/duplicate/failed) and a terminal status.
- Uploaded CSVs are stored on **private** local storage (`storage/app/private`), never public. The private `file_path` is never rendered in either Blade view.
- No automatic merge behavior anywhere in this feature.
- No scope creep: no background dashboard, no Deal/Task creation from import, no field-mapping UI, no new permission slug.

## 3. Implemented Files

**Created:**
- `database/migrations/2026_09_12_080000_create_lead_imports_table.php`
- `database/migrations/2026_09_12_080001_create_lead_import_rows_table.php`
- `database/migrations/2026_09_12_080002_add_csv_import_to_lead_sources_type_check.php` (added during final verification — see §6)
- `app/Enums/LeadImportStatus.php`
- `app/Enums/LeadImportRowStatus.php`
- `app/Models/LeadImport.php`
- `app/Models/LeadImportRow.php`
- `database/factories/LeadImportFactory.php`
- `database/factories/LeadImportRowFactory.php`
- `app/Support/LeadValidationRules.php`
- `app/Actions/Tenant/CreateLeadFromImport.php`
- `app/Imports/LeadCsvImport.php`
- `app/Livewire/Tenant/LeadImports/Index.php`
- `app/Livewire/Tenant/LeadImports/Show.php`
- `resources/views/livewire/tenant/lead-imports/index.blade.php`
- `resources/views/livewire/tenant/lead-imports/show.blade.php`
- `tests/Feature/Leads/CreateLeadFromImportTest.php` (23 tests)
- `tests/Feature/Leads/LeadImportUploadTest.php` (10 tests)
- `tests/Feature/Leads/LeadCsvImportEngineTest.php` (3 tests)

**Modified:**
- `app/Enums/LeadSourceType.php` — new `CsvImport` case + 4 exhaustive match arms (`label()`, `icon()`, `configFields()`, `configValidationRules()`)
- `app/Models/LeadSource.php` — `webhookUrl()` gained a 5th arm (`CsvImport => null`)
- `app/Livewire/Platform/Clients/LeadSources.php` — `typeOptions()` now excludes `CsvImport` too (mirroring `Manual`); `testConnection()` gained a matching arm
- `app/Livewire/Tenant/Leads/Index.php` — `saveLead()` now calls into `LeadValidationRules` instead of inlining rules (verified behavior-preserving); `sourceTypeOptions()` force-includes `CsvImport`
- `routes/web.php` — `leads/import` and `leads/import/{leadImport}` (registered before `leads/{lead}`)

## 4. Important Implementation Details

- **`CreateLeadFromImport`**: processes exactly one CSV row. Idempotency-guards on `(lead_import_id, row_number)` before doing anything. Checks in-file duplicates *before* existing-Lead duplicates (see the ordering note below). Resolves status/priority/assignee/tags/currency against the tenant's own catalogs, failing the row (never the whole import) on any unresolved reference. Wraps the actual Lead creation + assignment + tag-attach + scoring in one `DB::transaction()` per row.
- **`LeadCsvImport`**: implements `ToModel`, `WithHeadingRow`, `WithChunkReading` (chunk size 200, matching the existing `chunkById(200, ...)` precedent from `leads:recalculate-scores`/`leads:detect-duplicates`), `ShouldQueue` (plain — never `ShouldBatch`, never `ShouldQueueWithoutChain`), and `WithEvents` (registers an `AfterImport` listener that finalizes the import's terminal status). Implements `failed(Throwable $e)` to mark the import `Failed` if a chunk's chain aborts on an infrastructure fault.
- **`LeadImport` / `LeadImportRow`**: `LeadImportRow` carries `normalized_email`/`normalized_phone` columns (populated via `LeadDuplicateDetectionService`'s own normalization methods, never a second implementation) with a unique index on `(lead_import_id, row_number)` plus indexes on the normalized columns for fast in-file duplicate lookups.
- **`LeadSourceType::CsvImport`**: a new lazily-provisioned source type, structurally identical to `Manual` — excluded from the Platform Admin's Lead Sources config UI, created on first use via `firstOrCreate` in the upload handler.
- **Shared `LeadValidationRules` extraction**: the field-level validation rules previously inlined in `Index::saveLead()` were extracted into static methods on `App\Support\LeadValidationRules`, reused by both the manual-entry form and the CSV importer. Verified behavior-preserving by re-running the full pre-existing manual-creation test suite unmodified.
- **Postgres CHECK constraint migration**: required because `lead_sources.type` has a Postgres CHECK constraint enumerating allowed values, baked in at migration time — every prior `LeadSourceType` addition needed a matching companion migration. This was missed initially; see §6.
- **Duplicate-check ordering refinement**: in-file duplicates are checked *before* existing-Lead duplicates (not after, as originally sketched) — once an earlier row in the same import commits its own real `Lead` row, the existing-Lead query would also match it, so checking in-file first gives correct, more precise attribution and is what makes the `normalized_email`/`normalized_phone` columns actually load-bearing.
- **Currency-code resolution**: `budget_currency`/`deal_value_currency` CSV columns supply a currency *code* (e.g. "USD"), resolved against `tenant->currencies()->where('code', ...)` — distinct from `LeadValidationRules::currencyId()`, which validates an internal currency *ID* (what the Livewire dropdown submits) and is not reusable here.
- **Idempotency strategy**: a retried chunk job is guarded by checking whether `(lead_import_id, row_number)` already exists in `LeadImportRow` before processing that row again; combined with the unique DB index and one `DB::transaction()` per row, a row can never be processed twice successfully.
- **Queue/retry behavior**: Laravel Excel's native `ShouldQueue` (without `ShouldBatch`) dispatches via Laravel job **chaining**, verified against the installed `maatwebsite/excel` 4.0.2 source — chunks run strictly sequentially, never concurrently, which is what guarantees deterministic row-order without any lock.
- **Scoring and assignment behavior**: see §2 — both are explicit, synchronous, and route through the existing real services (`LeadScoringService::computeFor()`, `LeadAssignmentService::assign()`), never a raw bypass.

## 5. Final Verification Results

- Feature #7 focused tests: **36/36**
- Combined focused + `ManualLeadCreationTest` + `LeadCurrencySyncTest`: **62/62**
- Feature #1–#6 regression (19 files: merge, scoring, tags, deals, tasks, bulk actions): **224/224**
- `tests/Feature/Leads` + `tests/Feature/Notifications`: **684/685**, 1 pre-existing skip
- Full application suite: **810/813**
- The 3 non-passing full-suite results are the 2 already-documented pre-existing `LeadsIndexListenerTest` failures (`'$refresh'` vs actual `onLeadReceived`/`onLeadUpdated`) plus the 1 pre-existing skip — none caused by Feature #7.
- Pint: clean
- PHPStan: 317 total, vs. the 310 baseline established at Feature #6's closure — the +7 are exclusively in the two new Livewire components, all traced to the same three already-accepted baseline categories used throughout this module (`Auth::user()` union type, `#[Computed]`-less magic property access, missing return types on computed-property methods); no newly introduced diagnostic categories.
- A real end-to-end smoke test against actual Postgres (`optifin_erp`), the real running `queue:work` process, and real private storage passed after the two fixes in §6 — an 8-row test CSV covering a minimal row, a full-fields row (assignment + notification + tags + currency + scoring all confirmed), an existing-Lead duplicate, an in-file duplicate, and four distinct row-level validation failures all produced the exact expected outcome.

## 6. Bugs Found and Fixed During Final Verification

1. **Missing PostgreSQL CHECK constraint for `CsvImport`** (critical, production-blocking): `lead_sources.type` is enforced by a Postgres CHECK constraint that must be updated every time a new `LeadSourceType` case is added (established precedent — every prior addition shipped a companion migration). This was missed when `LeadSourceType::CsvImport` was first added. Every CSV upload would have failed outright on Postgres with `SQLSTATE[23514]: Check violation`, despite the entire automated test suite passing (SQLite, the test driver, doesn't enforce this the same way). Fixed with `2026_09_12_080002_add_csv_import_to_lead_sources_type_check.php`, following the exact established pattern (drop + recreate the constraint from `LeadSourceType::cases()`). Re-verified via the real Postgres smoke test.
2. **Test storage leakage**: `LeadImportUploadTest.php` and `LeadCsvImportEngineTest.php` never called `Storage::fake('local')`, so every test run wrote real CSV files into the actual `storage/app/private/lead-imports` directory — 39 orphaned files had accumulated there before this was caught (during the manual smoke test, by directly inspecting the storage directory). Fixed by adding `Storage::fake('local')` via `beforeEach()` in both files; the orphaned files were deleted and a fresh test run confirmed zero leftover files.

**Also recorded (not a code bug):** the first post-fix smoke-test attempt failed with `Call to undefined method App\Models\Lead::tags()` — traced to the long-running `queue:work` process (running since session start) having cached a stale class definition in memory, a known Laravel operational fact (long-running workers require `queue:restart`/a restart after code changes to pick up new code). Confirmed via a fresh `tinker` process that `Lead::tags()` works correctly. **The queue worker was restarted** (old process killed, a fresh one started) rather than left stale.

## 7. Current Real Test Data

A populated, real verification import was deliberately left in the **"Verification Test Co"** tenant for manual inspection — it shows 2 imported / 2 duplicate / 4 failed rows with distinct error messages per failure type, plus a real fired assignment notification.

Routes needed for browser QA (relative to the tenant's own domain/session):
- `/leads/import` — upload page + import history
- `/leads/import/{id}` — import detail (the populated example is visible from the history list on this tenant)

No credentials beyond what was already documented in the implementation report are recorded here.

## 8. Remaining Browser QA

**Verified by automated/HTTP/system tests already** (do not need to be re-verified, only visually confirmed if convenient):
- Upload rejects wrong file type/MIME
- Upload rejects files over 10 MB
- Upload rejects missing/unknown headers before queueing
- Upload rejects more than 10,000 data rows; accepts exactly 10,000
- A valid CSV creates a `LeadImport` and queues processing
- Import reaches a correct terminal status (`Completed`/`CompletedWithErrors`) with accurate counts
- Existing-Lead duplicate detection (email/phone, conflict case)
- In-file duplicate detection and first-valid-row-wins ordering
- Status/priority/currency/assignee/tags resolution, including failure-only-that-row behavior for each
- Assignment goes through `LeadAssignmentService` with a real notification
- Scoring computes correctly for an imported lead
- Tenant isolation (cross-tenant import access 404s)
- Authorization (a user without `create` on `lead-management` cannot access the page)
- Private file path never appears in rendered HTML

**Still requires interactive browser verification:**
- [ ] `/leads/import` page renders correctly (layout, spacing, no visual glitches)
- [ ] The file picker control works as expected in a real browser
- [ ] Uploading a valid CSV through the actual UI (not a simulated request)
- [ ] Processing/completion status is visibly correct while and after processing
- [ ] Import history page displays correctly, including the pre-existing example import
- [ ] Import detail page displays correctly
- [ ] Row-level statuses (Imported/Duplicate/Failed) are visually clear
- [ ] Row error messages are understandable to a real user
- [ ] Existing-duplicate row is visually distinguishable and links to the right Lead
- [ ] In-file-duplicate row is visually distinguishable
- [ ] Optional fields (status, priority, currency, assignee, tags) display correctly on a successfully imported lead
- [ ] Budget/deal value display correctly with currency
- [ ] Validation error messages on upload (bad file/headers) render clearly in the UI
- [ ] Upload-size (10 MB) rejection is visible and understandable in the UI
- [ ] 10,000-row rejection is visible and understandable in the UI (if practical to test interactively)
- [ ] `wire:poll`/status-refresh behavior on the detail page actually updates the screen while an import is processing
- [ ] Loading/error/success UI states look correct (no stuck spinners, no broken toasts)
- [ ] Authorization/tenant isolation behave correctly as experienced in the browser (not just HTTP status codes)
- [ ] Private file path is not exposed anywhere visible in the browser (view source, network tab, etc.)

## 9. Navigation Finding

No navigation/sidebar item was added for the new import pages. This matches the existing precedent: the Scoring Rules and Tags pages are also URL-only with no nav entry anywhere in the codebase. This is a **UX consideration only** — not currently a blocker, and not approved for scope expansion without explicit direction.

## 10. Session Closure

- This handoff file was created at `docs/lead-management/FEATURE-07-CSV-IMPORT-HANDOFF.md`.
- No application code was changed during this handoff step.
- `docs/lead-management/PROGRESS.md` was not changed.
- Feature #7 remains pending final interactive browser QA — not marked COMPLETE, APPROVED, or CLOSED.
