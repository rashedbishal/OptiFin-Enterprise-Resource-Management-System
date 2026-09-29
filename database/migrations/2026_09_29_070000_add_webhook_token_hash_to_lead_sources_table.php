<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * F1 remediation (forensic audit Batch A) — an opaque, per-source
     * webhook credential for the four Meta-delivered LeadSourceTypes
     * (FacebookMessenger, FacebookLeadAds, Whatsapp, Instagram), mirroring
     * WebsiteForm.token_hash exactly: only the SHA-256 hash of a
     * Str::random(40) token is ever stored, the plaintext is shown once at
     * generation time and never persisted, and comparison is a plain
     * indexed hash-equality lookup (see WebsiteForm's own docblock for why
     * a fast, deterministic hash — not bcrypt/argon2 — is deliberate here:
     * the token itself carries the entropy).
     *
     * Nullable, unlike WebsiteForm.token_hash (which is always set at
     * creation): every LeadSource that exists before this migration runs,
     * and every non-Meta-type LeadSource forever, has no token and must
     * keep working exactly as today via the existing tenant_code-only
     * webhook route — this column is populated lazily, the next time an
     * admin creates or re-saves a Meta-type source, not backfilled here.
     * Globally unique (not scoped to tenant_id or type): the incoming
     * webhook request resolves a source by hash alone first, then
     * cross-checks tenant/type match, the same lookup shape
     * resolveWebsiteForm() already uses — a NULL value never collides with
     * another NULL under standard SQL unique-index semantics (the same
     * precedent already established by
     * 2026_09_28_070000_add_singleton_type_unique_to_lead_sources_table.php's
     * own singleton_type column), so this adds zero constraint on every
     * source that has none yet.
     */
    public function up(): void
    {
        Schema::table('lead_sources', function (Blueprint $table) {
            $table->string('webhook_token_hash')->nullable()->unique()->after('config');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lead_sources', function (Blueprint $table) {
            $table->dropUnique(['webhook_token_hash']);
            $table->dropColumn('webhook_token_hash');
        });
    }
};
