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
        // Hides one outbound message from the full-page Activity Log card's
        // Sent/Failed/Resend list only — the message row itself, and its
        // bubble in the main conversation thread, are completely untouched.
        // Deliberately not a delete: this app never soft-deletes a Message
        // (DeleteLeadConversation hard-deletes every row for a real wipe),
        // and this isn't that — it's a per-viewer-list visibility flag, Set
        // by App\Livewire\Tenant\Leads\Show::hideOutboundMessage(),
        // Client-Admin-only.
        Schema::table('messages', function (Blueprint $table) {
            $table->timestamp('activity_log_hidden_at')->nullable()->after('resend_message_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('activity_log_hidden_at');
        });
    }
};
