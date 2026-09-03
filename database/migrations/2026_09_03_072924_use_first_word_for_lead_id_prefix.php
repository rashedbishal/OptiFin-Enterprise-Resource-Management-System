<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The lead identifier scheme (see App\Models\Tenant::generateLeadIdPrefix()
     * and Lead::booted()) originally slugified a tenant's whole name into its
     * lead_id_prefix ("Opticore Solutions Ltd" -> "OPTICORE-SOLUTIONS-LTD").
     * It should only ever have used the first word ("OPTICORE"). This
     * recomputes every tenant's prefix with the corrected rule and rewrites
     * every lead's identifier to match, without touching the numeric suffix
     * each lead already has — only the prefix segment changes, so this is
     * safe to run regardless of what's happened to next_lead_number since
     * the original backfill (new leads created, leads deleted, etc.).
     */
    public function up(): void
    {
        $usedPrefixes = [];

        DB::table('tenants')->select('id', 'name')->orderBy('created_at')->orderBy('id')->get()
            ->each(function (object $tenant) use (&$usedPrefixes): void {
                $firstWord = explode(' ', trim((string) $tenant->name), 2)[0];
                $base = strtoupper((string) Str::slug($firstWord));

                if ($base === '') {
                    $base = 'TENANT';
                }

                $prefix = $base;

                for ($suffix = 2; in_array($prefix, $usedPrefixes, true); $suffix++) {
                    $prefix = "{$base}-{$suffix}";
                }

                $usedPrefixes[] = $prefix;

                DB::table('tenants')->where('id', $tenant->id)->update(['lead_id_prefix' => $prefix]);

                DB::table('leads')->where('tenant_id', $tenant->id)->select('id', 'identifier')
                    ->chunkById(500, function ($leads) use ($prefix): void {
                        foreach ($leads as $lead) {
                            if ($lead->identifier === null || ! str_contains($lead->identifier, '-LEAD-')) {
                                continue;
                            }

                            $number = substr($lead->identifier, strrpos($lead->identifier, '-LEAD-') + strlen('-LEAD-'));

                            DB::table('leads')->where('id', $lead->id)->update([
                                'identifier' => "{$prefix}-LEAD-{$number}",
                            ]);
                        }
                    });
            });
    }

    /**
     * Reverse the migrations.
     *
     * Not reversible: the previous full-name prefix isn't recoverable once
     * this has overwritten it (and shouldn't be — it was the bug).
     */
    public function down(): void {}
};
