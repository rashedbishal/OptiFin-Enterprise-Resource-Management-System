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
        Schema::table('messenger_message_templates', function (Blueprint $table) {
            // One template per Messenger source, period — a deliberate
            // product simplification over Meta's own "100s per Page"
            // allowance: change the wording by deleting the current one
            // (DeleteMessengerMessageTemplate, which also removes it from
            // Meta) and creating a replacement, never by having two active
            // at once. Replaces the (lead_source_id, name, language)
            // uniqueness the table started with.
            $table->dropUnique('messenger_message_templates_lead_source_id_name_language_unique');
            $table->unique('lead_source_id');

            // The actual approved message text — previously only
            // name/language/variable_count were kept, so "viewing" a
            // template meant nothing beyond metadata. Nullable: a
            // tenant's own manual registration (a template that already
            // exists on Meta some other way) never collects this.
            $table->text('body_text')->nullable()->after('language');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messenger_message_templates', function (Blueprint $table) {
            $table->dropColumn('body_text');
            $table->dropUnique(['lead_source_id']);
            $table->unique(['lead_source_id', 'name', 'language']);
        });
    }
};
