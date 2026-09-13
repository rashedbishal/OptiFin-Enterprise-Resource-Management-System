<?php

use App\Models\Lead;
use App\Services\LeadDuplicateDetectionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * email_normalized/phone_normalized are ordinary application-computed
     * columns, not database-generated ones — see
     * App\Services\LeadDuplicateDetectionService's docblock and
     * App\Observers\LeadObserver::saving(). Backfilled here using the exact
     * same normalizeEmail()/normalizePhone() static methods every write
     * path uses going forward, deliberately NOT a raw SQL UPDATE with
     * LOWER()/TRIM()/regexp_replace(): those can disagree with
     * normalizeEmail()'s multibyte-aware Str::lower() on Unicode edge
     * cases, and regexp_replace has no SQLite equivalent at all, so this
     * keeps a single source of truth for normalization across every driver.
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('email_normalized')->nullable()->after('email');
            $table->string('phone_normalized')->nullable()->after('phone');
        });

        Lead::query()
            ->where(function ($query): void {
                $query->whereNotNull('email')->orWhereNotNull('phone');
            })
            ->chunkById(200, function ($leads): void {
                foreach ($leads as $lead) {
                    // Direct attribute assignment, not update(['email_normalized' => ...]):
                    // these two columns are deliberately excluded from Lead's
                    // #[Fillable] list, the same established pattern as
                    // identifier/lead_score/merged_into_lead_id — system-
                    // computed values that must never be mass-assignable.
                    $lead->email_normalized = LeadDuplicateDetectionService::normalizeEmail($lead->email);
                    $lead->phone_normalized = LeadDuplicateDetectionService::normalizePhone($lead->phone);
                    $lead->save();
                }
            });

        Schema::table('leads', function (Blueprint $table) {
            $table->index(['tenant_id', 'email_normalized']);
            $table->index(['tenant_id', 'phone_normalized']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'email_normalized']);
            $table->dropIndex(['tenant_id', 'phone_normalized']);
            $table->dropColumn(['email_normalized', 'phone_normalized']);
        });
    }
};
