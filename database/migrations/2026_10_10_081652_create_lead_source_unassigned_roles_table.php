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
        // A role×lead-source grant: an active TenantUser holding this role
        // can see and work an unassigned lead from this specific source
        // (see LeadSource::unassignedRoles()/Role::unassignedLeadSources()),
        // without ever being able to claim it themselves — assignment stays
        // Client-Admin-only (LeadPolicy::assign() is untouched). Deliberately
        // per lead-source row, not per LeadSourceType, matching the
        // tenant's own Lead Sources table granularity.
        Schema::create('lead_source_unassigned_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('lead_source_id')->constrained('lead_sources')->cascadeOnDelete();
            $table->foreignUlid('role_id')->constrained('roles')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['lead_source_id', 'role_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_source_unassigned_roles');
    }
};
