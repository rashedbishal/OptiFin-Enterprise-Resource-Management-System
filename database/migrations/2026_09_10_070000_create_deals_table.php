<?php

use App\Enums\DealStatus;
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
        Schema::create('deals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // A Lead may have many Deals over its lifetime (repeat business,
            // upsells, a lost deal followed by a fresh one) — see
            // App\Models\Lead::deals()/currentDeal(). No uniqueness
            // constraint: nothing about this relationship caps a Lead to
            // one Deal, active or otherwise.
            $table->foreignUlid('lead_id')->constrained('leads')->cascadeOnDelete();

            $table->string('name');

            // Nullable: a Deal can exist before its terms are pinned down
            // (early qualification). currency_id is only ever persisted
            // alongside a real value — see Show::saveDeal()'s "a currency
            // without an amount means nothing" rule, same as Lead's own
            // deal_value/estimated_budget pairing.
            $table->decimal('value', 14, 2)->nullable();
            $table->foreignUlid('currency_id')->nullable()->constrained('currencies')->nullOnDelete();

            $table->unsignedTinyInteger('probability')->nullable();

            // Plain string backed by App\Enums\DealStatus rather than a DB
            // enum — same reasoning as lead_scoring_rules.field/operator:
            // a future status value never needs a migration, only a new
            // enum case.
            $table->string('status')->default(DealStatus::Open->value);

            $table->date('expected_close_date')->nullable();

            // System-set only (see Show::changeDealStatus()) — written the
            // moment status transitions to won/lost, cleared back to null
            // on reopen. Never directly editable by a user.
            $table->date('actual_close_date')->nullable();

            // Independent of Lead::assigned_to — defaults to it at creation
            // time (a one-time copy, not a live sync) but is separately
            // editable and is never touched by a later Lead reassignment.
            // See App\Services\LeadAssignmentService, which remains
            // entirely untouched by this feature.
            $table->foreignUlid('owner_id')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('tenant_users')->nullOnDelete();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'lead_id']);
            $table->index(['lead_id', 'status']);
            $table->index(['tenant_id', 'owner_id']);
            $table->index(['tenant_id', 'expected_close_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deals');
    }
};
