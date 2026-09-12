<?php

namespace Database\Factories;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TaskStatusLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskStatusLog>
 */
class TaskStatusLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'old_status' => TaskStatus::Pending,
            'new_status' => TaskStatus::Completed,
        ];
    }
}
