<?php

namespace Database\Factories;

use App\Models\CallingAppDevice;
use App\Models\TenantUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CallingAppDevice>
 */
class CallingAppDeviceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_user_id' => TenantUser::factory(),
            'tenant_id' => fn (array $attributes): string => TenantUser::query()->whereKey($attributes['tenant_user_id'])->firstOrFail()->tenant_id,
            'personal_access_token_id' => fn (array $attributes): int => TenantUser::query()->whereKey($attributes['tenant_user_id'])->firstOrFail()
                ->createToken('Calling app', [CallingAppDevice::TOKEN_ABILITY])->accessToken->getKey(),
            'device_name' => fake()->randomElement(['Pixel 8', 'Galaxy S24', 'Redmi Note 13']),
        ];
    }
}
