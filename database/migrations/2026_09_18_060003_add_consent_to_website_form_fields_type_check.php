<?php

use App\Enums\WebsiteFormFieldType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Same Postgres-CHECK-constraint-vs-SQLite-table-rewrite split every
     * prior enum-value addition in this codebase uses (e.g.
     * add_facebook_lead_ads_to_lead_sources_type_check.php) — Postgres
     * enforces website_form_fields.field_type via a named CHECK constraint
     * that must be dropped and recreated to widen; SQLite has no such
     * constraint to alter, only the column definition itself.
     */
    public function up(): void
    {
        $this->setFieldTypeConstraint(WebsiteFormFieldType::cases());
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->setFieldTypeConstraint(array_filter(
            WebsiteFormFieldType::cases(),
            fn (WebsiteFormFieldType $type): bool => $type !== WebsiteFormFieldType::Consent,
        ));
    }

    /**
     * @param  array<int, WebsiteFormFieldType>  $cases
     */
    private function setFieldTypeConstraint(array $cases): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $values = implode(',', array_map(fn (WebsiteFormFieldType $type): string => "'{$type->value}'", $cases));

            DB::statement('ALTER TABLE website_form_fields DROP CONSTRAINT website_form_fields_field_type_check');
            DB::statement("ALTER TABLE website_form_fields ADD CONSTRAINT website_form_fields_field_type_check CHECK (field_type IN ({$values}))");

            return;
        }

        Schema::table('website_form_fields', function (Blueprint $table) use ($cases) {
            $table->enum('field_type', array_column($cases, 'value'))->change();
        });
    }
};
