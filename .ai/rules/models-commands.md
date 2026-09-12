---
paths:
  - 'app/{Models/Task.php,Models/TaskStatusLog.php,Enums/TaskStatus.php,Console/Commands/SendTaskAlertNotifications.php}'
---

# Models Commands

## Task mirrors Deal's architecture; TaskStatusLog must never merge-reparent
Task is a plain lead_id-keyed child of Lead (like Deal), added to LeadMergeService::CHILD_MODELS. TaskStatusLog is keyed by task_id only — never add it to CHILD_MODELS (no lead_id column, would fail). TaskStatus (Pending/Completed/Cancelled) is a plain enum mirroring DealStatus's canTransitionTo() rule: Completed<->Cancelled directly is blocked, must pass through Pending. closed_at is one shared terminal timestamp for both outcomes (mirrors Deal.actual_close_date), never clears due_date. Task.assigned_to is independent of Lead.assigned_to (copied once at creation, never synced). Task deletion is NOT Client-Admin-only like Deal's — see Show::canDeleteTask(): Client Admin deletes any task in an authorized lead; a non-admin only their own assigned task (never another's, never unassigned). tasks:send-alert-notifications is its own command (not folded into leads:send-alert-notifications) because Task's recipients() is keyed off the Task's own assignee, not the Lead's.
