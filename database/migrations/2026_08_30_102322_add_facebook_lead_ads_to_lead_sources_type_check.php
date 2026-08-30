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
     * Instagram type addition — see that migration's note.
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
            fn (LeadSourceType $type): bool => $type !== LeadSourceType::FacebookLeadAds,
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
