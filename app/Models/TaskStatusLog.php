<?php

namespace App\Models;

use App\Enums\TaskStatus;
use Database\Factories\TaskStatusLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One real status transition for a Task — a row is written only for actual
 * transitions (see Show::changeTaskStatus()), never for a Task's initial
 * creation. Keyed to task_id only, deliberately (see the
 * create_task_status_logs_table migration's docblock): App\Services\
 * LeadMergeService reparents a Task's lead_id but never touches its id, so
 * this table needs no merge-time handling of its own.
 *
 * @property string $id
 * @property string $task_id
 * @property string|null $changed_by
 * @property TaskStatus|null $old_status
 * @property TaskStatus $new_status
 * @property string|null $note
 * @property-read Task $task
 * @property-read TenantUser|null $changedBy
 */
#[Fillable(['task_id', 'changed_by', 'old_status', 'new_status', 'note'])]
class TaskStatusLog extends Model
{
    /** @use HasFactory<TaskStatusLogFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_status' => TaskStatus::class,
            'new_status' => TaskStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<TenantUser, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'changed_by');
    }
}
