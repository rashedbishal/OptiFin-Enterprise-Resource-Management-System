<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Operator Alert Email (OPS-1)
    |--------------------------------------------------------------------------
    |
    | Where scheduler-failure and IMAP-connection-outage alerts are emailed.
    | Optional: when unset, both are still written to the application log
    | (Log::error / Log::critical) — only the email is skipped. Never
    | hard-code an address here.
    |
    */

    'alert_email' => env('OPS_ALERT_EMAIL'),

    /*
    |--------------------------------------------------------------------------
    | Scheduler Heartbeat URL (OPS-1)
    |--------------------------------------------------------------------------
    |
    | Optional dead-man's-switch URL, requested (plain GET, no payload) each
    | time `leads:fetch-emails` — the every-minute command — finishes
    | successfully. Point it at any external monitor that alerts when its pings
    | stop arriving. It proves the scheduler is executing; it says nothing about
    | the queue worker. Unset = no heartbeat.
    |
    */

    'scheduler_heartbeat_url' => env('OPS_SCHEDULER_HEARTBEAT_URL'),

    /*
    |--------------------------------------------------------------------------
    | Scheduler Failure Email Throttle (OPS-1)
    |--------------------------------------------------------------------------
    |
    | A failing scheduled command is always logged, but its alert EMAIL is sent
    | at most once per this many minutes per command, so a command failing every
    | minute cannot flood the operator's inbox.
    |
    */

    'scheduler_alert_throttle_minutes' => 60,

    /*
    |--------------------------------------------------------------------------
    | IMAP Connection Failure Alerting (OPS-1)
    |--------------------------------------------------------------------------
    |
    | A lead source whose IMAP connection fails `imap_failure_threshold`
    | consecutive times raises ONE alert (log + optional email). The
    | consecutive-failure counter lives in the cache for at most
    | `imap_failure_window_minutes` (a still-broken source therefore re-alerts
    | about once per window), and is cleared the moment a connection succeeds.
    | A failing source is re-polled every minute, so 5 is ~5 minutes of
    | continuous failure — long enough to ignore a single network blip.
    |
    */

    'imap_failure_threshold' => 5,

    'imap_failure_window_minutes' => 1440,

];
