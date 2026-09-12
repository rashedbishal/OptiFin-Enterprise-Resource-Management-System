<?php

namespace App\Livewire\Tenant\LeadImports;

use App\Enums\LeadSourceType;
use App\Imports\LeadCsvImport;
use App\Models\Lead;
use App\Models\LeadImport;
use App\Models\LeadSource;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Feature #7 — CSV Import: upload form + this tenant's import history.
 * Client-Admin-and-permitted-Client-User accessible, same bar as manual
 * Lead creation (Gate::authorize('create', Lead::class)) — CSV import is
 * the same ability exercised at higher volume, not a new one.
 */
#[Title('Import Leads')]
class Index extends Component
{
    use WithFileUploads, WithPagination;

    /**
     * The exact, case-insensitive header set Feature #7 Phase 1 supports —
     * no field-mapping UI, so these are the only column names ever
     * recognized. "name" is the only required one.
     *
     * @var array<int, string>
     */
    private const array ALLOWED_HEADERS = [
        'name', 'email', 'phone', 'company', 'industry', 'location', 'service_interest',
        'budget', 'budget_currency', 'deal_value', 'deal_value_currency',
        'status', 'priority', 'assignee_email', 'tags',
    ];

    private const int MAX_FILE_SIZE_KB = 10240; // 10 MB

    private const int MAX_DATA_ROWS = 10000;

    public ?TemporaryUploadedFile $csvFile = null;

    public function mount(): void
    {
        Gate::authorize('create', Lead::class);
    }

    #[Computed]
    public function imports()
    {
        return LeadImport::where('tenant_id', Auth::user()->tenant_id)
            ->latest('created_at')
            ->paginate(15);
    }

    /**
     * Rejects the entire upload before anything is queued — a malformed
     * header, an unknown column, a missing "name" column, or a file over
     * either limit never creates a LeadImport row or touches the queue at
     * all. Never truncates: an over-limit file is rejected outright, not
     * partially accepted.
     */
    public function import(): void
    {
        Gate::authorize('create', Lead::class);

        $this->validate([
            'csvFile' => ['required', 'file', 'mimes:csv,txt', 'max:'.self::MAX_FILE_SIZE_KB],
        ]);

        $localPath = $this->csvFile->getRealPath();

        $headerCheck = $this->readHeaders($localPath);

        if ($headerCheck !== null) {
            $this->addError('csvFile', $headerCheck);

            return;
        }

        $dataRowCount = $this->countDataRows($localPath);

        if ($dataRowCount === 0) {
            $this->addError('csvFile', __('The CSV file has no data rows.'));

            return;
        }

        if ($dataRowCount > self::MAX_DATA_ROWS) {
            $this->addError('csvFile', __('The CSV file has :count data rows, which is more than the :max allowed. Split it into smaller files and upload them separately.', [
                'count' => $dataRowCount,
                'max' => number_format(self::MAX_DATA_ROWS),
            ]));

            return;
        }

        $tenant = Auth::user()->tenant;

        $source = LeadSource::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'type' => LeadSourceType::CsvImport],
            ['name' => 'CSV Import', 'is_active' => true],
        );

        $storedPath = $this->csvFile->storeAs('lead-imports', Str::ulid().'.csv', 'local');

        if ($storedPath === false) {
            $this->addError('csvFile', __('The file could not be stored. Please try again.'));

            return;
        }

        $import = LeadImport::create([
            'tenant_id' => $tenant->id,
            'imported_by' => Auth::id(),
            'source_id' => $source->id,
            'original_filename' => $this->csvFile->getClientOriginalName(),
            'file_path' => $storedPath,
            'total_rows' => $dataRowCount,
        ]);

        Excel::import(new LeadCsvImport($import->id), $storedPath, 'local');

        $this->reset('csvFile');
        unset($this->imports);

        Flux::toast(variant: 'success', text: __('Import started — processing :count rows.', ['count' => $dataRowCount]));

        $this->redirectRoute('tenant-lead-imports.show', $import, navigate: true);
    }

    /**
     * Peeks the file's first row only — returns a human-readable error
     * string if the headers are invalid, or null if they're fine. Never
     * reads the whole file into memory.
     */
    private function readHeaders(string $path): ?string
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return __('The file could not be read.');
        }

        $headerRow = fgetcsv($handle);
        fclose($handle);

        if ($headerRow === false) {
            return __('The CSV file is empty.');
        }

        $normalized = array_map(fn (mixed $header): string => mb_strtolower(trim((string) $header)), $headerRow);

        $unknown = array_diff($normalized, self::ALLOWED_HEADERS);

        if ($unknown !== []) {
            return __('Unknown column(s): :columns. Allowed columns are: :allowed.', [
                'columns' => implode(', ', $unknown),
                'allowed' => implode(', ', self::ALLOWED_HEADERS),
            ]);
        }

        if (! in_array('name', $normalized, true)) {
            return __('The CSV file is missing the required "name" column.');
        }

        return null;
    }

    /**
     * Counts data rows only (the header row is never counted) — uses
     * fgetcsv() row-by-row rather than counting newlines, since a quoted
     * field can legitimately contain an embedded newline.
     */
    private function countDataRows(string $path): int
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return 0;
        }

        fgetcsv($handle); // skip header

        $count = 0;

        while (($row = fgetcsv($handle)) !== false) {
            // A trailing blank line (common CSV export artifact) parses as
            // a row whose every column is null/empty - not a real data row.
            $hasContent = array_filter($row, fn (?string $value): bool => $value !== null && $value !== '') !== [];

            if (! $hasContent) {
                continue;
            }

            $count++;
        }

        fclose($handle);

        return $count;
    }

    public function render(): View
    {
        return view('livewire.tenant.lead-imports.index');
    }
}
