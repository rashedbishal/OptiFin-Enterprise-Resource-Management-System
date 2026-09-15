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
            // Additive-only: null on every existing message until a resend
            // actually happens. Set once, on the ORIGINAL failed row, the
            // moment App\Livewire\Tenant\Leads\Show::resendMessage() creates
            // its resend — never on the resend row itself, and never
            // updated again afterward (App\Models\Message rows are never
            // individually deleted, so nullOnDelete is a defensive default
            // mirroring `sent_by`'s own on-delete convention, not a path
            // this module currently exercises).
            $table->foreignUlid('resend_message_id')->nullable()->after('failure_reason')
                ->constrained('messages')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resend_message_id');
        });
    }
};
