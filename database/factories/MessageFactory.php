<?php

namespace Database\Factories;

use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'conversation_id' => Conversation::factory(),
            'lead_id' => Lead::factory(),
            'channel' => MessageChannel::Whatsapp,
            'direction' => MessageDirection::Inbound,
            'message_type' => MessageType::Text,
            'body' => fake()->sentence(),
        ];
    }

    public function outbound(): static
    {
        return $this->state(fn (array $attributes): array => [
            'direction' => MessageDirection::Outbound,
            'status' => MessageStatus::Pending,
            'status_updated_at' => now(),
        ]);
    }

    public function withStatus(MessageStatus $status): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => $status,
            'status_updated_at' => now(),
        ]);
    }

    public function withExternalId(string $externalMessageId): static
    {
        return $this->state(fn (array $attributes): array => ['external_message_id' => $externalMessageId]);
    }
}
