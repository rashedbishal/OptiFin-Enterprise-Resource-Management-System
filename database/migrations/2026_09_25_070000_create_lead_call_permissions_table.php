<?php

use App\Enums\LeadCallPermissionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * WhatsApp Outbound Calling — a Lead's CURRENT effective WhatsApp call
     * permission state, one row per lead, upserted by both the live
     * GET .../call_permissions check and the call_permission_reply webhook
     * (App\Actions\Tenant\{CheckLeadCallPermission,RecordCallPermissionReply}).
     * Deliberately a plain string status column, no DB CHECK constraint —
     * same discipline as lead_calls.status, chosen specifically so a later
     * case addition (see CallStatus::Initiated, added this same session)
     * never needs a constraint-rewriting migration.
     *
     * "Never checked" is the absence of a row entirely (same "no row and
     * blank are the same state" discipline lead_consents already
     * established) — never a distinct status value here.
     */
    public function up(): void
    {
        Schema::create('lead_call_permissions', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // Denormalized, same discipline as every other lead-child table
            // in this module — every query filters tenant_id explicitly.
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignUlid('lead_source_id')->constrained('lead_sources')->cascadeOnDelete();

            $table->string('status', 16)->default(LeadCallPermissionStatus::NoPermission->value);

            // Meta's own verbatim permission.status ("no_permission" |
            // "temporary" | "permanent") from the last live check, stored
            // raw and never branched on — same discipline as
            // lead_calls.meta_status/lead_sources.calling_meta_status.
            $table->string('meta_raw_status', 32)->nullable();

            // From permission.expiration_time (a live check) or
            // interactive.call_permission_reply.expiration_timestamp (the
            // webhook) — null for NoPermission/Permanent/Rejected. Meta
            // sends no webhook when a temporary permission expires, so an
            // expired-but-not-yet-rechecked row is a normal, expected state:
            // every consequential action (a request, a connect attempt)
            // re-derives "effectively granted" from this column against
            // now(), and additionally re-verifies live before ever calling
            // Meta — this column alone is never trusted for that decision.
            $table->timestamp('expires_at')->nullable();

            // When the local row was last updated FROM a source of truth —
            // either a live GET .../call_permissions or an accepted/rejected
            // webhook reply. UI-freshness only; never itself the gate before
            // a consequential Meta action (see the class docblock).
            $table->timestamp('last_synced_at')->nullable();

            // When a call_permission_request template was last sent — UI
            // copy only ("request pending"). The actual re-request gate is
            // always Meta's own live actions[].limits[], never this column.
            $table->timestamp('last_requested_at')->nullable();

            $table->timestamps();

            // One row IS the current effective state for this lead.
            $table->unique('lead_id');
            $table->index(['tenant_id', 'lead_source_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_call_permissions');
    }
};
