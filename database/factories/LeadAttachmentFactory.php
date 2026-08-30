<?php

namespace Database\Factories;

use App\Enums\LeadAttachmentStatus;
use App\Models\Lead;
use App\Models\LeadAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadAttachment>
 */
class LeadAttachmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            'type' => 'image',
            'path' => 'leads/'.fake()->uuid().'/messenger/'.fake()->uuid().'.jpg',
            'mime' => 'image/jpeg',
            'original_media_url' => fake()->url(),
            'status' => LeadAttachmentStatus::Downloaded,
            'sent_at' => fake()->dateTimeBetween('-1 week'),
        ];
    }
}
