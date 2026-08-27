<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\LeadPriority;
use App\Enums\LeadStatus;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $source_id
 * @property string|null $assigned_to
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $message
 * @property array<string, mixed>|null $source_data
 * @property LeadStatus $status
 * @property LeadPriority $priority
 * @property string|null $notes
 * @property Carbon|null $assigned_at
 * @property Carbon|null $first_contacted_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'tenant_id', 'source_id', 'assigned_to', 'name', 'email', 'phone', 'message',
    'source_data', 'status', 'priority', 'notes', 'assigned_at', 'first_contacted_at', 'closed_at',
])]
class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_data' => 'array',
            'status' => LeadStatus::class,
            'priority' => LeadPriority::class,
            'assigned_at' => 'datetime',
            'first_contacted_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'source_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'assigned_to');
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(LeadStatusLog::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(LeadAssignment::class);
    }

    public function leadNotes(): HasMany
    {
        return $this->hasMany(LeadNote::class);
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function assignedTo(Builder $query, string $tenantUserId): void
    {
        $query->where('assigned_to', $tenantUserId);
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function unassigned(Builder $query): void
    {
        $query->whereNull('assigned_to');
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function byStatus(Builder $query, LeadStatus $status): void
    {
        $query->where('status', $status);
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function byPriority(Builder $query, LeadPriority $priority): void
    {
        $query->where('priority', $priority);
    }

    /**
     * Get lead counts grouped by status, zero-filled for statuses with no leads.
     *
     * @param  Builder<Lead>  $query
     * @return array<int, array{status: string, count: int}>
     */
    public static function countsByStatus(Builder $query): array
    {
        $counts = $query
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return collect(LeadStatus::cases())->map(fn (LeadStatus $status): array => [
            'status' => $status->value,
            'count' => (int) ($counts[$status->value] ?? 0),
        ])->all();
    }
}
