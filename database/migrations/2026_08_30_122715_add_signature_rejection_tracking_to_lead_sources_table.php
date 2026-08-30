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
        Schema::table('lead_sources', function (Blueprint $table) {
            // A webhook signature rejection happens on Meta's request, with
            // no admin browser session attached to show a live toast to —
            // this is surfaced instead the next time an admin opens this
            // source's tenant's Lead Sources page, then cleared (see
            // LeadSources::flashSignatureRejections()), so it's a one-time
            // notice rather than a banner that lingers forever.
            $table->timestamp('last_signature_rejection_at')->nullable();
            $table->string('last_signature_rejection_reason')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lead_sources', function (Blueprint $table) {
            $table->dropColumn(['last_signature_rejection_at', 'last_signature_rejection_reason']);
        });
    }
};
