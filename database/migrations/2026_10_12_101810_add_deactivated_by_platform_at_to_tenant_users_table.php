<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks exactly which tenant_users rows App\Actions\Platform\
 * ManageClientAdminActivation::deactivate() put to sleep (set non-null the
 * moment it flips is_active to false), so its own activate() can restore
 * precisely those rows later — never a row that was already inactive for
 * an independent reason before that sweep ran, and never a row some other
 * path (e.g. Tenant\Users\Index::toggleActive()) has since reactivated on
 * its own.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->timestamp('deactivated_by_platform_at')->nullable()->after('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->dropColumn('deactivated_by_platform_at');
        });
    }
};
