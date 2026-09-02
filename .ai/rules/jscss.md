---
paths:
  - 'resources/{js,css}/**'
---

# Jscss

## Firefox can't animate native <dialog> exit — flyout-close-animation.js works around it
Firefox doesn't support the CSS `overlay` property (`CSS.supports('overlay','auto')` → false), which is required to animate an element's removal from the top layer. Flux's flyout modal (`<flux:modal variant="flyout">`) uses a native `<dialog>` + `@starting-style` + `transition-behavior: allow-discrete` for its open/close slide — this makes the *entry* animation work everywhere (entering the top layer doesn't need `overlay`), but the *exit* animation silently no-ops in Firefox: `.close()` just snaps straight to `display:none`.

Fix lives in `resources/js/flyout-close-animation.js` (imported from `app.js`) + the `.flux-flyout-closing` CSS rule in `resources/css/app.css`. It intercepts every close path — verified by reading Flux's minified source (`vendor/livewire/flux/dist/flux-lite.min.js`): the X button and any Livewire-driven close both call the dialog's native `.close()` directly (`_closeable.close()` → `_dialogable.hide()` → `el.close()`), overridden per-instance; Escape and click-outside both fire a cancelable native `cancel` event first (Flux's click-outside handler deliberately dispatches the same event type), intercepted via `preventDefault()`. Both funnel into `animateThenClose()`: add a class, wait for a plain (non-`allow-discrete`) CSS transition while the dialog is still open/in the top layer, then call the real native close.

If Flux is upgraded, re-verify this still matches its internal close mechanism (grep the new `flux-lite.min.js` for `_closeable`/`_dialogable`/`hide()`) — a Flux internals change could silently break the interception. See [[Make the flyout actually slide its full width instead of a 50px nudge]] for the related entry-animation work.
