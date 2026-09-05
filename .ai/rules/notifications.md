---
paths:
  - 'app/Livewire/Tenant/Leads/Show.php,app/Notifications/MeetingCancelledNotification.php,app/Notifications/FollowUpCancelledNotification.php'
---

# Notifications

## Cancellation notifications go to admins, never the actor
Show::cancelMeeting()/cancelFollowUp() send MeetingCancelledNotification/FollowUpCancelledNotification via Show::notifyOtherAdmins() — every active Client Admin in the tenant EXCEPT Auth::id() (whoever performed the cancellation, whether that's an admin or the assigned rep never gets notified about their own action). Deliberately not added inside the shared recordDateAlertAction() (used by both cancel and "mark done"): only cancelling notifies, marking done doesn't. Each notification is constructed and ->notify()'d BEFORE recordDateAlertAction() nulls the lead's meeting_date/next_follow_up_at, since toArray() reads the real date off $this->lead at send time — reordering this would silently send a null date. Real-time delivery to the bell is automatic (see [[Notification bell updates via Reverb, not polling]]) since these go through the same ->notify() + NotificationSent path as every other type.
