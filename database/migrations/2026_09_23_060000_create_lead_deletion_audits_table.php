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
        Schema::create('lead_deletion_audits', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Nullable/nullOnDelete, not cascade: this row is written inside
            // the SAME transaction as, and immediately before, the Lead's
            // own hard delete (see App\Actions\Tenant\RecordLeadDeletionAudit)
            // — by design the lead row this points to is gone a moment
            // later. The snapshot columns below, not this FK, are what keep
            // the row identifiable afterwards.
            $table->foreignUlid('lead_id')->nullable()->constrained('leads')->nullOnDelete();

            $table->string('lead_identifier');
            $table->string('lead_name');
            $table->string('lead_email')->nullable();
            $table->string('lead_phone')->nullable();
            $table->string('source_type');

            $table->foreignUlid('deleted_by')->nullable()->constrained('tenant_users')->nullOnDelete();

            $table->boolean('is_bulk')->default(false);

            // A rough count-per-table shape of what was cascade-destroyed
            // alongside this Lead (attachments, notes, tasks, deals, ...) —
            // see App\Actions\Tenant\RecordLeadDeletionAudit for exactly
            // which tables this covers and why.
            $table->json('cascade_summary')->nullable();

            $table->timestamps();

            $table->index('tenant_id');
            $table->index('lead_id');
            $table->index('deleted_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_deletion_audits');
    }
};
