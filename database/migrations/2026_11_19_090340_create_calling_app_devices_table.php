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
        Schema::create('calling_app_devices', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // Denormalized, same discipline as every other tenant-scoped
            // table — every query filters tenant_id explicitly, and the
            // cascade keeps DeleteTenant's database-level wipe complete.
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // One paired phone per user (product decision): re-pairing
            // replaces the row rather than adding a second one.
            $table->foreignUlid('tenant_user_id')->unique()->constrained('tenant_users')->cascadeOnDelete();

            // The Sanctum bearer token this device authenticates with —
            // unpairing deletes the token, which cascades this row away too.
            $table->foreignId('personal_access_token_id')->unique()->constrained('personal_access_tokens')->cascadeOnDelete();

            // Free-text label the app reports at pairing time (e.g. the
            // phone model) — display only, never trusted for anything.
            $table->string('device_name', 100)->nullable();

            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calling_app_devices');
    }
};
