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
        // Laravel's own default pivot table name for Lead<->LeadTag
        // (alphabetical order of the two singular model snake names) — no
        // explicit table name needed on either belongsToMany() call.
        Schema::create('lead_lead_tag', function (Blueprint $table) {
            $table->foreignUlid('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignUlid('lead_tag_id')->constrained('lead_tags')->cascadeOnDelete();

            // Who attached this tag to this lead — nullable/null-on-delete,
            // same audit-without-hard-dependency pattern as
            // LeadAssignment::assigned_by/LeadDetailUpdateLog::changed_by.
            $table->foreignUlid('tagged_by')->nullable()->constrained('tenant_users')->nullOnDelete();

            $table->timestamps();

            // Prevents a duplicate Lead<->LeadTag pivot row — attach logic
            // (Show::attachTag(), LeadMergeService's tag union) always
            // checks first, but this is the real data-integrity backstop
            // under concurrent requests, same reasoning as every other
            // unique() constraint backing a pre-check in this module.
            $table->unique(['lead_id', 'lead_tag_id']);

            // Supports both the tag catalog's usage-count query (Sec.
            // "delete-in-use" confirmation) and the tag filter's whereHas()
            // lookup.
            $table->index('lead_tag_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_lead_tag');
    }
};
