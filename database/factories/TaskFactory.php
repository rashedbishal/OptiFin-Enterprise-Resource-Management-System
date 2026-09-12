<?php

namespace Database\Factories;

use App\Enums\LeadPriority;
use App\Enums\TaskStatus;
use App\Models\Lead;
use App\Models\Task;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            // Created against the task's own tenant_id (already resolved by
            // this point in attribute expansion), same reasoning as
            // LeadFactory/DealFactory's own defaults — avoids spawning an
            // unrelated orphan tenant (see .ai/rules/factories.md).
            'lead_id' => fn (array $attributes) => Lead::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
            ])->id,
            'title' => fake()->sentence(4),
            'priority' => LeadPriority::Medium,
            'status' => TaskStatus::Pending,
            'due_date' => now()->addDays(7)->toDateString(),
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (): array => ['status' => TaskStatus::Completed, 'closed_at' => now()]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => ['status' => TaskStatus::Cancelled, 'closed_at' => now()]);
    }
}
