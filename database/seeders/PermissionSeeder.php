<?php

namespace Database\Seeders;

use App\Enums\PermissionSlug;
use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PermissionSlug::cases() as $permission) {
            Permission::updateOrCreate(
                ['slug' => $permission->value],
                ['name' => ucfirst($permission->value)]
            );
        }
    }
}
