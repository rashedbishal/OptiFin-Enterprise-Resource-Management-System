<?php

namespace App\Models;

use App\Enums\LeadPriority;
use App\Enums\TaskStatus;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A discrete, nameable unit of work tied to a Lead (and optionally one of
 * that Lead's own Deals) — a Lead may have many Tasks, each independently
 * tracked to completion. Complementary to, and never a replacement for,
 * Lead's own next_follow_up_at/meeting_date fields (see Show::saveTask()'s
 * docblock): those stay single-slot scheduling fields on Lead, untouched by
 * this feature. No permission class of its own: Task actions are authorized
 * against the parent Lead (see App\Livewire\Tenant\Leads\Show's Task
 * methods).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $lead_id
 * @property string|null $deal_id
 * @property string $title
 * @property string|null $description
 * @property Carbon|null $due_date
 * @property string|null $due_time
 * @property LeadPriority $priority
 * @property TaskStatus $status
 * @property Carbon|null $closed_at
 * @property string|null $assigned_to
 * @property string|null $created_by
 * @property-read Lead $lead
 * @property-read Deal|null $deal
 * @property-read TenantUser|null $assignee
 * @property-read TenantUser|null $createdBy
 */
#[Fillable([
    'tenant_id', 'lead_id', 'deal_id', 'title', 'description', 'due_date', 'due_time',
    'priority', 'status', 'closed_at', 'assigned_to', 'created_by',
])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'priority' => LeadPriority::class,
            'status' => TaskStatus::class,
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<Deal, $this>
     */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /**
     * @return BelongsTo<TenantUser, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'assigned_to');
    }

    /**
     * @return BelongsTo<TenantUser, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'created_by');
    }

    /**
     * @return HasMany<TaskStatusLog, $this>
     */
    public function statusLogs(): HasMany
    {
        return $this->hasMany(TaskStatusLog::class);
    }

    /**
     * "2:30 PM" if a time was recorded alongside the due date, else null —
     * record-only metadata, never read by alertActive()'s date-based
     * comparison below. Mirrors Lead::meetingTimeFormatted() exactly.
     */
    public function dueTimeFormatted(): ?string
    {
        return $this->due_time !== null ? Carbon::parse($this->due_time)->format('g:i A') : null;
    }

    /**
     * Whether this Task's due-date alert should be showing right now —
     * mirrors Lead::meetingAlertActive()/followUpAlertActive() exactly: no
     * upper bound once the window opens, stays active (including into
     * overdue) until the Task is completed or cancelled. Also gates on
     * status directly (not just relying on a caller's own query filter) so
     * a closed Task can never read as alert-active regardless of how it's
     * queried.
     */
    public function alertActive(bool $enabled, int $daysBefore): bool
    {
        if (! $enabled || $this->status !== TaskStatus::Pending || $this->due_date === null) {
            return false;
        }

        return today()->gte($this->due_date->copy()->subDays($daysBefore));
    }
}
