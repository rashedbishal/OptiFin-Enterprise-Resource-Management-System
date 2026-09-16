<?php

namespace App\Console\Commands;

use App\Enums\FailedEmailMessageStatus;
use App\Jobs\ProcessEmailLeadSource;
use App\Models\FailedEmailMessage;
use App\Models\LeadSource;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * EMAIL-LOSS-1 — the minimal recovery interface for FailedEmailMessage
 * records (see that model's own docblock): finds due, still-retryable
 * failures, groups them by lead source, and dispatches one
 * ProcessEmailLeadSource job per affected source in its retry mode
 * (App\Jobs\ProcessEmailLeadSource::$retryFailedMessageIds) — reusing that
 * job's own $tries/WithoutOverlapping/IMAP-connection-lifecycle machinery
 * exactly as a normal poll run does, rather than re-implementing any of it
 * here. Deliberately an Artisan command only — no admin UI in this phase
 * (see the approved Implementation Approval's own scope).
 */
#[Signature('leads:retry-failed-emails {--limit=25 : Maximum number of due failed messages to select across all sources in this invocation}')]
#[Description('Retry durably-recorded failed email lead-processing messages that are due for another attempt')]
class RetryFailedEmailMessages extends Command
{
    public function handle(): int
    {
        if (! extension_loaded('imap')) {
            $this->error('The IMAP extension is not available on this server.');

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));

        $due = FailedEmailMessage::query()
            ->where('status', FailedEmailMessageStatus::Failed)
            ->where('next_retry_at', '<=', now())
            ->orderBy('next_retry_at')
            ->limit($limit)
            ->get()
            ->groupBy('lead_source_id');

        if ($due->isEmpty()) {
            $this->info('No failed email messages are due for retry.');

            return self::SUCCESS;
        }

        foreach ($due as $leadSourceId => $records) {
            $source = LeadSource::find($leadSourceId);

            // A source deactivated or deleted since the failure was
            // recorded is skipped, not treated as an error — the durable
            // record itself is untouched, so it stays visible/retryable
            // once the source is active again, exactly as leads:fetch-emails
            // itself already skips an inactive source rather than failing.
            if ($source === null || ! $source->is_active) {
                $this->line('Skipping '.$records->count()." due message(s) for lead source [{$leadSourceId}]: source is missing or inactive.");

                continue;
            }

            $this->line('Dispatching retry for '.$records->count()." due message(s) on lead source [{$source->id}].");

            ProcessEmailLeadSource::dispatch($source, $records->pluck('id')->all());
        }

        return self::SUCCESS;
    }
}
