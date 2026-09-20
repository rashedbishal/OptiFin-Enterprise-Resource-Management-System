/**
 * Firefox doesn't support the CSS `overlay` property, which browsers need
 * to animate an element's removal from the top layer — so a native
 * <dialog>'s CSS-only exit transition (Flux's own @starting-style-based
 * approach, see resources/css/app.css) silently snaps shut instantly in
 * Firefox instead of sliding out, even though the exact same CSS makes the
 * *entry* transition work fine everywhere (entering the top layer doesn't
 * need `overlay`).
 *
 * This intercepts every way Flux's flyout can close — the X button and any
 * Livewire-driven close (both call the dialog's native .close(), which we
 * override per-instance) and Escape / click-outside (both go through a
 * cancelable native "cancel" event, confirmed by reading Flux's own source:
 * its click-outside handler manually dispatches the same "cancel" event
 * Escape uses, specifically so both are interceptable the same way) — and
 * runs a manual, ordinary CSS class-toggle transition *before* the dialog
 * is actually still open, so it doesn't depend on browser support for
 * animating top-layer removal at all. Only the timing changes; every other
 * bit of Flux's own dialog behavior (focus trap, backdrop, wire:model
 * sync) still runs exactly as before, just slightly deferred.
 */

const CLOSING_CLASS = 'flux-flyout-closing';
const CLOSE_ANIMATION_MS = 1000;

function animateThenClose(dialog, realClose) {
    dialog.classList.add(CLOSING_CLASS);
    // Force a reflow so the class is applied before the transition-relevant
    // styles below are considered "starting" values, matching how this
    // would behave with a manually-triggered CSS transition anywhere else.
    void dialog.offsetHeight;

    window.setTimeout(() => {
        dialog.classList.remove(CLOSING_CLASS);
        realClose();
    }, CLOSE_ANIMATION_MS);
}

function patchFlyout(dialog) {
    if (dialog.dataset.flyoutClosePatched) {
        return;
    }
    dialog.dataset.flyoutClosePatched = 'true';

    const nativeClose = HTMLDialogElement.prototype.close.bind(dialog);
    let animating = false;

    // Covers the X (close) button and any server-driven close (e.g.
    // Livewire setting the bound property to false) — both end up calling
    // the dialog's own .close(), which Flux's internal `hide()` invokes
    // directly without going through a cancelable event first.
    dialog.close = (...args) => {
        if (animating || !dialog.open) {
            nativeClose(...args);
            return;
        }
        animating = true;
        animateThenClose(dialog, () => {
            animating = false;
            nativeClose(...args);
        });
    };

    // Covers Escape and click-outside-to-dismiss — both fire a cancelable
    // native "cancel" event before the dialog would actually close (Flux's
    // click-outside handler dispatches this same event type deliberately,
    // to reuse the platform's own dismiss semantics).
    dialog.addEventListener('cancel', (event) => {
        if (animating) {
            return;
        }
        event.preventDefault();
        animating = true;
        animateThenClose(dialog, () => {
            animating = false;
            nativeClose();
        });
    });
}

function patchAllModals() {
    document.querySelectorAll('[data-flux-modal] > dialog, dialog[data-flux-flyout], dialog[data-modal]').forEach(patchFlyout);
}

document.addEventListener('livewire:init', patchAllModals);
document.addEventListener('livewire:navigated', patchAllModals);

// Safety net for a flyout or centered modal added to the DOM after the events above already
// fired (e.g. a component mounted later via a Livewire nested-component
// swap) — cheap to keep running for the page's lifetime since patchFlyout
// no-ops on anything already patched.
new MutationObserver(patchAllModals).observe(document.documentElement, {
    childList: true,
    subtree: true,
});
