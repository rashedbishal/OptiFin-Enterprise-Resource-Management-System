---
paths:
  - app/Livewire/Platform/LeadManagement/Settings.php
---

# Lead Management

## saveStatus()/saveCurrency() catch UniqueConstraintViolationException
The slugTaken/codeTaken pre-checks aren't atomic with the create()/update() that follows — a genuine concurrent race (two admins submitting the same name/code at once) can pass the pre-check on both requests before either insert commits. slug/code both have real DB unique() constraints, so the race can't produce a duplicate row, but without the try/catch around the create()/update() the loser got an uncaught UniqueConstraintViolationException (a raw 500) instead of the same graceful validation error the pre-check gives everyone else. Not covered by an automated test — deterministically forcing this exact race in a single-process synchronous test isn't practical without dependency-injection seams this codebase doesn't have (same limitation as the webhook dedupe locks); verified by code review instead.
