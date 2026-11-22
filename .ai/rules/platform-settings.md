---
paths:
  - 'app/{Livewire/Platform/Settings/ActivityLog.php,Support/SettingsAudit.php,Services/ErrorAlertNotifier.php,Providers/MonitoringServiceProvider.php},bootstrap/app.php'
---

# Platform Settings

## Activity log deletions are permanent; settings changes audited; error alerts and Logs gating
Activity log: Mother Admin only; can delete entries older than a date after re-entering their password; each deletion is logged under log_name 'activity-log', which is NEVER deletable (don't schedule activitylog:clean — it would bypass that). Settings saves/removals (Email, Backups, Calling app key/APK, backup deletes) go through SettingsAudit::record() with non-secret details only (secrets as "X changed"). ErrorAlertNotifier hooks $exceptions->reportable(): fingerprint = class + first frame in app/ or routes/, max one email per kind per 24h, silent unless an alert recipient exists. Logs are an IN-HOUSE viewer (opcodesio/log-viewer was removed by request — don't re-add a package UI): Livewire Platform\Monitoring\Logs + App\Services\Logs\{LogReader,LogIndex,MailPreviewParser}, only .log files under storage/logs, referenced by id (never a path). View reads; Update downloads (LogDownloadController, streamed), deletes single/selected ENTRIES and EMPTIES files (password required) — a .log file is never deleted from the page. Edits go through LogEditor, which rewrites IN PLACE under flock (never delete+recreate: workers keep the file open); the app's file log channels have 'locking' => true so their writes wait instead of being lost. All recorded via SettingsAudit. The byte-offset index lives in storage/framework/cache/log-index.
