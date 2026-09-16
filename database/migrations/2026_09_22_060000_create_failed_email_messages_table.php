<?php

use App\Enums\EmailPartialFailureStage;
use App\Enums\FailedEmailMessageStatus;
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
        Schema::create('failed_email_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('lead_source_id')->constrained('lead_sources')->cascadeOnDelete();

            // Stable identity — see the migration's own docblock below for
            // why both are captured and how uid/uidvalidity together (never
            // uid alone) are the safe replay key.
            $table->string('message_id')->nullable();
            $table->unsignedInteger('imap_uid');
            $table->unsignedInteger('uidvalidity');

            $table->enum('status', FailedEmailMessageStatus::values())->default(FailedEmailMessageStatus::Failed->value);
            $table->unsignedInteger('attempts')->default(1);

            // Informational only (see FailedEmailMessage's own docblock) —
            // never used to branch retry logic, only to help a human triage
            // why something failed.
            $table->string('failure_class')->nullable();
            $table->text('failure_reason')->nullable();

            // Set only when the Lead itself was already durably
            // created/appended before a later step (event dispatch,
            // attachment saving) failed — see EmailMessagePartiallyProcessed.
            // A non-null value here changes what a retry attempts: only the
            // attachment/event step, never Lead::create()/append again.
            $table->foreignUlid('partial_lead_id')->nullable()->constrained('leads')->nullOnDelete();

            // EMAIL-LOSS-1 (event-replay fix) — which post-persist step
            // failed (see App\Enums\EmailPartialFailureStage) and whether
            // the original operation was a create or an append (needed so
            // a retry that must re-fire the event fires the CORRECT event
            // class). Both nullable: meaningless whenever partial_lead_id
            // is null (the failure happened before any Lead write), and
            // for any row written before this fix existed.
            $table->enum('partial_stage', EmailPartialFailureStage::values())->nullable();
            $table->boolean('is_new_lead')->nullable();

            // Set only once status transitions to Recovered.
            $table->foreignUlid('resolved_lead_id')->nullable()->constrained('leads')->nullOnDelete();

            $table->timestamp('first_failed_at');
            $table->timestamp('last_attempted_at');
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('recovered_at')->nullable();

            // Subject only — never the message body/HTML/attachments
            // themselves. Enough for a human to recognize which email this
            // is during triage, matching Lead.source_data's own existing
            // subject-only precedent for this same channel; deliberately not
            // a larger content snapshot (see this migration's own docblock).
            $table->string('subject')->nullable();
            $table->string('from_email')->nullable();

            $table->timestamps();

            // The safe replay identity: a UID is only meaningful in
            // combination with the UIDVALIDITY it was observed under (RFC
            // 3501) — never globally unique on its own, and never assumed
            // stable if UIDVALIDITY has since changed (see
            // ProcessEmailLeadSource::handleRetries()). message_id is
            // deliberately NOT part of this unique constraint: it can be
            // missing/malformed on some senders' mail (see
            // ProcessEmailLeadSource::normalizeMessageId()), which a
            // unique index cannot safely enforce across multiple nulls
            // meaning "distinct real messages," not "duplicates."
            $table->unique(['lead_source_id', 'uidvalidity', 'imap_uid']);
            $table->index('message_id');
            $table->index(['status', 'next_retry_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('failed_email_messages');
    }
};
