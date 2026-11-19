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
        // Platform-wide (vendor-level, NOT per tenant) settings managed in the
        // admin panel — first user: the calling app's Firebase service
        // account. Values are always stored encrypted (see PlatformSetting).
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('key', 100)->unique();
            $table->longText('value')->nullable();
            $table->foreignUlid('updated_by')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
