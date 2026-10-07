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
        Schema::table('messages', function (Blueprint $table) {
            // A frozen snapshot of the WhatsAppMessageTemplate actually
            // used to send this one message_type=Template row — never a
            // live FK back to that catalog, which a tenant could rename or
            // deactivate later. Null for every other message_type.
            $table->string('template_name')->nullable()->after('message_type');
            $table->string('template_language')->nullable()->after('template_name');
            $table->json('template_variables')->nullable()->after('template_language');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['template_name', 'template_language', 'template_variables']);
        });
    }
};
