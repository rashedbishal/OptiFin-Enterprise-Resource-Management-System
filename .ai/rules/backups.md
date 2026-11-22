---
paths:
  - 'app/{Services/BackupSettings.php,Livewire/Platform/Settings/Backups.php,Health/BackupsCheck.php,Jobs/RunBackup.php,Backups/**,Notifications/Backups/**,Support/BackupAlertNotifiable.php},config/backup.php'
---

# Backups

## Backups: B2 settings from Admin → Settings → Backups; stay inside B2's free API limits
B2 credentials + archive password live encrypted in PlatformSetting (BackupSettings) and are applied via beforeResolving(Spatie\Backup\Config\Config) — the package's Config is scoped and validated, so set config BEFORE it resolves. Scheduled backup commands use ->when(isConfigured) and Asia/Dhaka times. Never list B2 per file: the page and BackupsCheck use one Flysystem listContents() call (size+date included) and the Health check reuses a 30-min cached status — spatie's Backup objects do a HEAD per file and swallow connection errors. RunBackup's $timeout must stay below the redis retry_after (90s). config backup.notifications.mail.to is a placeholder; BackupAlertNotifiable picks real recipients.
