<?php

namespace App\Models;

use App\Enums\ConsentType;
use App\Enums\WebsiteFormFieldType;
use Database\Factories\WebsiteFormFieldFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Feature #13 — one field a specific WebsiteForm exposes for public
 * submission: either a fixed Lead column (`fixed_field`, one of
 * email/phone/message — `name` is always implicit, never has its own row
 * here) or a reference to an existing tenant Custom Field Definition
 * (`lead_custom_field_definition_id`). A configuration row, not data — it
 * carries no submitted value itself, only "this form accepts this field."
 *
 * Feature #14 adds a third shape: `consent_type` (set only when field_type =
 * Consent), plus `is_required`/`policy_version`/`policy_url`, meaningful
 * only for that same field type — Fixed/Custom rows never read these,
 * preserving their existing Feature #13 behavior unchanged.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $website_form_id
 * @property WebsiteFormFieldType $field_type
 * @property string|null $fixed_field
 * @property string|null $lead_custom_field_definition_id
 * @property ConsentType|null $consent_type
 * @property bool $is_required
 * @property string|null $policy_version
 * @property string|null $policy_url
 * @property int $sort_order
 * @property-read WebsiteForm $form
 * @property-read LeadCustomFieldDefinition|null $customFieldDefinition
 */
#[Fillable([
    'tenant_id', 'website_form_id', 'field_type', 'fixed_field', 'lead_custom_field_definition_id',
    'consent_type', 'is_required', 'policy_version', 'policy_url', 'sort_order',
])]
class WebsiteFormField extends Model
{
    /** @use HasFactory<WebsiteFormFieldFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'field_type' => WebsiteFormFieldType::class,
            'consent_type' => ConsentType::class,
            'is_required' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<WebsiteForm, $this>
     */
    public function form(): BelongsTo
    {
        return $this->belongsTo(WebsiteForm::class, 'website_form_id');
    }

    /**
     * @return BelongsTo<LeadCustomFieldDefinition, $this>
     */
    public function customFieldDefinition(): BelongsTo
    {
        return $this->belongsTo(LeadCustomFieldDefinition::class, 'lead_custom_field_definition_id');
    }
}
