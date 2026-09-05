<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every non-admin agent's board/list query is really tenant_id + assigned_to
 * (see Lead::assignedTo() scope + FiltersLeadsQuery::applyAssignmentScope())
 * — the implicit single-column index MySQL/Postgres put on assigned_to for
 * its foreign key doesn't serve that composite filter as well as this does
 * as a tenant's lead volume grows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->index(['tenant_id', 'assigned_to']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'assigned_to']);
        });
    }
};
