<?php

use App\Enums\LeadAttachmentStatus;
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
        Schema::table('lead_attachments', function (Blueprint $table) {
            // Nullable now: a row is created as soon as a message with an
            // attachment arrives (status=downloading), before the download
            // itself starts — the path is only known once it finishes.
            $table->string('path')->nullable()->change();

            $table->enum('status', array_column(LeadAttachmentStatus::cases(), 'value'))
                ->default(LeadAttachmentStatus::Downloaded->value)
                ->after('original_media_url');
            $table->text('failure_reason')->nullable()->after('status');
        });

        // Every existing row already has a downloaded file, so the default
        // above (Downloaded) is correct for them without a backfill pass.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lead_attachments', function (Blueprint $table) {
            $table->dropColumn(['status', 'failure_reason']);
            $table->string('path')->nullable(false)->change();
        });
    }
};
