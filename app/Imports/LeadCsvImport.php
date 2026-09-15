<?php

namespace App\Imports;

use App\Actions\Tenant\CreateLeadFromImport;
use App\Enums\LeadImportStatus;
use App\Events\LeadImportStatusChanged;
use App\Models\LeadImport;
use App\Models\LeadSource;
use App\Models\Tenant;
use App\Models\TenantUser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Maatwebsite\Excel\Concerns\RemembersRowNumber;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Events\AfterImport;
use Throwable;

/**
 * Feature #7's CSV import engine. Deliberately implements plain ShouldQueue
 * — never Maatwebsite\Excel\Concerns\ShouldBatch (would need Bus::batch()/
 * job_batches, explicitly forbidden) and never
 * Maatwebsite\Excel\Concerns\ShouldQueueWithoutChain (would dispatch chunk
 * jobs independently, breaking row-order determinism).
 *
 * Verified against the installed maatwebsite/excel 4.0.2 source
 * (vendor/maatwebsite/excel/src/ChunkReader.php): plain ShouldQueue makes
 * Laravel Excel dispatch (new QueueImport($import))->chain([...ReadChunk
 * jobs..., AfterImportJob]) — Laravel's native job CHAINING, which needs no
 * job_batches table. A chain's jobs run strictly one after another — the
 * next link isn't even inserted into the `jobs` table until the current
 * one's handle() finishes — so no two ReadChunk jobs of the same import can
 * ever run concurrently, regardless of how many queue workers exist. Within
 * one ReadChunk job, rows are processed synchronously in file order. Net
 * effect: every row across the whole file is processed in strict ascending
 * row-number order with zero possible concurrency — this is what makes
 * "the lowest CSV row number that validates and passes duplicate checks
 * wins" true, without any lock.
 */
class LeadCsvImport implements ShouldQueue, ToModel, WithChunkReading, WithEvents, WithHeadingRow
{
    use RemembersRowNumber;

    public function __construct(
        private readonly string $leadImportId,
    ) {}

    /**
     * M6 — lazily resolved on the first model() call of each freshly
     * deserialized ReadChunk job execution (chunkSize() = 200 rows), then
     * reused for the remainder of that same chunk — NOT across the whole
     * import: verified against the installed maatwebsite/excel source
     * (vendor/maatwebsite/excel/src/Jobs/ReadChunk.php) that each chunk is
     * dispatched as its own separately queued, separately serialized job,
     * so a fresh LeadCsvImport instance (these three properties back at
     * null) is what every chunk actually starts from in production — no
     * BeforeImport/cross-serialization-boundary state is relied on here.
     * Confirmed safe to cache for the life of any import: LeadImport has
     * no delete path anywhere in the application, Tenant has no delete
     * path anywhere in the application, and LeadSource can never be
     * deleted while any LeadImport references it (Platform\Clients\
     * LeadSources::delete()'s own dependent-data guard checks exactly
     * that). TenantUser is deliberately NOT cached here — see the comment
     * directly above its resolution inside model() below.
     */
    private ?LeadImport $resolvedImport = null;

    private ?Tenant $resolvedTenant = null;

    private ?LeadSource $resolvedSource = null;

    /**
     * @param  array<string, mixed>  $row
     */
    public function model(array $row): null
    {
        if ($this->resolvedImport === null) {
            $this->resolvedImport = LeadImport::find($this->leadImportId);
        }

        $import = $this->resolvedImport;

        if ($import === null) {
            return null;
        }

        if ($import->status === LeadImportStatus::Pending) {
            $import->update(['status' => LeadImportStatus::Processing, 'started_at' => now()]);
        }

        if ($this->resolvedTenant === null) {
            $this->resolvedTenant = Tenant::find($import->tenant_id);
        }

        if ($this->resolvedSource === null) {
            $this->resolvedSource = LeadSource::find($import->source_id);
        }

        $tenant = $this->resolvedTenant;
        $source = $this->resolvedSource;

        // Deliberately NOT cached alongside $tenant/$source above: unlike
        // LeadImport/Tenant/LeadSource (all provably undeletable for the
        // life of any import — see this class's own property docblock),
        // a TenantUser has no delete guard at all (Tenant\Users\Index::
        // delete()) and lead_imports.imported_by is nullOnDelete() —
        // deletion mid-import is a real, application-permitted scenario.
        // Re-resolved fresh every row, exactly as before this change, so
        // a mid-chunk deletion is still caught on the very next row
        // rather than silently reused from a stale cached reference.
        $importedBy = TenantUser::find($import->imported_by);

        // Tenant isolation is explicit here, not assumed: this runs inside
        // a queue worker, never a request bound to app()->bound('currentTenant')
        // — every lookup CreateLeadFromImport itself does is also
        // explicitly tenant_id-scoped, for the same reason.
        if (! $tenant instanceof Tenant || ! $source instanceof LeadSource || ! $importedBy instanceof TenantUser) {
            return null;
        }

        // WithHeadingRow treats spreadsheet row 1 as the header, so the
        // first DATA row's raw spreadsheet index (what getRowNumber()
        // returns) is 2 - subtracting 1 gives the 1-based, header-excluded
        // data-row number App\Models\LeadImportRow.row_number expects.
        $rowNumber = ($this->getRowNumber() ?? 2) - 1;

        app(CreateLeadFromImport::class)->handle($import, $tenant, $source, $importedBy, $rowNumber, $row);

        return null;
    }

    /**
     * Matches the existing chunkById(200, ...) precedent already used by
     * leads:recalculate-scores and leads:detect-duplicates — the only two
     * prior "iterate a tenant's leads in bulk" examples in this codebase.
     */
    public function chunkSize(): int
    {
        return 200;
    }

    /**
     * The verified finalization hook: Laravel Excel unconditionally chains
     * an AfterImportJob as the LAST link (see the class docblock), which
     * cannot run until every ReadChunk before it has completed. Its
     * handle() calls Reader::afterImport($import), which fires this event
     * when the import implements WithEvents — the reliable "every row is
     * done" signal, not an assumption.
     *
     * @return array<class-string, callable>
     */
    public function registerEvents(): array
    {
        return [
            AfterImport::class => function (): void {
                $import = LeadImport::find($this->leadImportId);

                if ($import === null) {
                    return;
                }

                $import->update([
                    'status' => $import->failed_count > 0 ? LeadImportStatus::CompletedWithErrors : LeadImportStatus::Completed,
                    'completed_at' => now(),
                ]);

                event(new LeadImportStatusChanged($import));
            },
        ];
    }

    /**
     * Covers the OTHER termination path: a ReadChunk job that exhausts its
     * retries on an infrastructure fault (not a row-level business
     * failure — those are caught inside CreateLeadFromImport and never
     * throw) aborts the rest of the chain, so AfterImportJob — and
     * therefore registerEvents()'s AfterImport listener above — would
     * never run. Maatwebsite\Excel\Jobs\ReadChunk::failed() already calls
     * $this->import->failed($e) when this method exists (verified in
     * vendor source), so this is the guaranteed alternate path to a
     * terminal status — without it, lead_imports.status could be stuck at
     * "processing" forever.
     */
    public function failed(Throwable $e): void
    {
        $import = LeadImport::find($this->leadImportId);

        if ($import === null) {
            return;
        }

        $import->update([
            'status' => LeadImportStatus::Failed,
            'error_message' => $e->getMessage(),
            'completed_at' => now(),
        ]);

        event(new LeadImportStatusChanged($import));
    }
}
