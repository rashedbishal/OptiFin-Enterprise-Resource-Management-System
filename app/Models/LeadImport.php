<?php

namespace App\Models;

use App\Enums\LeadImportStatus;
use Database\Factories\LeadImportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One uploaded CSV import batch (Feature #7). No BelongsToTenant trait —
 * every query filters tenant_id explicitly instead, same discipline as
 * LeadScoringRule/LeadTag: this is read/written from queued jobs
 * (App\Imports\LeadCsvImport's chunk jobs), which are never request-bound,
 * so BelongsToTenant's global scope would not activate there anyway.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $imported_by
 * @property string $source_id
 * @property string $original_filename
 * @property string $file_path
 * @property LeadImportStatus $status
 * @property int|null $total_rows
 * @property int $imported_count
 * @property int $duplicate_count
 * @property int $failed_count
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property string|null $error_message
 * @property-read Tenant $tenant
 * @property-read TenantUser|null $importedBy
 * @property-read LeadSource $source
 */
#[Fillable([
    'tenant_id', 'imported_by', 'source_id', 'original_filename', 'file_path', 'status',
    'total_rows', 'imported_count', 'duplicate_count', 'failed_count',
    'started_at', 'completed_at', 'error_message',
])]
class LeadImport extends Model
{
    /** @use HasFactory<LeadImportFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LeadImportStatus::class,
            'total_rows' => 'integer',
            'imported_count' => 'integer',
            'duplicate_count' => 'integer',
            'failed_count' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<TenantUser, $this>
     */
    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'imported_by');
    }

    /**
     * @return BelongsTo<LeadSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'source_id');
    }

    /**
     * @return HasMany<LeadImportRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(LeadImportRow::class);
    }
}
