---
paths:
  - 'app/Notifications/MeetingUpdatedNotification.php,app/Livewire/Tenant/NotificationBell.php'
---

# Livewire Tenant

## Meeting scheduled/rescheduled notification includes the time
MeetingUpdatedNotification::toArray() stores meeting_time (via Lead::meetingTimeFormatted(), e.g. "2:30 PM") alongside meeting_date. NotificationBell::dateChangeMessage() takes an optional $timeKey param — only the meeting_date call site passes 'meeting_time'; the next_follow_up_at call site (FollowUpUpdatedNotification) never does, since that column has no companion time field at all (see Lead::$meeting_time's own docblock — next_follow_up_at is date-only). Don't add a $timeKey to the follow-up call site; there's nothing to read.
