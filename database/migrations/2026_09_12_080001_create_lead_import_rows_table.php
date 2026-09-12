<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_import_rows', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('lead_import_id')->constrained('lead_imports')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('status');
            $table->foreignUlid('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            // Populated via App\Services\LeadDuplicateDetectionService's own
            // normalizeEmail()/normalizePhone() — never a second
            // normalization implementation (see LeadCsvImport). Plain
            // indexed string columns rather than querying raw_data's JSON,
            // so an in-file duplicate lookup across up to 10,000 rows never
            // needs a JSON-path scan.
            $table->string('normalized_email')->nullable();
            $table->string('normalized_phone')->nullable();
            $table->json('raw_data');
            $table->json('errors')->nullable();
            $table->foreignUlid('duplicate_of_lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->timestamps();

            // Doubles as the retry/idempotency guard (a chunk job retried
            // after a partial failure re-checks "does this row_number
            // already exist for this import" before processing) and the
            // detail page's natural read order.
            $table->unique(['lead_import_id', 'row_number']);
            $table->index(['lead_import_id', 'normalized_email']);
            $table->index(['lead_import_id', 'normalized_phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_import_rows');
    }
};
