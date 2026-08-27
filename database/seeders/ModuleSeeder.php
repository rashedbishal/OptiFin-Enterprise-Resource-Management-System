<?php

namespace Database\Seeders;

use App\Enums\ModuleScope;
use App\Models\Module;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ModuleSeeder extends Seeder
{
    /**
     * Platform-scope modules represent sections of the Mother Admin /
     * Platform Admin panel and are used to build Platform Admin roles.
     *
     * @var array<int, string>
     */
    private array $platformModules = [
        'Admin Management',
        'Client Management',
        'Module Management',
        'Role Management',
        'System Settings',
    ];

    /**
     * Tenant-scope modules are the ERP business modules that can be
     * assigned to a Client and used to build Client-side roles. Their
     * business functionality is intentionally not implemented yet.
     *
     * @var array<int, string>
     */
    private array $tenantModules = [
        'Lead Management',
        'Finance & Accounting',
        'Sales & CRM',
        'Procurement',
        'Inventory Management',
        'Human Resource Management',
        'Payroll Management',
        'Fixed Assets Management',
    ];

    public function run(): void
    {
        foreach ($this->platformModules as $index => $name) {
            $this->seedModule($name, ModuleScope::Platform, isSystem: true, sortOrder: $index);
        }

        foreach ($this->tenantModules as $index => $name) {
            $this->seedModule($name, ModuleScope::Tenant, isSystem: false, sortOrder: $index);
        }
    }

    private function seedModule(string $name, ModuleScope $scope, bool $isSystem, int $sortOrder): void
    {
        Module::updateOrCreate(
            ['slug' => Str::slug($name)],
            [
                'name' => $name,
                'description' => "{$name} module",
                'scope' => $scope,
                'is_system' => $isSystem,
                'sort_order' => $sortOrder,
            ]
        );
    }
}
