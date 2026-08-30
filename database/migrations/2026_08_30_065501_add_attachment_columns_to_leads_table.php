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
        Schema::table('leads', function (Blueprint $table) {
            $table->string('attachment_path')->nullable()->after('html_body');
            $table->string('attachment_type')->nullable()->after('attachment_path');
            $table->string('attachment_mime')->nullable()->after('attachment_type');
            $table->text('original_media_url')->nullable()->after('attachment_mime');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['attachment_path', 'attachment_type', 'attachment_mime', 'original_media_url']);
        });
    }
};
