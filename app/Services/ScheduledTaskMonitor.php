<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * OPS-1 — failure observability and the optional liveness heartbeat for the
 * commands registered in routes/console.php. Both entry points are invoked
 * from scheduler callbacks and are written to be incapable of throwing: a
 * monitoring problem must never turn a healthy scheduled run into a failed
 * one.
 *
 * Only what the scheduler itself knows is ever reported — the command name,
 * its exit code and a timestamp. The command's output is never captured or
 * forwarded, so no tenant data, email content or credential can reach a log
 * line, an alert email or the heartbeat.
 */
class ScheduledTaskMonitor
{
    public function __construct(private readonly OpsAlertNotifier $notifier) {}

    /**
     * Called for a scheduled command that exited non-zero. Always logged; the
     * email (when OPS_ALERT_EMAIL is set) is throttled per command.
     */
    public function failed(string $command, int $exitCode): void
    {
        try {
            $context = [
                'command' => $command,
                'exit_code' => $exitCode,
                'failed_at' => now()->toIso8601String(),
            ];

            Log::error("Scheduled command [{$command}] failed with exit code [{$exitCode}].", $context);

            if (! $this->notifier->isConfigured()) {
                return;
            }

            $marker = "ops:scheduler-failure-alert:{$command}";

            // The marker throttles delivery ATTEMPTS, not just delivered emails:
            // it is deliberately kept even when the send fails. Releasing it
            // would retry a broken mailer on every failing run (every minute
            // for leads:fetch-emails), each attempt possibly blocking the
            // sequential scheduler on an SMTP timeout. The failure is still
            // logged on every run, so nothing is lost — only re-emailed later.
            if (! Cache::add($marker, true, now()->addMinutes((int) config('ops.scheduler_alert_throttle_minutes', 60)))) {
                return;
            }

            $this->notifier->send("Scheduled command failed: {$command}", $context);
        } catch (Throwable $e) {
            $this->logMonitoringFailure($e);
        }
    }

    /**
     * Plain GET to OPS_SCHEDULER_HEARTBEAT_URL — no payload, no query string.
     * The URL is never logged (monitoring URLs usually embed a secret token).
     */
    public function heartbeat(): void
    {
        $url = config('ops.scheduler_heartbeat_url');

        if (! is_string($url) || $url === '') {
            return;
        }

        try {
            $response = Http::timeout(5)->connectTimeout(3)->get($url);

            if ($response->failed()) {
                Log::warning('The scheduler heartbeat endpoint rejected the ping.', ['status' => $response->status()]);
            }
        } catch (Throwable $e) {
            $this->logMonitoringFailure($e);
        }
    }

    private function logMonitoringFailure(Throwable $e): void
    {
        try {
            Log::warning('Scheduler monitoring could not complete.', ['exception' => $e::class]);
        } catch (Throwable) {
            // Nothing left to fall back on — and nothing may propagate.
        }
    }
}
