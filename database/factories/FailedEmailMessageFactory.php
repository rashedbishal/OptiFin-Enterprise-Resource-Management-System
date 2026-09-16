<?php

namespace Database\Factories;

use App\Enums\EmailPartialFailureStage;
use App\Enums\FailedEmailMessageStatus;
use App\Models\FailedEmailMessage;
use App\Models\LeadSource;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FailedEmailMessage>
 */
class FailedEmailMessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'lead_source_id' => LeadSource::factory(),
            'message_id' => '<'.fake()->unique()->uuid().'@example.com>',
            'imap_uid' => fake()->unique()->numberBetween(1, 100000),
            'uidvalidity' => 1,
            'status' => FailedEmailMessageStatus::Failed,
            'attempts' => 1,
            'failure_class' => 'unknown',
            'failure_reason' => 'Simulated failure for testing.',
            'partial_lead_id' => null,
            'partial_stage' => null,
            'is_new_lead' => null,
            'resolved_lead_id' => null,
            'first_failed_at' => now(),
            'last_attempted_at' => now(),
            'next_retry_at' => now()->addMinutes(15),
            'recovered_at' => null,
            'subject' => fake()->sentence(),
            'from_email' => fake()->safeEmail(),
        ];
    }

    public function exhausted(): static
    {
        return $this->state(fn (): array => [
            'status' => FailedEmailMessageStatus::Exhausted,
            'attempts' => 5,
            'next_retry_at' => null,
        ]);
    }

    public function recovered(): static
    {
        return $this->state(fn (): array => [
            'status' => FailedEmailMessageStatus::Recovered,
            'next_retry_at' => null,
            'recovered_at' => now(),
        ]);
    }

    public function partiallyProcessed(EmailPartialFailureStage $stage, string $leadId, bool $isNewLead = true): static
    {
        return $this->state(fn (): array => [
            'partial_lead_id' => $leadId,
            'partial_stage' => $stage,
            'is_new_lead' => $isNewLead,
        ]);
    }
}
