<?php

namespace App\Models;

use App\Enums\LeadCustomFieldType;
use Database\Factories\LeadCustomFieldDefinitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tenant's own custom-field catalog entry — tenant self-service, like
 * LeadTag/LeadScoringRule, not a Platform-Admin-managed shared catalog like
 * LeadStatus/Currency. No BelongsToTenant trait: every query filters
 * tenant_id explicitly instead (same discipline LeadTag/LeadScoringRule
 * already follow), since BelongsToTenant's global scope only activates when
 * app()->bound('currentTenant').
 *
 * `type`/`options` become immutable once any LeadCustomFieldValue exists for
 * this definition (enforced in App\Livewire\Tenant\LeadCustomFields\Index —
 * see hasValues()) — there is no safe, defined reinterpretation of existing
 * typed data across a type/option-list change, so it's blocked rather than
 * silently transformed or discarded.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $key
 * @property LeadCustomFieldType $type
 * @property array<int, string>|null $options
 * @property bool $is_required
 * @property bool $is_active
 * @property int $sort_order
 * @property-read Tenant $tenant
 */
#[Fillable(['tenant_id', 'name', 'key', 'type', 'options', 'is_required', 'is_active', 'sort_order'])]
class LeadCustomFieldDefinition extends Model
{
    /** @use HasFactory<LeadCustomFieldDefinitionFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LeadCustomFieldType::class,
            'options' => 'array',
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
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
     * @return HasMany<LeadCustomFieldValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(LeadCustomFieldValue::class);
    }

    /**
     * Feature #13 — every WebsiteFormField (across any of this tenant's
     * Website Forms) that exposes this definition for public submission.
     *
     * @return HasMany<WebsiteFormField, $this>
     */
    public function websiteFormFields(): HasMany
    {
        return $this->hasMany(WebsiteFormField::class);
    }

    /**
     * Whether any lead currently carries a value for this definition — the
     * single check backing both the delete-block (Currency/LeadStatus-style
     * "refuse, don't cascade") and the type/options immutability rule.
     */
    public function hasValues(): bool
    {
        return $this->values()->exists();
    }

    /**
     * Feature #13 — whether any Website Form still references this
     * definition, even if zero leads have ever submitted a value through
     * it yet. Backs an additive delete-block alongside hasValues() in
     * App\Livewire\Tenant\LeadCustomFields\Index::delete() — a form
     * referencing a since-deleted definition would otherwise dangle.
     */
    public function isReferencedByWebsiteForm(): bool
    {
        return $this->websiteFormFields()->exists();
    }

    /**
     * The Laravel validation rules a value for THIS specific definition
     * must satisfy right now — delegates to LeadCustomFieldType, which is
     * the actual source of truth per type, so this is just "apply my own
     * required/options state to my own type."
     *
     * @return array<int, mixed>
     */
    public function validationRules(): array
    {
        return $this->type->validationRules($this->is_required, $this->options);
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
