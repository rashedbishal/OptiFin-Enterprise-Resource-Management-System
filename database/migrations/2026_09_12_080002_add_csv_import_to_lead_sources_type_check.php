<?php

use App\Enums\LeadSourceType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Same Postgres-CHECK-constraint-vs-SQLite-table-rewrite split as the
     * Facebook Lead Ads / Instagram / LinkedIn / Manual type additions -
     * see those migrations' notes. Missed when LeadSourceType::CsvImport
     * was first added (Feature #7) - SQLite (the test suite's driver)
     * doesn't enforce this the same way Postgres's CHECK constraint does,
     * so the whole test suite passed while a real Postgres insert of a
     * CsvImport-typed LeadSource would fail outright. Caught by a manual
     * smoke test against the real dev database, not by any automated test.
     */
    public function up(): void
    {
        $this->setTypeConstraint(LeadSourceType::cases());
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->setTypeConstraint(array_filter(
            LeadSourceType::cases(),
            fn (LeadSourceType $type): bool => $type !== LeadSourceType::CsvImport,
        ));
    }

    /**
     * @param  array<int, LeadSourceType>  $cases
     */
    private function setTypeConstraint(array $cases): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $values = implode(',', array_map(fn (LeadSourceType $type): string => "'{$type->value}'", $cases));

            DB::statement('ALTER TABLE lead_sources DROP CONSTRAINT lead_sources_type_check');
            DB::statement("ALTER TABLE lead_sources ADD CONSTRAINT lead_sources_type_check CHECK (type IN ({$values}))");

            return;
        }

        Schema::table('lead_sources', function (Blueprint $table) use ($cases) {
            $table->enum('type', array_column($cases, 'value'))->change();
        });
    }
};
