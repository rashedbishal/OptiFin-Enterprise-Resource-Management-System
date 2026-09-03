<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('identifier')->nullable()->unique()->after('id');
        });

        $this->backfillIdentifiers();
    }

    /**
     * Assigns every pre-existing lead the same {tenant_prefix}-LEAD-{n}
     * identifier new leads get going forward (see Lead::booted()), numbered
     * in creation order per tenant — leads.id is a ULID, so ordering by it
     * is already chronological order without needing created_at. Leaves
     * each tenant's next_lead_number picking up right after its last one.
     */
    private function backfillIdentifiers(): void
    {
        DB::table('tenants')->select('id', 'lead_id_prefix')->orderBy('id')->get()
            ->each(function (object $tenant): void {
                $number = 1;

                DB::table('leads')->where('tenant_id', $tenant->id)->select('id')
                    ->chunkById(500, function ($leads) use ($tenant, &$number): void {
                        foreach ($leads as $lead) {
                            DB::table('leads')->where('id', $lead->id)->update([
                                'identifier' => "{$tenant->lead_id_prefix}-LEAD-{$number}",
                            ]);
                            $number++;
                        }
                    });

                DB::table('tenants')->where('id', $tenant->id)->update(['next_lead_number' => $number]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('identifier');
        });
    }
};
