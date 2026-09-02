<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_detail_update_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignUlid('changed_by')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->json('changed_fields');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_detail_update_logs');
    }
};
