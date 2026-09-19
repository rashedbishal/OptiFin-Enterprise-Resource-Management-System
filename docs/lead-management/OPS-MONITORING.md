# Operational monitoring — scheduler failures, scheduler heartbeat, IMAP outages

Configuration lives in `config/ops.php`; both environment variables are optional and safe to leave unset.

| Variable | Effect |
| --- | --- |
| `OPS_ALERT_EMAIL` | Address that receives scheduler-failure and IMAP-outage alert emails. Unset (or not a valid address) = **log only**; nothing else changes and nothing fails. |
| `OPS_SCHEDULER_HEARTBEAT_URL` | URL requested with a plain `GET` (no body, no query string, no tenant data) each time `leads:fetch-emails` finishes successfully. Unset = no heartbeat. Point it at any external dead-man's-switch monitor. |

Alert emails use the application's normal mail configuration (`MAIL_*`). With the shipped default `MAIL_MAILER=log` an "email" is only written to the log, so set a real mailer in production if you want `OPS_ALERT_EMAIL` to reach a person.

## What is monitored

**1. Scheduled command failures (all 8 commands in `routes/console.php`).** A scheduled command that exits non-zero is always logged (`Log::error`, with only the command name, exit code and timestamp — command output is never captured) and, when `OPS_ALERT_EMAIL` is set, emailed at most once per `ops.scheduler_alert_throttle_minutes` (default 60) per command. The throttle limits email *attempts*, not only delivered emails: if the mailer is broken, one attempt is made per window (each failure is still logged), so a failing every-minute command cannot turn into a minute-by-minute stream of delivery attempts. Laravel's own `schedule:run` also reports the failure through the normal exception handler; this adds the structured log line, the email and the throttle. A run skipped because of `withoutOverlapping()` is not a failure and is not reported.

**2. Scheduler liveness heartbeat (`leads:fetch-emails` only).** It runs every minute, so a stopped heartbeat means `schedule:run` is not executing (cron/supervisor stopped, host down, or a stale overlap mutex keeping the command from running). The ping is best-effort: a slow, unreachable or rejecting endpoint is logged as a warning (never with the URL, which usually holds a secret token) and never fails the command. A run that exits non-zero does not ping.

**3. IMAP connection failures (`ProcessEmailLeadSource`).** An unreachable or rejecting mailbox was previously only a log line — the job returned normally, so neither a failed job nor a command exit code existed. Each connection failure now increments a per-source consecutive-failure counter kept in the cache (no credentials, host, error text or email content). When the counter reaches `ops.imap_failure_threshold` (default 5 — about five minutes, since a failing source is re-polled every minute) **one** `Log::critical` (and one email, if configured) is raised. The counter is cleared by the first successful connection (with a `Log::info` recovery line if an alert had fired) and otherwise expires after `ops.imap_failure_window_minutes` (default 1440), so a source that stays broken re-alerts about once a day rather than every minute. Message-level failures (EMAIL-LOSS-1: `FailedEmailMessage`, `leads:retry-failed-emails`) are unchanged.

## What this does NOT detect

- **A dead queue worker.** Nothing inside this application can report that the queue worker (`php artisan queue:work`) has stopped — a stopped worker cannot report anything. The heartbeat above only proves the scheduler is running. A completely dead worker (which also silently stops IMAP polling, since polling runs as queued jobs, plus notifications and CSV imports) needs **independent external monitoring**: run the worker under a process supervisor with restart-on-exit, and monitor it from outside (for example a supervisor/service health check, or a separate heartbeat job dispatched to the queue and pinged from inside the job).
- **The scheduler heartbeat is not a queue heartbeat.** A healthy heartbeat with a dead worker is possible.
- **A stopped `cron` entry** is detected only through the heartbeat going silent — which requires the external monitor to alert on missing pings.
- **Alert state is cache-based.** Clearing the cache, or a cache-store outage, resets IMAP counters and scheduler throttle markers (worst case: one repeated alert, or a delayed one). Monitoring errors are swallowed and logged as warnings; they never affect a command or a job.
- **Mail is sent synchronously.** Alert emails are sent inline (deliberately not queued, so a dead worker cannot suppress them). A slow or hanging SMTP server can therefore delay the sequential `schedule:run` for the duration of the mailer's timeout — at most once per throttle window per command.
- **Non-zero exits only.** An exception thrown by the scheduler itself before a command starts (for example a mutex cache failure) is reported by Laravel's own handler to the log but not emailed.

## Required processes (unchanged, restated for operators)

- Scheduler: a cron entry running `php artisan schedule:run` every minute.
- Queue worker: `php artisan queue:work` supervised for restart (also `php artisan queue:restart` after each deploy).
