---
paths:
  - 'app/{Services/MailSettings.php,Services/OpsAlertNotifier.php,Notifications/OpsAlertNotification.php,Notifications/HealthCheckFailedNotification.php,Support/HealthAlertNotifiable.php,Livewire/Platform/Settings/Email.php},config/health.php'
---

# Settings

## Email accounts come from Admin → Settings → Email settings, with .env fallback
Two independent, Mother-Admin-managed SMTP accounts stored encrypted in PlatformSetting (MailSettings): USER_EMAILS becomes the default mailer (password resets + any future user email); MONITORING_ALERTS is a separate mailer used only by OpsAlertNotification and HealthCheckFailedNotification, sent to its own recipient list. Each applies only once saved; unsaved = exactly the .env behaviour (accounts never borrow from each other). Applied lazily via callAfterResolving('mail.manager'); Horizon workers re-read within 60s (Queue::before). New alert-type emails should use MailSettings::ALERTS_MAILER the same way.
