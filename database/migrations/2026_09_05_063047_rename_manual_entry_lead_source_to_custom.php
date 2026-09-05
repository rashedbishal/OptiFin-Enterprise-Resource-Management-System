<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The lazily-provisioned per-tenant Manual lead source (see
     * App\Actions\Tenant\CreateManualLead) was named "Manual Entry" until
     * now — renamed to "Custom" to match LeadSourceType::Manual::label().
     * Only ever touches rows still carrying the original default name, so a
     * tenant that somehow already has a source named "Custom" isn't
     * double-touched, and this stays a no-op if re-run.
     */
    public function up(): void
    {
        DB::table('lead_sources')
            ->where('type', 'manual')
            ->where('name', 'Manual Entry')
            ->update(['name' => 'Custom']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('lead_sources')
            ->where('type', 'manual')
            ->where('name', 'Custom')
            ->update(['name' => 'Manual Entry']);
    }
};
