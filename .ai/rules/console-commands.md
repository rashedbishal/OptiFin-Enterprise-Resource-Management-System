---
paths:
  - app/Console/Commands/SendLeadAlertNotifications.php
---

# Console Commands

## Alert sweep skips suspended/inactive tenants; date checks stay index-friendly
handle() now filters Tenant::query() to `status = Active` before the meeting/followup_alert_enabled OR — grouped inside a nested where() closure, since AND binds tighter than OR in SQL and appending a bare ->where('status', ...) before an ungrouped ->orWhere(...) would silently widen the match to "any followup_alert_enabled tenant regardless of status." alreadyNotifiedToday() uses a >= today / < tomorrow range instead of whereDate('created_at', today()) — same index-friendliness fix as [[the FiltersLeadsQuery whereDate rule]], written fresh here after being fixed there in the same session, so don't reintroduce whereDate() on a timestamp column in this file either.
