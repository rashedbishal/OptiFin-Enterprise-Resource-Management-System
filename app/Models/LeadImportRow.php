<?php

namespace App\Models;

use App\Enums\LeadImportRowStatus;
use Database\Factories\LeadImportRowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The result of one CSV data row within a LeadImport — see
 * App\Enums\LeadImportRowStatus and App\Actions\Tenant\CreateLeadFromImport,
 * the only place this table is ever written to.
 *
 * @property string $id
 * @property string $lead_import_id
 * @property int $row_number
 * @property LeadImportRowStatus $status
 * @property string|null $lead_id
 * @property string|null $normalized_email
 * @property string|null $normalized_phone
 * @property array<string, mixed> $raw_data
 * @property array<int, string>|null $errors
 * @property string|null $duplicate_of_lead_id
 * @property-read LeadImport $leadImport
 * @property-read Lead|null $lead
 * @property-read Lead|null $duplicateOfLead
 */
#[Fillable([
    'lead_import_id', 'row_number', 'status', 'lead_id', 'normalized_email', 'normalized_phone',
    'raw_data', 'errors', 'duplicate_of_lead_id',
])]
class LeadImportRow extends Model
{
    /** @use HasFactory<LeadImportRowFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LeadImportRowStatus::class,
            'row_number' => 'integer',
            'raw_data' => 'array',
            'errors' => 'array',
        ];
    }

    /**
     * @return BelongsTo<LeadImport, $this>
     */
    public function leadImport(): BelongsTo
    {
        return $this->belongsTo(LeadImport::class);
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function duplicateOfLead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'duplicate_of_lead_id');
    }
}
