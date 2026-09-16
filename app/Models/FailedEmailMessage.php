<?php

namespace App\Models;

use App\Enums\EmailPartialFailureStage;
use App\Enums\FailedEmailMessageStatus;
use Database\Factories\FailedEmailMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * EMAIL-LOSS-1 — a durable record of one inbound email message that failed
 * App\Jobs\ProcessEmailLeadSource::createLeadFromEmail() at least once, so a
 * transient failure (a DB deadlock, a momentary connection blip) does not
 * become permanently unrecoverable just because the message was marked
 * \Seen — see that job's own docblock for the full \Seen-timing reasoning.
 *
 * Written only on failure — there is no row for a message that succeeded on
 * its first attempt, and no "pending" row written before an outcome is
 * known. Never deleted by the application: a row that reaches
 * FailedEmailMessageStatus::Exhausted stays visible as a permanent record
 * that this message was ultimately unrecoverable, for operator triage via
 * direct DB/query access (no admin UI exists for this in this phase).
 *
 * Deliberately stores identifiers and failure metadata only — never the raw
 * message body, HTML, or attachment binary (see subject/from_email below,
 * both already unencrypted-equivalent to what Lead.source_data/Lead.email
 * already store for a successfully-processed email from this same channel).
 * A retry re-fetches the real message from the mailbox by uid/uidvalidity
 * rather than replaying from a stored copy.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $lead_source_id
 * @property string|null $message_id
 * @property int $imap_uid
 * @property int $uidvalidity
 * @property FailedEmailMessageStatus $status
 * @property int $attempts
 * @property string|null $failure_class
 * @property string|null $failure_reason
 * @property string|null $partial_lead_id
 * @property EmailPartialFailureStage|null $partial_stage
 * @property bool|null $is_new_lead
 * @property string|null $resolved_lead_id
 * @property Carbon $first_failed_at
 * @property Carbon $last_attempted_at
 * @property Carbon|null $next_retry_at
 * @property Carbon|null $recovered_at
 * @property string|null $subject
 * @property string|null $from_email
 * @property-read Tenant $tenant
 * @property-read LeadSource $leadSource
 * @property-read Lead|null $partialLead
 * @property-read Lead|null $resolvedLead
 */
#[Fillable([
    'tenant_id', 'lead_source_id', 'message_id', 'imap_uid', 'uidvalidity',
    'status', 'attempts', 'failure_class', 'failure_reason', 'partial_lead_id', 'partial_stage', 'is_new_lead', 'resolved_lead_id',
    'first_failed_at', 'last_attempted_at', 'next_retry_at', 'recovered_at', 'subject', 'from_email',
])]
class FailedEmailMessage extends Model
{
    /** @use HasFactory<FailedEmailMessageFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => FailedEmailMessageStatus::class,
            'imap_uid' => 'integer',
            'uidvalidity' => 'integer',
            'attempts' => 'integer',
            'partial_stage' => EmailPartialFailureStage::class,
            'is_new_lead' => 'boolean',
            'first_failed_at' => 'datetime',
            'last_attempted_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'recovered_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<LeadSource, $this>
     */
    public function leadSource(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class);
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function partialLead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'partial_lead_id');
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function resolvedLead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'resolved_lead_id');
    }
}
