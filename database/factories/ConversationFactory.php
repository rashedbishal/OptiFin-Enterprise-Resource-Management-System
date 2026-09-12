<?php

namespace Database\Factories;

use App\Enums\MessageChannel;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'lead_id' => Lead::factory(),
            'lead_source_id' => LeadSource::factory(),
            'channel' => MessageChannel::Whatsapp,
            'external_thread_id' => '1'.fake()->numerify('##########'),
            'last_inbound_at' => now(),
            'last_outbound_at' => null,
        ];
    }

    public function messenger(): static
    {
        return $this->state(fn (array $attributes): array => [
            'channel' => MessageChannel::Messenger,
            'external_thread_id' => Str::random(16),
        ]);
    }

    public function instagram(): static
    {
        return $this->state(fn (array $attributes): array => [
            'channel' => MessageChannel::Instagram,
            'external_thread_id' => Str::random(16),
        ]);
    }

    public function windowExpired(): static
    {
        return $this->state(fn (array $attributes): array => ['last_inbound_at' => now()->subHours(48)]);
    }

    public function neverContacted(): static
    {
        return $this->state(fn (array $attributes): array => ['last_inbound_at' => null]);
    }
}
