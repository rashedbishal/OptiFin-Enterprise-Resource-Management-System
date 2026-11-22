<?php

namespace App\Services;

use App\Models\LeadSource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * OPS-1 — makes a persistently failing IMAP connection observable.
 * ProcessEmailLeadSource logs an unreachable mailbox and returns normally, so
 * nothing else (no failed job, no exit code) ever surfaces it.
 *
 * State is one cache counter per lead source — consecutive connection
 * failures — holding no credentials, host, error text or email content, and
 * no schema. Deduplication needs no separate marker: Cache::increment() is
 * atomic and hands every concurrent caller a distinct value, so exactly one of
 * them observes the value equal to the threshold and raises the alert. The
 * counter is dropped on the first successful connection (recovery), and
 * expires on its own after the configured window, so a source that stays broken
 * re-alerts about once per window instead of every minute.
 *
 * Every entry point swallows its own errors: a cache or mail problem must
 * never change how the job ingests email.
 */
class ImapConnectionHealthMonitor
{
    public function __construct(private readonly OpsAlertNotifier $notifier) {}

    public function recordFailure(LeadSource $source): void
    {
        try {
            $key = $this->key($source);

            Cache::add($key, 0, now()->addMinutes((int) config('ops.imap_failure_window_minutes', 1440)));

            $failures = (int) Cache::increment($key);

            if ($failures !== (int) config('ops.imap_failure_threshold', 5)) {
                return;
            }

            $context = [
                'lead_source_id' => $source->id,
                'consecutive_failures' => $failures,
                'detected_at' => now()->toIso8601String(),
            ];

            Log::critical("Lead source [{$source->id}] IMAP connection has failed {$failures} consecutive times.", $context);

            $this->notifier->send('IMAP connection failing for a lead source', $context);
        } catch (Throwable $e) {
            $this->logMonitoringFailure($e);
        }
    }

    public function recordSuccess(LeadSource $source): void
    {
        try {
            $failures = (int) Cache::pull($this->key($source), 0);

            if ($failures >= (int) config('ops.imap_failure_threshold', 5)) {
                Log::info("Lead source [{$source->id}] IMAP connection recovered after {$failures} consecutive failures.", [
                    'lead_source_id' => $source->id,
                ]);
            }
        } catch (Throwable $e) {
            $this->logMonitoringFailure($e);
        }
    }

    /**
     * How many times in a row this source's inbox has failed to connect
     * (read-only; for Admin → Monitoring → Integrations).
     */
    public function consecutiveFailures(LeadSource $source): int
    {
        try {
            return (int) Cache::get($this->key($source), 0);
        } catch (Throwable) {
            return 0;
        }
    }

    private function key(LeadSource $source): string
    {
        return "ops:imap-connection-failures:{$source->id}";
    }

    private function logMonitoringFailure(Throwable $e): void
    {
        try {
            Log::warning('IMAP connection health monitoring could not complete.', ['exception' => $e::class]);
        } catch (Throwable) {
            // Nothing may propagate into the job.
        }
    }
}
