---
paths:
  - 'app/{Livewire/Platform/Monitoring/**,Providers/{HorizonServiceProvider,MonitoringServiceProvider}.php,Enums/MonitoringTool.php}'
---

# Monitoring

## Monitoring tools are platform modules, checked on the platform guard
Horizon/Health/Pulse access is role-based: each MonitoringTool case is a platform-scoped system Module (slug = case value, added by the add_monitoring_platform_modules migration); `view` opens a page, `update` allows its actions (retry failed job, run health checks). Always check via PlatformUser::canUseMonitoringTool() on Auth::guard('platform') — never the `permission:` middleware (it prefers the web/tenant guard) and never the gate's own $user arg (default guard). The viewHorizon/viewPulse gates replace the packages' "allow everyone in local" defaults on purpose.
