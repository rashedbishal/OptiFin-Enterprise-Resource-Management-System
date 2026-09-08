<?php

namespace App\Models;

use App\Enums\LeadScoringField;
use App\Enums\LeadScoringOperator;
use Database\Factories\LeadScoringRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tenant-configured scoring rule, evaluated by
 * App\Services\LeadScoringService against every active rule's own tenant's
 * leads. `field`/`operator` are plain strings backed by the
 * LeadScoringField/LeadScoringOperator enums rather than DB enums, so a
 * future field/operator addition never needs a migration.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $field
 * @property string $operator
 * @property mixed $value
 * @property int $points
 * @property bool $is_active
 * @property int $sort_order
 */
#[Fillable(['tenant_id', 'name', 'field', 'operator', 'value', 'points', 'is_active', 'sort_order'])]
class LeadScoringRule extends Model
{
    /** @use HasFactory<LeadScoringRuleFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'array',
            'points' => 'integer',
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

    public function fieldEnum(): ?LeadScoringField
    {
        return LeadScoringField::tryFrom($this->field);
    }

    public function operatorEnum(): ?LeadScoringOperator
    {
        return LeadScoringOperator::tryFrom($this->operator);
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
