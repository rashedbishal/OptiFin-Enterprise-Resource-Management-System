<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('tenant_currencies', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'currency_id']);
        });

        $this->seedCatalogAndBackfillTenants();
    }

    /**
     * Every tenant currently shares one plain `tenants.currency` string
     * (default "USD", never anything else in this app's actual data) —
     * seed the catalog with exactly the currencies already in use (not a
     * speculative "common currencies" list the Platform Admin didn't ask
     * for) and attach each tenant to its own, so every tenant already has
     * >=1 currency selected once this ships rather than starting from
     * zero and immediately failing the "at least one currency" rule the
     * app now enforces before a new Lead Source can be created.
     */
    private function seedCatalogAndBackfillTenants(): void
    {
        $now = now();

        // USD is always seeded, even on a database with zero tenants (a
        // fresh test database migrates before any Tenant factory runs) —
        // AssignModuleToTenant::seedDefaultCurrency() and TenantFactory
        // both need at least one catalog entry to attach a brand new
        // tenant to. ->unique() drops the duplicate if real tenant data
        // already included USD, which it always has in practice so far.
        $codes = DB::table('tenants')->select('currency')->distinct()->pluck('currency')->push('USD')->unique();

        $currencyIds = [];

        foreach ($codes as $code) {
            $currencyIds[$code] = (string) Str::ulid();

            DB::table('currencies')->insert([
                'id' => $currencyIds[$code],
                'code' => $code,
                'name' => $this->knownName($code),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('tenants')->select('id', 'currency')->orderBy('id')
            ->chunkById(100, function ($tenants) use ($currencyIds, $now) {
                $rows = [];

                foreach ($tenants as $tenant) {
                    $rows[] = [
                        'tenant_id' => $tenant->id,
                        'currency_id' => $currencyIds[$tenant->currency],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('tenant_currencies')->insert($rows);
                }
            });
    }

    private function knownName(string $code): string
    {
        return match ($code) {
            'USD' => 'US Dollar',
            'EUR' => 'Euro',
            'GBP' => 'British Pound',
            'BDT' => 'Bangladeshi Taka',
            'INR' => 'Indian Rupee',
            default => $code,
        };
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_currencies');
        Schema::dropIfExists('currencies');
    }
};
