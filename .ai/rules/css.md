---
paths:
  - 'app/Livewire/Tenant/NotificationBell.php,resources/views/livewire/tenant/notification-bell.blade.php,resources/css/app.css'
---

# Css

## CORRECTION: removing the static class caused a flash-on-load bug — x-cloak was the actual missing piece
Follow-up correcting the earlier rule in this file ("Notification bell: instant-open via Alpine... x-show/static-class gotcha"). That rule's fix (remove the static `hidden` class entirely, rely on `x-show="open"` alone) solved the "won't open on click" bug but introduced a NEW one: with nothing hiding the dropdown in the raw server-rendered HTML, it visibly flashed open on every page load for the entire pre-hydration window (however long Alpine's script takes to download/parse/run) — user-reported ("why notification card opens suddenly" while the page loads). Confirmed via Playwright: sampling the dropdown's computed `display` every 50ms across an artificially-widened 700ms+ pre-hydration window showed it fully visible the whole time.

**Actual fix**: `x-cloak` on the dropdown div, PLUS a global `[x-cloak] { display: none !important; }` rule added to `resources/css/app.css` — which did not exist ANYWHERE in this app before now, meaning the codebase's other 4 `x-cloak` usages (`date-picker.blade.php`, `datetime-picker.blade.php`, `dropdown-select.blade.php`, `two-factor-challenge.blade.php`) were flagged as *possibly* silently non-functional in the earlier rule; this confirms they were, and the new global rule fixes all of them at once, not just the bell. `x-cloak` and `x-show` don't conflict the way the static class did: Alpine removes the `x-cloak` attribute itself the instant it finishes initializing an element, so the `!important` CSS rule has already stopped applying by the time `x-show`'s own inline-style toggling starts — they operate in strictly sequential phases (pre-hydration vs. post-hydration), never simultaneously fighting over the same declaration.

**Rule of thumb for any future `x-show`+`x-transition` element that needs a "hidden until Alpine hydrates" safety net**: use `x-cloak`, never a static `hidden`/`!hidden` class (confirmed broken) and never nothing at all (confirmed flashes). Verify with the same technique used here: throttle Alpine's script load and sample the element's computed `display` repeatedly across that window, don't just eyeball a screenshot.
