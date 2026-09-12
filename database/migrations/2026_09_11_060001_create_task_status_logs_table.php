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
        Schema::create('task_status_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // Keyed to task_id only, deliberately — NOT lead_id/tenant_id.
            // App\Services\LeadMergeService reparents Task rows by updating
            // Task.lead_id alone; a Task's own id never changes, so every
            // TaskStatusLog row stays correctly attached to its Task through
            // an ordinary merge with zero extra handling — same pattern as
            // deal_status_logs.deal_id. Do not add lead_id/tenant_id here,
            // and do not add TaskStatusLog to LeadMergeService::CHILD_MODELS.
            $table->foreignUlid('task_id')->constrained('tasks')->cascadeOnDelete();

            $table->foreignUlid('changed_by')->nullable()->constrained('tenant_users')->nullOnDelete();

            // Nullable at the DB level for schema consistency with
            // lead_status_logs.old_status_id/deal_status_logs.old_status,
            // though in practice a Task's creation itself is never logged
            // here (see App\Models\Task's docblock) — only real transitions
            // are, so old_status is always populated by the code that
            // writes it.
            $table->string('old_status')->nullable();
            $table->string('new_status');

            $table->text('note')->nullable();

            $table->timestamps();

            $table->index('task_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_status_logs');
    }
};
