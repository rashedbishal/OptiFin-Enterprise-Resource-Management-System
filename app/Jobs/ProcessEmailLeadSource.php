<?php

namespace App\Jobs;

use App\Enums\EmailPartialFailureStage;
use App\Enums\FailedEmailMessageStatus;
use App\Enums\LeadAttachmentStatus;
use App\Events\LeadReceived;
use App\Events\LeadUpdated;
use App\Exceptions\EmailMessagePartiallyProcessed;
use App\Models\FailedEmailMessage;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Tenant;
use App\Services\ImapConnectionHealthMonitor;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use IMAP\Connection;
use RuntimeException;
use Symfony\Component\Mime\MimeTypes;
use Throwable;

class ProcessEmailLeadSource implements ShouldQueue
{
    use Queueable;

    /**
     * Bumped from the framework default (1) to tolerate WithoutOverlapping
     * below releasing this job back onto the queue a time or two while a
     * previous run for the same source is still in flight — that's normal
     * contention (see middleware()), not a real failure, and shouldn't
     * exhaust retries and get this job marked permanently failed.
     */
    public int $tries = 3;

    /**
     * Cap on unseen messages processed per run. Unbounded processing on a
     * large inbox can outrun the queue worker's timeout, which kills the
     * process before it reaches the last_synced_at update below — leaving
     * the source permanently "due" and causing duplicate re-dispatch every
     * minute. Remaining unseen messages are picked up on the next run.
     */
    private const MAX_MESSAGES_PER_RUN = 25;

    /**
     * Matches the Messenger/Instagram attachment cap — a reasonable default
     * for inbound mail, and comfortably above what most mail providers
     * allow per attachment in the first place (Gmail caps at 25MB).
     */
    private const MAX_ATTACHMENT_BYTES = 25 * 1024 * 1024;

    /**
     * @var array<int, string>
     */
    private const IMAP_PRIMARY_TYPES = [
        0 => 'text', 1 => 'multipart', 2 => 'message', 3 => 'application',
        4 => 'audio', 5 => 'image', 6 => 'video', 7 => 'other',
    ];

    /**
     * EMAIL-LOSS-1 — how many total attempts (the original poll attempt
     * plus subsequent leads:retry-failed-emails attempts) a failed message
     * gets before its FailedEmailMessage row is marked Exhausted and never
     * retried again. Deliberately small and uniform across every failure
     * class (see FailedEmailMessage's own docblock — failure_class is
     * informational only, never used to shortcut this ceiling): a message
     * that fails the same way 5 times, cheaply and without any IMAP-side
     * risk each attempt, has told us everything a 6th identical attempt
     * would.
     */
    private const MAX_RETRY_ATTEMPTS = 5;

    /**
     * EMAIL-LOSS-1 — retry backoff in minutes, multiplied by the attempt
     * count already made (attempt 1 failed -> retry in 15m; attempt 2
     * failed -> retry in 30m; ...). Matches this source's own default
     * fetch_interval_minutes (15) as the smallest reasonable spacing —
     * retrying faster than the tenant's own configured poll cadence would
     * gain nothing, since nothing about the failure's cause would have
     * changed that quickly.
     */
    private const RETRY_BACKOFF_MINUTES = 15;

    /**
     * IMAP fetches over a slow or throttled connection can occasionally push
     * a full batch past the queue worker's default 60s timeout even at the
     * message cap above; this gives it headroom instead of failing outright.
     */
    public int $timeout = 180;

    /**
     * EMAIL-LOSS-1 — $retryFailedMessageIds is null for every normal
     * leads:fetch-emails-dispatched run (unchanged default behavior: poll
     * UNSEEN). App\Console\Commands\RetryFailedEmailMessages is the only
     * caller that ever passes a populated list, switching handle() to
     * handleRetries() instead of the UNSEEN search — see that method's own
     * docblock for why this still safely reuses middleware() below rather
     * than needing its own separate concurrency guard.
     *
     * @param  array<int, string>|null  $retryFailedMessageIds
     */
    public function __construct(public LeadSource $source, public ?array $retryFailedMessageIds = null) {}

    /**
     * Without this, a job that overruns its source's fetch_interval_minutes
     * (plausible on a slow IMAP connection near the 180s timeout above)
     * would let leads:fetch-emails' next tick dispatch a second run for the
     * same source before the first finishes — since last_synced_at only
     * updates at the very end of handle() — and two concurrent IMAP
     * sessions against the same mailbox could both see the same UNSEEN
     * message before either flags it \Seen, creating duplicate leads.
     * expireAfter matches $timeout: if a run dies without releasing the
     * lock, it can't hold it any longer than it would have been allowed to
     * run anyway.
     *
     * EMAIL-LOSS-1: keyed on $this->source->id regardless of whether this
     * is a normal poll or a retry run — a retry run for a source and a
     * normal poll run for the SAME source can therefore never execute
     * concurrently either, which is exactly the guarantee handleRetries()
     * relies on instead of taking its own separate lock.
     *
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->source->id))->releaseAfter(30)->expireAfter($this->timeout)];
    }

    public function handle(): void
    {
        $config = $this->source->config ?? [];
        $mailbox = $this->mailboxConnectionString($config);

        $inbox = @imap_open($mailbox, $config['imap_username'] ?? '', $config['imap_password'] ?? '');

        if (! $inbox instanceof Connection) {
            $error = imap_last_error();

            // Drain the extension's internal alert/error stacks. Anything left
            // unread here surfaces as a PHP warning at request shutdown (outside
            // this suppressed scope), which Laravel turns into an ErrorException.
            imap_errors();
            imap_alerts();

            Log::error("Unable to connect to lead source [{$this->source->id}]: {$error}");

            // OPS-1 — count this connection failure so a persistent outage
            // raises a single, throttled alert. Observability only: it can
            // never throw and changes nothing about how the job proceeds.
            app(ImapConnectionHealthMonitor::class)->recordFailure($this->source);

            return;
        }

        app(ImapConnectionHealthMonitor::class)->recordSuccess($this->source);

        $uidvalidity = $this->mailboxUidValidity($inbox, $mailbox);

        if ($this->retryFailedMessageIds !== null) {
            $this->handleRetries($inbox, $uidvalidity);
        } else {
            $this->handleUnseenMessages($inbox, $uidvalidity);
        }

        imap_errors();
        imap_alerts();
        imap_close($inbox);

        // Retry runs deliberately never touch last_synced_at — it drives
        // FetchEmailLeads::isDue()'s normal poll cadence, and a retry run
        // advancing it would needlessly delay the next real poll.
        if ($this->retryFailedMessageIds === null) {
            $this->source->update(['last_synced_at' => now()]);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function mailboxConnectionString(array $config): string
    {
        return sprintf('{%s:%d/imap/ssl}INBOX', $config['imap_host'] ?? '', $config['imap_port'] ?? 993);
    }

    /**
     * EMAIL-LOSS-1 — a UID (see resolveUid()/handleRetries()) is only
     * meaningful in combination with the UIDVALIDITY it was observed under
     * (RFC 3501); a mailbox rebuild reassigns UIDVALIDITY, silently making
     * every previously-recorded UID potentially refer to a different
     * message. Captured once per run and stamped onto every new failure
     * record, so a later retry can detect a mismatch and refuse to replay
     * rather than risk touching the wrong message. Returns 0 (a value no
     * real mailbox uses) if the STATUS check itself fails — a genuinely
     * new failure recorded with uidvalidity=0 simply can never match a
     * later real UIDVALIDITY, which fails a future retry closed (refuses
     * to replay) rather than open, the safe direction for this check.
     */
    private function mailboxUidValidity(Connection $inbox, string $mailbox): int
    {
        $status = imap_status($inbox, $mailbox, SA_UIDVALIDITY);

        return $status !== false ? (int) $status->uidvalidity : 0;
    }

    /**
     * The normal poll path — unchanged in spirit from before EMAIL-LOSS-1:
     * search UNSEEN, cap at MAX_MESSAGES_PER_RUN, process each. What
     * changed: a per-message failure is now durably recorded (see
     * tryRecordNewFailure()) BEFORE \Seen is applied, not just logged —
     * and \Seen is only applied at all once that recording has actually
     * succeeded, so a message can never end up \Seen with no corresponding
     * local recovery record (the one ordering EMAIL-LOSS-1's own Decision
     * Brief called critical). If recording itself fails, the message is
     * deliberately left unseen — a rare, doubly-unlucky case, but the
     * normal next scheduled run trying again is strictly safer than
     * silently losing the only trace that anything happened.
     */
    private function handleUnseenMessages(Connection $inbox, int $uidvalidity): void
    {
        $messageNumbers = imap_search($inbox, 'UNSEEN') ?: [];
        sort($messageNumbers);
        $messageNumbers = array_slice($messageNumbers, 0, self::MAX_MESSAGES_PER_RUN);

        foreach ($messageNumbers as $messageNumber) {
            $messageNumber = (int) $messageNumber;
            $header = imap_headerinfo($inbox, $messageNumber);

            try {
                if ($header === false) {
                    throw new RuntimeException("Unable to read headers for message [{$messageNumber}] on lead source [{$this->source->id}].");
                }

                $this->createLeadFromEmail($inbox, $messageNumber, $header);
            } catch (Throwable $e) {
                Log::error("Failed to process email message [{$messageNumber}] on lead source [{$this->source->id}]: {$e->getMessage()}");

                if (! $this->tryRecordNewFailure($inbox, $messageNumber, $uidvalidity, $header !== false ? $header : null, $e)) {
                    continue;
                }
            }

            // Flagged \Seen on both success and (now-durably-recorded)
            // failure — a poison message that can't be parsed won't
            // suddenly parse successfully on a later run, so leaving it
            // unseen would mean re-fetching and re-failing on the exact
            // same message every normal poll run forever, consuming a
            // MAX_MESSAGES_PER_RUN slot and re-logging indefinitely.
            // Recovery for a genuinely transient failure now happens
            // through the dedicated leads:retry-failed-emails path
            // instead of the normal UNSEEN search — see
            // FailedEmailMessage's own docblock.
            imap_setflag_full($inbox, (string) $messageNumber, '\\Seen');
        }
    }

    /**
     * EMAIL-LOSS-1 — durably records a brand-new failure (the message has
     * no prior FailedEmailMessage row) so leads:retry-failed-emails can
     * later find it. Resolves the message's UID lazily, only once a
     * failure has actually occurred — the common (successful) case never
     * pays for this extra IMAP round trip.
     *
     * @return bool true if the failure was durably recorded (safe to mark
     *              \Seen — see handleUnseenMessages()'s own docblock),
     *              false if it could not be, in which case the caller must
     *              leave the message unseen.
     */
    private function tryRecordNewFailure(Connection $inbox, int $messageNumber, int $uidvalidity, ?object $header, Throwable $e): bool
    {
        $uid = imap_uid($inbox, $messageNumber);

        if ($uid === false || $uid === 0) {
            Log::error("Could not resolve a UID for message [{$messageNumber}] on lead source [{$this->source->id}]; its failure cannot be durably recorded. Leaving it unseen so the next scheduled run retries it.");

            return false;
        }

        $partialLeadId = $e instanceof EmailMessagePartiallyProcessed ? $e->leadId : null;
        // EMAIL-LOSS-1 (event-replay fix): captured alongside partialLeadId
        // so a retry can tell an event-dispatch failure apart from an
        // attachment failure and, when it's the event, fire the correct
        // event class — see EmailMessagePartiallyProcessed's own docblock.
        $partialStage = $e instanceof EmailMessagePartiallyProcessed ? $e->stage : null;
        $isNewLead = $e instanceof EmailMessagePartiallyProcessed ? $e->isNewLead : null;
        $reason = Str::limit($e->getMessage(), 1000);
        $messageId = $header !== null ? $this->normalizeMessageId($header->message_id ?? null) : null;
        $from = $header->from[0] ?? null;

        try {
            // updateOrCreate rather than a bare create(): the unique
            // (lead_source_id, uidvalidity, imap_uid) key means a message
            // that is somehow re-encountered here (e.g. imap_setflag_full()
            // silently failed to actually apply on a prior run — PHP's IMAP
            // functions return bool, not an exception, on failure) safely
            // increments the existing record instead of throwing a
            // constraint violation.
            $existing = FailedEmailMessage::where('lead_source_id', $this->source->id)
                ->where('uidvalidity', $uidvalidity)
                ->where('imap_uid', $uid)
                ->first();

            if ($existing !== null) {
                $existing->update([
                    'attempts' => $existing->attempts + 1,
                    'status' => FailedEmailMessageStatus::Failed,
                    'failure_class' => $this->classifyFailure($header, $e),
                    'failure_reason' => $reason,
                    'partial_lead_id' => $partialLeadId,
                    'partial_stage' => $partialStage,
                    'is_new_lead' => $isNewLead,
                    'last_attempted_at' => now(),
                    'next_retry_at' => now()->addMinutes(self::RETRY_BACKOFF_MINUTES * ($existing->attempts + 1)),
                ]);
            } else {
                FailedEmailMessage::create([
                    'tenant_id' => $this->source->tenant_id,
                    'lead_source_id' => $this->source->id,
                    'message_id' => $messageId,
                    'imap_uid' => $uid,
                    'uidvalidity' => $uidvalidity,
                    'status' => FailedEmailMessageStatus::Failed,
                    'attempts' => 1,
                    'failure_class' => $this->classifyFailure($header, $e),
                    'failure_reason' => $reason,
                    'partial_lead_id' => $partialLeadId,
                    'partial_stage' => $partialStage,
                    'is_new_lead' => $isNewLead,
                    'first_failed_at' => now(),
                    'last_attempted_at' => now(),
                    'next_retry_at' => now()->addMinutes(self::RETRY_BACKOFF_MINUTES),
                    'subject' => $header->subject ?? null,
                    'from_email' => $from !== null ? "{$from->mailbox}@{$from->host}" : null,
                ]);
            }

            return true;
        } catch (Throwable $recordingFailure) {
            Log::error("Additionally failed to durably record the above failure for message [{$messageNumber}] on lead source [{$this->source->id}]: {$recordingFailure->getMessage()}. Leaving this message unseen so the next scheduled run retries it.");

            return false;
        }
    }

    /**
     * EMAIL-LOSS-1 — lightweight, deliberately not a rule engine (see this
     * job's own Decision Brief §9): an unreadable header can never succeed
     * on retry (the message's own structure hasn't changed), so it's
     * classified distinctly; everything else is left to a plain,
     * already-existing Laravel utility (ConcurrencyErrorDetector, the same
     * one Illuminate\Database\Concerns\ManagesTransactions itself uses) for
     * the one class of failure this session has directly, repeatedly
     * proven is genuinely transient. Never used to change the retry
     * ceiling itself — purely informational, for operator triage.
     */
    private function classifyFailure(?object $header, Throwable $e): string
    {
        if ($header === null) {
            return 'permanent_unreadable_header';
        }

        if ((new ConcurrencyErrorDetector)->causedByConcurrencyError($e)) {
            return 'transient_db_concurrency';
        }

        if ($e instanceof QueryException) {
            return 'transient_db';
        }

        return 'unknown';
    }

    /**
     * RFC 5322 Message-ID values are sender-assigned and occasionally
     * missing or blank — never assumed present, never used as the sole
     * identity (see the failed_email_messages migration's own docblock:
     * the unique replay key is uid+uidvalidity, not this).
     */
    private function normalizeMessageId(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * EMAIL-LOSS-1 — processes a specific, bounded list of previously-failed
     * messages (by FailedEmailMessage id) instead of the normal UNSEEN
     * search. Deliberately reuses this job's own middleware() rather than
     * taking a new, separate lock: WithoutOverlapping is keyed on
     * $this->source->id regardless of retry-vs-normal mode, so only one
     * instance of this job — retry or normal poll — can ever be actively
     * handle()-ing for a given source at a time. That single guarantee is
     * exactly what Step 12 of the approved Implementation Approval asked
     * for ("reuse existing source-level coordination"), and is sufficient
     * on its own: it rules out two retry runs OR a retry run and a normal
     * poll ever touching the same mailbox concurrently. The one additional
     * safeguard kept is a cheap, defensive re-check of each record's own
     * status inside retryOneMessage() — normally redundant given the
     * guarantee above, kept only in case a future caller ever bypasses the
     * queue/middleware path.
     */
    private function handleRetries(Connection $inbox, int $currentUidValidity): void
    {
        $records = FailedEmailMessage::query()
            ->whereIn('id', $this->retryFailedMessageIds ?? [])
            ->where('status', FailedEmailMessageStatus::Failed)
            ->get();

        foreach ($records as $record) {
            $this->retryOneMessage($inbox, $currentUidValidity, $record);
        }
    }

    private function retryOneMessage(Connection $inbox, int $currentUidValidity, FailedEmailMessage $record): void
    {
        if ($record->fresh()?->status !== FailedEmailMessageStatus::Failed) {
            return;
        }

        if ($record->uidvalidity !== $currentUidValidity) {
            $this->exhaustRecord($record, 'The mailbox UID validity changed since this failure was recorded; the original message can no longer be safely located.');

            return;
        }

        $messageNumber = imap_msgno($inbox, $record->imap_uid);

        if ($messageNumber === 0) {
            $this->exhaustRecord($record, 'The original message could no longer be found in the mailbox (deleted or moved since this failure was recorded).');

            return;
        }

        $header = imap_headerinfo($inbox, $messageNumber);

        if ($header === false) {
            $this->recordRetryFailure($record, 'Unable to re-read headers for this message on retry.');
            imap_setflag_full($inbox, (string) $messageNumber, '\\Seen');

            return;
        }

        $currentMessageId = $this->normalizeMessageId($header->message_id ?? null);

        if ($record->message_id !== null && $currentMessageId !== null && $record->message_id !== $currentMessageId) {
            $this->exhaustRecord($record, 'The Message-ID at this UID no longer matches what was originally recorded; refusing to replay what may be a different message.');

            return;
        }

        try {
            if ($record->partial_lead_id !== null) {
                // The Lead/its text is already durably persisted from a
                // prior attempt (see EmailMessagePartiallyProcessed's own
                // docblock) — never Lead::create()/append again (would
                // duplicate the message text). See
                // eventAlreadyDispatchedFor()'s own docblock for exactly
                // when the event itself is/isn't re-fired.
                $lead = Lead::findOrFail($record->partial_lead_id);
                $structure = imap_fetchstructure($inbox, $messageNumber);
                $eventAlreadyDispatched = $this->eventAlreadyDispatchedFor($record);

                $this->runPostPersistSteps(
                    $lead,
                    $eventAlreadyDispatched,
                    (bool) $record->is_new_lead,
                    $inbox,
                    $messageNumber,
                    $structure,
                    $this->emailSentAt($header),
                );
            } else {
                $lead = $this->createLeadFromEmail($inbox, $messageNumber, $header);
            }

            // Only reached once every required post-persist step has
            // actually completed — runPostPersistSteps() throws (caught
            // below) if either the event or the attachments step is still
            // outstanding and fails again.
            $record->update([
                'status' => FailedEmailMessageStatus::Recovered,
                'recovered_at' => now(),
                'resolved_lead_id' => $lead->id,
                'last_attempted_at' => now(),
            ]);
        } catch (EmailMessagePartiallyProcessed $e) {
            $this->recordRetryFailure($record, $e->getMessage(), partialLeadId: $e->leadId, stage: $e->stage);
        } catch (Throwable $e) {
            $this->recordRetryFailure($record, $e->getMessage());
        }

        // Already \Seen from the original attempt — reasserting here is a
        // harmless no-op, not a new behavior change.
        imap_setflag_full($inbox, (string) $messageNumber, '\\Seen');
    }

    /**
     * EMAIL-LOSS-1 (event-replay fix) — the exact decision retryOneMessage()
     * relies on to never re-fire an event that already returned
     * successfully: only a record whose LAST recorded stage is
     * specifically Attachments means event() already dispatched on a
     * prior attempt. Any other value — Event (never dispatched), or null
     * (a record from before this fix existed, or one whose only failure
     * so far was something other than EmailMessagePartiallyProcessed) —
     * means the event must still be (re-)fired. Extracted as its own
     * method so this one decision has exactly one place it's made, and so
     * it can be verified directly without needing a real IMAP\Connection
     * (see this fix's own Decision Brief §"Real IMAP limitation").
     */
    private function eventAlreadyDispatchedFor(FailedEmailMessage $record): bool
    {
        return $record->partial_stage === EmailPartialFailureStage::Attachments;
    }

    private function recordRetryFailure(FailedEmailMessage $record, string $reason, ?string $partialLeadId = null, ?EmailPartialFailureStage $stage = null): void
    {
        $attempts = $record->attempts + 1;
        $exhausted = $attempts >= self::MAX_RETRY_ATTEMPTS;

        $record->update([
            'attempts' => $attempts,
            'failure_reason' => Str::limit($reason, 1000),
            'partial_lead_id' => $partialLeadId ?? $record->partial_lead_id,
            // EMAIL-LOSS-1 (event-replay fix): advances (e.g. Event ->
            // Attachments once the event finally succeeds and only
            // attachments remain outstanding) or stays as-is — never
            // cleared, since $stage is only passed when the retry attempt
            // itself threw EmailMessagePartiallyProcessed again.
            'partial_stage' => $stage ?? $record->partial_stage,
            'last_attempted_at' => now(),
            'status' => $exhausted ? FailedEmailMessageStatus::Exhausted : FailedEmailMessageStatus::Failed,
            'next_retry_at' => $exhausted ? null : now()->addMinutes(self::RETRY_BACKOFF_MINUTES * $attempts),
        ]);

        Log::error(
            "Retry failed for email message (FailedEmailMessage [{$record->id}]) on lead source [{$this->source->id}]: {$reason}"
            .($exhausted ? ' — retry ceiling reached, marked exhausted.' : '')
        );
    }

    private function exhaustRecord(FailedEmailMessage $record, string $reason): void
    {
        $record->update([
            'status' => FailedEmailMessageStatus::Exhausted,
            'failure_reason' => Str::limit($reason, 1000),
            'last_attempted_at' => now(),
            'next_retry_at' => null,
        ]);

        Log::error("Email message retry abandoned (FailedEmailMessage [{$record->id}]) on lead source [{$this->source->id}]: {$reason}");
    }

    private function createLeadFromEmail(Connection $inbox, int $messageNumber, object $header): Lead
    {
        $structure = imap_fetchstructure($inbox, $messageNumber);
        $body = $structure !== false
            ? $this->extractBody($inbox, $messageNumber, $structure)
            : ['message' => '', 'html' => null];

        $from = $header->from[0] ?? null;
        $email = $from !== null ? "{$from->mailbox}@{$from->host}" : null;
        $name = $from->personal ?? $email ?? 'Unknown Sender';
        $sentAt = $this->emailSentAt($header);

        // A second email from the same address is a follow-up on the same
        // inquiry, not a new one — mirrors how Messenger/Instagram/WhatsApp
        // already append a follow-up message to the sender's existing lead
        // instead of creating a duplicate (see
        // LeadWebhookController::appendFacebookMessage()). Skipped entirely
        // when the address couldn't be parsed at all, since there's nothing
        // reliable to match a later email against.
        $existingLead = $email !== null
            ? Lead::query()->where('source_id', $this->source->id)->where('email', $email)->first()
            : null;

        if ($existingLead instanceof Lead) {
            $this->appendEmailMessage($existingLead, $header, $body, $email);
            $lead = $existingLead;
            $isNewLead = false;
        } else {
            // TOCTOU fix: coordination lock — see
            // Platform\Clients\LeadSources::toggleLeadStatus()'s own docblock
            // for the full readers/writer protocol. Wrapped in its own
            // transaction (this job has none otherwise) so the lock and the
            // Lead::create() below commit atomically — a pipeline-status
            // removal racing this exact window can never strand the lead being
            // created. $tenant->initialLeadStatus() is read from this
            // freshly-locked instance, never $this->source->tenant (which may
            // carry a stale cached relation).
            //
            // DEADLOCK-1 fix: FOR UPDATE, not FOR SHARE — see
            // LeadWebhookController::createLead()'s identical comment for the
            // full reasoning. Lead::create() below still triggers
            // Lead::nextIdentifier()'s own FOR UPDATE on this same row; that is
            // now a harmless re-lock instead of a lock-upgrade deadlock hazard
            // against another concurrent creator for this tenant.
            $lead = DB::transaction(function () use ($name, $email, $body, $header): Lead {
                $tenant = Tenant::whereKey($this->source->tenant_id)->lockForUpdate()->firstOrFail();

                return Lead::create([
                    'tenant_id' => $this->source->tenant_id,
                    'source_id' => $this->source->id,
                    'name' => $name,
                    'email' => $email,
                    'message' => trim($body['message']),
                    'html_body' => $body['html'],
                    'source_data' => ['subject' => $header->subject ?? null],
                    'status_id' => $tenant->initialLeadStatus()?->id,
                ]);
            });
            $isNewLead = true;
        }

        // EMAIL-LOSS-1: everything above this point either fully committed
        // (Lead::create()'s own transaction) or made no persisted change at
        // all (a thrown exception from appendEmailMessage()'s own
        // $lead->save() never partially applies — a single UPDATE is
        // atomic). From here on $lead is guaranteed durably persisted —
        // runPostPersistSteps() below handles everything after that point.
        $this->runPostPersistSteps($lead, eventAlreadyDispatched: false, isNewLead: $isNewLead, inbox: $inbox, messageNumber: $messageNumber, structure: $structure, sentAt: $sentAt);

        return $lead;
    }

    /**
     * EMAIL-LOSS-1 (event-replay fix) — the two steps that must run after a
     * Lead is already durably persisted (created or appended): firing its
     * event, then saving attachments. Shared by createLeadFromEmail() (a
     * fresh attempt, $eventAlreadyDispatched always false) and
     * retryOneMessage()'s partial-lead branch ($eventAlreadyDispatched true
     * only when the persisted FailedEmailMessage's own partial_stage says
     * the event already succeeded on a prior attempt) — one place, so the
     * two callers can never classify a failure differently.
     *
     * Each of the two steps has its OWN try/catch, not one shared one (the
     * bug this fix exists to close): a failure is tagged with exactly
     * which step it came from via EmailPartialFailureStage, so a retry
     * never re-fires an event that already returned successfully (per
     * ShouldQueue's own semantics, that already durably queued its
     * listeners — see EmailPartialFailureStage's own docblock) and never
     * skips re-firing one that never dispatched at all.
     *
     * $isNewLead is passed through, not re-derived, so a retry fires the
     * SAME event class (LeadReceived vs LeadUpdated) the original attempt
     * would have — see EmailMessagePartiallyProcessed's own docblock.
     */
    private function runPostPersistSteps(Lead $lead, bool $eventAlreadyDispatched, bool $isNewLead, Connection $inbox, int $messageNumber, object|false $structure, CarbonInterface $sentAt): void
    {
        if (! $eventAlreadyDispatched) {
            try {
                event($isNewLead ? new LeadReceived($lead) : new LeadUpdated($lead));
            } catch (Throwable $e) {
                throw new EmailMessagePartiallyProcessed($lead->id, EmailPartialFailureStage::Event, $isNewLead, $e);
            }
        }

        try {
            if ($structure !== false) {
                $this->saveAttachments($inbox, $messageNumber, $structure, $lead, $sentAt);
            }
        } catch (Throwable $e) {
            throw new EmailMessagePartiallyProcessed($lead->id, EmailPartialFailureStage::Attachments, $isNewLead, $e);
        }
    }

    /**
     * Appends a follow-up email from the same sender onto their existing
     * lead — mirrors LeadWebhookController::appendFacebookMessage(), with
     * one difference: there's no per-message conversation timeline for
     * email the way Lead::messageTimeline() gives Messenger/WhatsApp, so
     * html_body is simply replaced with the newest email's own content
     * (only one slot exists; showing the latest message beats a stale
     * first one), and message is newline-appended the same way those
     * channels already do.
     *
     * EMAIL-LOSS-1: no longer fires LeadUpdated itself — createLeadFromEmail()
     * now fires it (or LeadReceived) right after this returns, alongside the
     * create-path's own event, so both paths share one post-persist
     * try/catch boundary (see that method's own comment).
     */
    private function appendEmailMessage(Lead $lead, object $header, array $body, string $email): void
    {
        $personalName = $header->from[0]->personal ?? null;

        if ($lead->name === $email && is_string($personalName) && $personalName !== '') {
            $lead->name = $personalName;
        }

        $messageText = trim($body['message']);

        if ($messageText !== '') {
            $lead->message = trim(($lead->message ?? '')."\n".$messageText);
        }

        if ($body['html'] !== null) {
            $lead->html_body = $body['html'];
        }

        $sourceData = $lead->source_data ?? [];
        $sourceData['subject'] = $header->subject ?? ($sourceData['subject'] ?? null);
        $lead->source_data = $sourceData;

        $lead->save();
    }

    /**
     * `imap_headerinfo()->date` is the raw Date header — malformed or
     * missing on some senders' mail, so this falls back to "now" rather
     * than letting a bad date header break attachment ordering entirely.
     */
    private function emailSentAt(object $header): CarbonInterface
    {
        if (! isset($header->date) || ! is_string($header->date) || $header->date === '') {
            return now();
        }

        try {
            return Carbon::parse($header->date);
        } catch (Throwable) {
            return now();
        }
    }

    /**
     * imap_fetchbody() returns a MIME part's raw, still-encoded bytes — it
     * never applies Content-Transfer-Encoding itself. Without decoding,
     * base64 or quoted-printable parts (common for HTML/forwarded mail) land
     * in the lead's message field as unreadable encoded text.
     *
     * Returns both the plain-text message (the lead's primary, searchable
     * field — HTML tags stripped as a last resort when no plain part exists
     * at all) and the original HTML part verbatim, if the message has one,
     * so the UI can offer "view original email" without re-deriving it.
     *
     * @return array{message: string, html: ?string}
     */
    private function extractBody(Connection $inbox, int $messageNumber, object $structure): array
    {
        ['plain' => $plain, 'html' => $html] = $this->findTextParts($structure);

        $htmlBody = $html !== null
            ? $this->fetchDecodedPart($inbox, $messageNumber, $html[0], $html[1])
            : null;

        $message = match (true) {
            $plain !== null => $this->fetchDecodedPart($inbox, $messageNumber, $plain[0], $plain[1]),
            $htmlBody !== null => html_entity_decode(strip_tags($htmlBody), ENT_QUOTES | ENT_HTML5),
            default => '',
        };

        return [
            'message' => $this->stripAntiClippingPadding($message),
            'html' => $htmlBody,
        ];
    }

    private function fetchDecodedPart(Connection $inbox, int $messageNumber, ?string $partNumber, int $encoding): string
    {
        $raw = $partNumber !== null
            ? imap_fetchbody($inbox, $messageNumber, $partNumber)
            : imap_fetchbody($inbox, $messageNumber, '1');

        return match ($encoding) {
            3 => base64_decode((string) $raw) ?: (string) $raw, // ENCBASE64
            4 => quoted_printable_decode((string) $raw), // ENCQUOTEDPRINTABLE
            default => (string) $raw,
        };
    }

    /**
     * Marketing senders (Hostinger here) pad plain-text bodies with invisible
     * zero-width/combining characters between spaces to defeat Gmail's
     * "message clipped" truncation. They're inert in an inbox but read as
     * garbage once extracted as plain text, so strip them along with the
     * whitespace runs they leave behind.
     */
    private function stripAntiClippingPadding(string $text): string
    {
        // Zero-width/format characters (ZWSP, ZWNJ, ZWJ, BOM, word joiner,
        // soft hyphen, combining grapheme joiner) used purely as padding.
        $text = preg_replace('/[\p{Cf}\x{034F}]/u', '', $text) ?? $text;

        // What's left is runs of Unicode space-separator characters (plain
        // space, figure space, NBSP, ...) standing in for the removed ones.
        // Trim each line so padding-only lines collapse to truly empty ones.
        $lines = array_map(
            fn (string $line): string => trim(preg_replace('/[\p{Zs}\t]{2,}/u', ' ', $line) ?? $line),
            preg_split('/\r\n|\r|\n/', $text) ?: [$text],
        );

        $text = preg_replace('/\n{3,}/', "\n\n", implode("\n", $lines)) ?? implode("\n", $lines);

        return trim($text);
    }

    /**
     * Walk a message's MIME structure for the best plain-text part and the
     * best HTML part, independently — a forwarded message commonly wraps
     * multipart/alternative (plain + html) inside multipart/mixed alongside
     * attachments, so both can exist several levels deep in the same tree.
     * A non-multipart message with an unrecognized subtype is treated as
     * "plain" so `message` still gets whatever body the message actually has.
     *
     * @return array{plain: ?array{0: ?string, 1: int}, html: ?array{0: ?string, 1: int}}
     *                                                                                    each candidate is [part number (dot-notation, e.g. "1.2"; null
     *                                                                                    for a non-multipart message), the part's IMAP encoding constant]
     */
    private function findTextParts(object $structure, string $prefix = ''): array
    {
        if (empty($structure->parts)) {
            $partNumber = $prefix !== '' ? $prefix : null;
            $encoding = $structure->encoding ?? 0;

            return strtoupper($structure->subtype ?? '') === 'HTML'
                ? ['plain' => null, 'html' => [$partNumber, $encoding]]
                : ['plain' => [$partNumber, $encoding], 'html' => null];
        }

        $plain = null;
        $html = null;

        foreach ($structure->parts as $index => $part) {
            if ($plain !== null && $html !== null) {
                break;
            }

            $partNumber = $prefix === '' ? (string) ($index + 1) : $prefix.'.'.($index + 1);

            if ((int) $part->type === 1) {
                $found = $this->findTextParts($part, $partNumber);
                $plain ??= $found['plain'];
                $html ??= $found['html'];

                continue;
            }

            if ((int) $part->type !== 0) {
                continue;
            }

            $subtype = strtoupper($part->subtype ?? '');

            if ($subtype === 'PLAIN') {
                $plain ??= [$partNumber, $part->encoding ?? 0];
            } elseif ($subtype === 'HTML') {
                $html ??= [$partNumber, $part->encoding ?? 0];
            }
        }

        return ['plain' => $plain, 'html' => $html];
    }

    /**
     * Walks the same MIME structure as findTextParts() for every part that
     * isn't the plain/html body — a forwarded email or one with several
     * files attached can carry more than one, so all of them are saved as
     * their own LeadAttachment row, not just the first.
     */
    private function saveAttachments(Connection $inbox, int $messageNumber, object $structure, Lead $lead, CarbonInterface $sentAt): void
    {
        $parts = $this->findAttachmentParts($structure);

        if (count($parts) > 1) {
            Log::info('Multiple email attachments received', ['count' => count($parts), 'lead_id' => $lead->id]);
        }

        foreach ($parts as $part) {
            $this->saveAttachmentPart($inbox, $messageNumber, $part, $lead, $sentAt);
        }
    }

    /**
     * @return array<int, array{part_number: string, encoding: int, mime: string, filename: ?string}>
     */
    private function findAttachmentParts(object $structure, string $prefix = ''): array
    {
        if (empty($structure->parts)) {
            return [];
        }

        $attachments = [];

        foreach ($structure->parts as $index => $part) {
            $partNumber = $prefix === '' ? (string) ($index + 1) : $prefix.'.'.($index + 1);

            if ((int) $part->type === 1) {
                $attachments = [...$attachments, ...$this->findAttachmentParts($part, $partNumber)];

                continue;
            }

            $subtype = strtoupper($part->subtype ?? '');
            $filename = $this->partFilename($part);

            // A plain/html part with no filename is the message body itself
            // (already captured by findTextParts()) — but the same subtypes
            // legitimately show up as real attachments too (e.g. a .txt or
            // .csv file), so only skip the bodyless case.
            if (in_array($subtype, ['PLAIN', 'HTML'], true) && $filename === null) {
                continue;
            }

            $attachments[] = [
                'part_number' => $partNumber,
                'encoding' => (int) ($part->encoding ?? 0),
                'mime' => $this->partMimeType($part),
                'filename' => $filename,
            ];
        }

        return $attachments;
    }

    /**
     * Reads the attachment's filename from the Content-Disposition or
     * Content-Type parameters — wherever the sender's mail client put it.
     */
    private function partFilename(object $part): ?string
    {
        $params = [...($part->dparameters ?? []), ...($part->parameters ?? [])];

        foreach ($params as $param) {
            if (in_array(strtolower($param->attribute ?? ''), ['filename', 'name'], true)) {
                return $param->value;
            }
        }

        return null;
    }

    private function partMimeType(object $part): string
    {
        $primary = self::IMAP_PRIMARY_TYPES[(int) ($part->type ?? 3)] ?? 'application';
        $subtype = strtolower($part->subtype ?? 'octet-stream');

        return "{$primary}/{$subtype}";
    }

    /**
     * @param  array{part_number: string, encoding: int, mime: string, filename: ?string}  $part
     */
    private function saveAttachmentPart(Connection $inbox, int $messageNumber, array $part, Lead $lead, CarbonInterface $sentAt): void
    {
        $type = $this->attachmentTypeCategory($part['mime']);

        try {
            $bytes = $this->fetchDecodedPart($inbox, $messageNumber, $part['part_number'], $part['encoding']);
        } catch (Throwable $e) {
            $lead->attachments()->create([
                'type' => $type,
                'status' => LeadAttachmentStatus::Failed,
                'failure_reason' => "Could not read attachment from the mailbox: {$e->getMessage()}",
                'sent_at' => $sentAt,
            ]);

            return;
        }

        if ($bytes === '' || strlen($bytes) > self::MAX_ATTACHMENT_BYTES) {
            $lead->attachments()->create([
                'type' => $type,
                'status' => LeadAttachmentStatus::Failed,
                'failure_reason' => $bytes === ''
                    ? 'Attachment was empty after decoding.'
                    : 'Attachment exceeds the '.self::MAX_ATTACHMENT_BYTES.'-byte limit.',
                'sent_at' => $sentAt,
            ]);

            return;
        }

        $relativePath = "leads/{$lead->tenant->tenant_code}/email/".Str::ulid().'.'.$this->attachmentExtension($part['filename'], $part['mime']);

        try {
            Storage::disk('local')->put($relativePath, $bytes);
        } catch (Throwable $e) {
            $lead->attachments()->create([
                'type' => $type,
                'status' => LeadAttachmentStatus::Failed,
                'failure_reason' => "Could not save attachment to disk: {$e->getMessage()}",
                'sent_at' => $sentAt,
            ]);

            return;
        }

        $lead->attachments()->create([
            'type' => $type,
            'path' => $relativePath,
            'mime' => $part['mime'],
            'status' => LeadAttachmentStatus::Downloaded,
            'sent_at' => $sentAt,
        ]);
    }

    private function attachmentTypeCategory(string $mime): string
    {
        return match (true) {
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'video/') => 'video',
            str_starts_with($mime, 'audio/') => 'audio',
            default => 'file',
        };
    }

    private function attachmentExtension(?string $filename, string $mime): string
    {
        if ($filename !== null) {
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            if ($extension !== '') {
                return $extension;
            }
        }

        return MimeTypes::getDefault()->getExtensions($mime)[0] ?? 'bin';
    }
}
