<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // Additive-only, for Duplicate Detection & Lead Merge: null on
            // every existing lead until a merge actually happens. A merged
            // "loser" lead is never deleted (see App\Services\LeadMergeService)
            // — it stays fully queryable/traceable, just pointed at the lead
            // it was merged into and excluded from normal list views.
            // nullOnDelete rather than cascade: if the surviving lead is ever
            // deleted through the existing delete flow, the loser shouldn't
            // vanish with it — it just becomes unmerged again.
            $table->foreignUlid('merged_into_lead_id')->nullable()->after('id')
                ->constrained('leads')->nullOnDelete();

            // Supports Duplicate Detection's same-tenant email/phone lookup
            // (App\Services\LeadDuplicateDetectionService) — neither column
            // was indexed for lookup by value before.
            $table->index(['tenant_id', 'email']);
            $table->index(['tenant_id', 'phone']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merged_into_lead_id');
            $table->dropIndex(['tenant_id', 'email']);
            $table->dropIndex(['tenant_id', 'phone']);
        });
    }
};
