---
paths:
  - 'app/{Livewire/Platform/Settings/ActivityLog.php,Support/SettingsAudit.php,Services/ErrorAlertNotifier.php,Providers/MonitoringServiceProvider.php},config/log-viewer.php,bootstrap/app.php'
---

# Platform Settings

## Activity log deletions are permanent; settings changes audited; error alerts and Logs gating
Activity log: Mother Admin only; can delete entries older than a date after re-entering their password; each deletion is logged under log_name 'activity-log', which is NEVER deletable (don't schedule activitylog:clean — it would bypass that). Settings saves/removals (Email, Backups, Calling app key/APK, backup deletes) go through SettingsAudit::record() with non-secret details only (secrets as "X changed"). ErrorAlertNotifier hooks $exceptions->reportable(): fingerprint = class + first frame in app/ or routes/, max one email per kind per 24h, silent unless an alert recipient exists. Log viewer lives at admin/monitoring/logs behind 'auth:platform' + the 'logs' module (View reads, Update downloads/deletes) — its default gates are replaced in MonitoringServiceProvider.
