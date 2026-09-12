<?php

namespace App\Models;

use Database\Factories\LeadCustomFieldValueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tenant-defined custom field's value on one specific Lead. Never
 * created with a blank value — clearing a value deletes this row instead
 * (see App\Actions\Tenant\SaveLeadCustomFieldValues), so `value` is always
 * either a real string or the row simply doesn't exist. tenant_id is
 * denormalized here (same discipline as LeadDuplicateLink/Deal/Task) so
 * every query can filter it explicitly without an extra join, per this
 * module's tenant-isolation convention — see LeadCustomFieldDefinition's
 * docblock for why there's no BelongsToTenant trait either.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $lead_id
 * @property string $lead_custom_field_definition_id
 * @property string|null $value
 * @property-read LeadCustomFieldDefinition $definition
 * @property-read Lead $lead
 */
#[Fillable(['tenant_id', 'lead_id', 'lead_custom_field_definition_id', 'value'])]
class LeadCustomFieldValue extends Model
{
    /** @use HasFactory<LeadCustomFieldValueFactory> */
    use HasFactory, HasUlids;

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<LeadCustomFieldDefinition, $this>
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(LeadCustomFieldDefinition::class, 'lead_custom_field_definition_id');
    }
}
