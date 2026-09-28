<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Closes a genuine, confirmed race in CreateManualLead and
     * LeadImports\Index, each of which does `LeadSource::firstOrCreate(
     * ['tenant_id' => ..., 'type' => ...], ...)` to lazily provision the
     * tenant's ONE shared source for exactly two types: Manual, CsvImport —
     * confirmed singleton-per-tenant by this app's own first-party
     * documentation (LeadSourceType's own case comments: "the single
     * per-tenant source... lazily created on first use") AND by the fact
     * that neither is ever independently creatable any other way (both are
     * excluded from Platform\Clients\LeadSources::typeOptions(), so the
     * Platform Admin panel can never create a second one). Two concurrent
     * first-ever requests for a tenant (its first manual lead, first CSV
     * import) could both pass the "does one exist" check before either
     * commits, producing two rows and silently fragmenting that tenant's
     * attribution/reporting for that type forever.
     *
     * WebsiteForm (also handled by a firstOrCreate() of this same shape, in
     * WebsiteForms\Index) is deliberately EXCLUDED despite WebsiteForm::
     * leadSource()'s own docblock describing it as "the tenant's ONE shared
     * ... LeadSource" — that describes the Website Form BUILDER's own
     * lazy-creation intent, not an app-wide invariant: WebsiteForm IS
     * independently creatable through the Platform Admin panel (no
     * restriction in LeadSources::save(), unlike Manual/CsvImport), and an
     * unique(tenant_id, singleton_type) index that included it broke well
     * over a hundred existing tests that already create multiple
     * WebsiteForm-type sources per tenant for unrelated scenarios — concrete
     * evidence the wider system does not actually treat "at most one" as an
     * enforced rule for this type. See LeadSource::SINGLETON_TYPES's own
     * docblock for the full reasoning.
     *
     * Every OTHER LeadSourceType (FacebookMessenger, FacebookLeadAds,
     * Whatsapp, Instagram, Email, LinkedInLeadGen, and now WebsiteForm) is
     * confirmed to legitimately allow multiple sources per tenant —
     * Platform\Clients\LeadSources::save() imposes no uniqueness restriction
     * for any of them, and an existing, already-passing test
     * (LeadSourceCostReportingTest.php) exercises 5 FacebookLeadAds sources
     * for one tenant as normal, expected behavior. A blanket
     * unique(tenant_id, type) would have broken all of those — this must
     * restrict only the two confirmed singletons.
     *
     * Deliberately NOT a Postgres partial unique index (`... where type in
     * (...)`, the same shape as platform_users_single_mother_admin) despite
     * that precedent already existing in this codebase: CLAUDE.md's own words
     * confirm Postgres-specific behavior "is not exercised by tests — verify
     * it by hand", and this fix specifically needs a REAL, SQLite-executable
     * regression test proving the constraint blocks a genuine concurrent
     * duplicate (see the accompanying test), not just a manually-verified
     * assumption. Instead: a nullable `singleton_type` column, populated
     * (via LeadSource's own `creating` model event — see that class) with the
     * type's own value ONLY for the two singleton types and left NULL for
     * every other type, backed by a perfectly ordinary, fully portable
     * unique(tenant_id, singleton_type) index — standard SQL treats every
     * NULL as distinct from every other NULL in a unique index, on SQLite
     * and Postgres alike, so every multi-source type is completely
     * unaffected while the two singleton types are genuinely, testably
     * enforced.
     */
    public function up(): void
    {
        Schema::table('lead_sources', function (Blueprint $table) {
            $table->string('singleton_type', 32)->nullable()->after('type');
        });

        DB::table('lead_sources')
            ->whereIn('type', ['manual', 'csv_import'])
            ->update(['singleton_type' => DB::raw('type')]);

        Schema::table('lead_sources', function (Blueprint $table) {
            $table->unique(['tenant_id', 'singleton_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lead_sources', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'singleton_type']);
            $table->dropColumn('singleton_type');
        });
    }
};
