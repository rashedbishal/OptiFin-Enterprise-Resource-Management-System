<?php

namespace Database\Factories;

use App\Enums\CallDirection;
use App\Enums\CallEndReason;
use App\Enums\CallRoutingMode;
use App\Enums\CallStatus;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Models\LeadSource;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LeadCall>
 */
class LeadCallFactory extends Factory
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
            'external_call_id' => 'wacid.'.Str::random(28),
            'status' => CallStatus::Ringing,
            'routing_mode' => CallRoutingMode::AdminFallback,
            'routed_user_ids' => [],
            'ringing_expires_at' => now()->addSeconds(60),
        ];
    }

    /**
     * WhatsApp Outbound Calling — an Initiated row: no wacid yet, agent-
     * initiated routing, no ringing_expires_at (that's set only once Meta's
     * connect response is applied — see InitiateOutboundCall).
     */
    public function outbound(): static
    {
        return $this->state(fn (array $attributes): array => [
            'direction' => CallDirection::Outbound,
            'external_call_id' => null,
            'biz_opaque_callback_data' => 'test-'.Str::random(20),
            'status' => CallStatus::Initiated,
            'routing_mode' => CallRoutingMode::AgentInitiated,
            'ringing_expires_at' => null,
        ]);
    }

    public function answering(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CallStatus::Answering,
            'claimed_at' => now(),
        ]);
    }

    public function connected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CallStatus::Connected,
            'claimed_at' => now()->subSeconds(10),
            'connected_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CallStatus::Completed,
            'end_reason' => CallEndReason::AgentHangup,
            'claimed_at' => now()->subMinutes(3),
            'connected_at' => now()->subMinutes(2),
            'ended_at' => now(),
            'duration_seconds' => 120,
        ]);
    }

    public function missed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CallStatus::Missed,
            'end_reason' => CallEndReason::CallerEndedOrNotAnswered,
            'ended_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CallStatus::Rejected,
            'end_reason' => CallEndReason::AgentRejected,
            'ended_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CallStatus::Failed,
            'end_reason' => CallEndReason::MetaApiError,
            'ended_at' => now(),
        ]);
    }
}
