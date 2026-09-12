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
        Schema::create('deal_status_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // Keyed to deal_id only, deliberately — NOT lead_id/tenant_id.
            // App\Services\LeadMergeService reparents Deal rows by updating
            // Deal.lead_id alone; a Deal's own id never changes, so every
            // DealStatusLog row stays correctly attached to its Deal through
            // an ordinary merge with zero extra handling. Do not add
            // lead_id/tenant_id here "for convenience" — it would need its
            // own merge-time reparenting for no real benefit, since every
            // query into this table already goes through deal_id.
            $table->foreignUlid('deal_id')->constrained('deals')->cascadeOnDelete();

            $table->foreignUlid('changed_by')->nullable()->constrained('tenant_users')->nullOnDelete();

            // old_status nullable at the DB level for schema consistency
            // with lead_status_logs.old_status_id, though in practice a Deal
            // creation itself is never logged here (see App\Models\Deal's
            // docblock) — only real transitions are, so old_status is always
            // populated by the code that writes it.
            $table->string('old_status')->nullable();
            $table->string('new_status');

            $table->text('note')->nullable();

            $table->timestamps();

            $table->index('deal_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deal_status_logs');
    }
};
