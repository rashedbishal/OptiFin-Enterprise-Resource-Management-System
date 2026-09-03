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
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('lead_id_prefix')->nullable()->unique()->after('tenant_code');
            $table->unsignedInteger('next_lead_number')->default(1)->after('lead_id_prefix');
        });

        $this->backfillLeadIdPrefixes();
    }

    /**
     * Every tenant created before lead identifiers existed still needs a
     * prefix, generated the same way App\Models\Tenant::generateLeadIdPrefix()
     * does for new tenants — duplicated here (rather than calling into app
     * code from a migration) so this keeps working even if that method's
     * collision handling changes later.
     */
    private function backfillLeadIdPrefixes(): void
    {
        $usedPrefixes = [];

        DB::table('tenants')->select('id', 'name')->orderBy('created_at')->orderBy('id')->get()
            ->each(function (object $tenant) use (&$usedPrefixes): void {
                $base = strtoupper((string) Str::slug((string) $tenant->name));

                if ($base === '') {
                    $base = 'TENANT';
                }

                $prefix = $base;

                for ($suffix = 2; in_array($prefix, $usedPrefixes, true); $suffix++) {
                    $prefix = "{$base}-{$suffix}";
                }

                $usedPrefixes[] = $prefix;

                DB::table('tenants')->where('id', $tenant->id)->update(['lead_id_prefix' => $prefix]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['lead_id_prefix', 'next_lead_number']);
        });
    }
};
