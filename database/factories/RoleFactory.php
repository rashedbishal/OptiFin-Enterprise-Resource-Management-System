<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->jobTitle();

        return [
            'tenant_id' => null,
            'name' => $name,
            'slug' => Str::slug($name),
            'is_system' => false,
            'is_owner_role' => false,
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn () => ['tenant_id' => $tenant->id]);
    }

    public function ownerRole(): static
    {
        return $this->state(fn () => [
            'name' => 'Client Admin',
            'slug' => 'client-admin',
            'is_system' => true,
            'is_owner_role' => true,
        ]);
    }
}
