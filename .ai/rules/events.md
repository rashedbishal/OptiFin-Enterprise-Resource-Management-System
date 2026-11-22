---
paths:
  - 'app/Events/{LeadReceived,LeadUpdated}.php'
---

# Events

## Lead broadcast events must stay ShouldRescue + ShouldDispatchAfterCommit
LeadReceived/LeadUpdated are ShouldBroadcastNow (sync), and Laravel broadcasts before running listeners. Without ShouldRescue a Reverb outage aborts the dispatch (LeadReceived's queued AutoAssign/notify/score listeners never queue) and, inside a transaction, rolls back the user's status/assignment change. ShouldDispatchAfterCommit holds the broadcast until commit so other boards never re-query pre-commit state. Don't drop either interface.
