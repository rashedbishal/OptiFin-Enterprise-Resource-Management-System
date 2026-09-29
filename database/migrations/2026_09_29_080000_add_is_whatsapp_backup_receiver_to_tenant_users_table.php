<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * WhatsApp Call Backup / Overflow Receiver — a per-individual-user
     * capability (not a Role/Module/Permission grant, see
     * App\Concerns\HasModulePermissions's own docblock for why every
     * existing permission in this app is role-derived instead): a Client
     * Admin may opt a specific TenantUser into receiving WhatsApp calls for
     * leads assigned to someone else, when that assignee is ineligible or
     * already busy on another active call (see App\Services\CallRouter's
     * new BackupFallback tier). Defaults false for every existing and new
     * row, so no tenant's routing behavior changes until a Client Admin
     * explicitly opts a user in — mirrors calling_enabled's own "our local
     * intent, default off, zero behavior change until explicitly turned
     * on" precedent (2026_09_21_070001_add_calling_columns_to_lead_sources_table.php).
     */
    public function up(): void
    {
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->boolean('is_whatsapp_backup_receiver')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->dropColumn('is_whatsapp_backup_receiver');
        });
    }
};
