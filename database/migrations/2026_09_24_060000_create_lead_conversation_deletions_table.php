<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Append-only record of a lead's conversation being wiped (the lead
     * itself stays). Counts only — never message content. `lead_id` is
     * nullOnDelete because the lead may itself be deleted later; the
     * snapshot columns keep the row identifiable.
     */
    public function up(): void
    {
        Schema::create('lead_conversation_deletions', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('lead_id')->nullable()->constrained('leads')->nullOnDelete();

            $table->string('lead_identifier');
            $table->string('lead_name');
            $table->string('channel');

            $table->foreignUlid('deleted_by')->nullable()->constrained('tenant_users')->nullOnDelete();

            $table->json('summary')->nullable();

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
        Schema::dropIfExists('lead_conversation_deletions');
    }
};
