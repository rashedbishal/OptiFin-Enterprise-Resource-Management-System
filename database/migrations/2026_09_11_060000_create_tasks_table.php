<?php

use App\Enums\LeadPriority;
use App\Enums\TaskStatus;
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
        Schema::create('tasks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // A Lead may have many Tasks over its lifetime — see
            // App\Models\Lead::tasks(). No uniqueness constraint.
            $table->foreignUlid('lead_id')->constrained('leads')->cascadeOnDelete();

            // Optional link to one of this Task's own lead's Deals — nulled,
            // not cascade-deleted, if that Deal is later removed: a work
            // item shouldn't vanish just because the Deal record did. Always
            // validated server-side (App\Livewire\Tenant\Leads\Show) to
            // belong to the same lead_id/tenant_id as this Task — never
            // trusted from a dropdown alone.
            $table->foreignUlid('deal_id')->nullable()->constrained('deals')->nullOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            // Split date/time, deliberately mirroring Lead's own
            // meeting_date/meeting_time shape rather than a single datetime
            // column — due_time is record-only/cosmetic, never read by
            // Task::alertActive()'s date-only comparison, same reasoning as
            // Lead::meetingTimeFormatted()'s own docblock.
            $table->date('due_date')->nullable();
            $table->time('due_time')->nullable();

            // Reuses the existing LeadPriority enum — same mental model as
            // Lead priority, no reason for a parallel identical enum.
            $table->string('priority')->default(LeadPriority::Medium->value);

            $table->string('status')->default(TaskStatus::Pending->value);

            // One shared terminal timestamp for BOTH Completed and
            // Cancelled — mirrors Deal.actual_close_date exactly (one
            // column covering both outcomes, not two). Named closed_at to
            // match Lead.closed_at's own existing naming for "this record
            // left the active state," not completed_at, which would wrongly
            // imply success for a cancelled task. System-set only (see
            // Show::changeTaskStatus()) — cleared back to null on reopen.
            $table->timestamp('closed_at')->nullable();

            // Independent of Lead::assigned_to — defaults to it at creation
            // time (a one-time copy, not a live sync) but is separately
            // editable and is never touched by a later Lead reassignment.
            // App\Services\LeadAssignmentService remains entirely untouched
            // by this feature.
            $table->foreignUlid('assigned_to')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('tenant_users')->nullOnDelete();

            $table->timestamps();

            $table->index(['tenant_id', 'lead_id']);
            $table->index(['lead_id', 'status']);
            $table->index(['tenant_id', 'assigned_to']);
            $table->index(['tenant_id', 'due_date']);
            $table->index('deal_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
