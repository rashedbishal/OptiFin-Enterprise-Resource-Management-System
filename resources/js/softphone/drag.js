/**
 * WhatsApp inbound calling — the mobile full-screen answer/decline buttons'
 * drag-or-tap gesture. Framework-free on purpose, exactly like
 * SoftphoneController, so it can be unit-tested with plain numbers and no
 * DOM/Alpine/PointerEvent involved at all.
 *
 * Two thresholds decide the outcome of one press-move-release cycle:
 *  - tapThreshold: if the pointer never moved further than this from where it
 *    started, the whole gesture is a tap — triggers immediately regardless of
 *    where it happens to be released (a tap's natural small jitter is not a
 *    drag).
 *  - dragThreshold: once the pointer has moved past tapThreshold at least
 *    once (a real drag was attempted), the gesture only triggers if the
 *    FINAL release position is at least this far from the start; releasing
 *    anywhere short of it cancels (the button visually snaps back — decided
 *    by wherever the finger actually let go, not the furthest point reached).
 */
export class DragGesture {
    constructor({ tapThreshold = 8, dragThreshold = 64, onTrigger = () => {}, onCancel = () => {}, onMove = () => {} } = {}) {
        this.tapThreshold = tapThreshold;
        this.dragThreshold = dragThreshold;
        this.onTrigger = onTrigger;
        this.onCancel = onCancel;
        this.onMove = onMove;

        this.active = false;
        this.startX = 0;
        this.startY = 0;
        this.maxDistance = 0;
    }

    start(x, y) {
        this.active = true;
        this.startX = x;
        this.startY = y;
        this.maxDistance = 0;
        this.onMove(0, 0, 0);
    }

    move(x, y) {
        if (!this.active) {
            return;
        }

        const dx = x - this.startX;
        const dy = y - this.startY;
        const distance = Math.hypot(dx, dy);
        this.maxDistance = Math.max(this.maxDistance, distance);
        this.onMove(dx, dy, distance);
    }

    end(x, y) {
        if (!this.active) {
            return;
        }

        this.active = false;
        const dx = x - this.startX;
        const dy = y - this.startY;
        const releaseDistance = Math.hypot(dx, dy);
        this.onMove(0, 0, 0);

        if (this.maxDistance <= this.tapThreshold || releaseDistance >= this.dragThreshold) {
            this.onTrigger();
        } else {
            this.onCancel();
        }
    }

    /**
     * A pointer that never resolves to an up (pointercancel, losing capture,
     * or being torn down mid-drag) always snaps back — never triggers.
     */
    cancel() {
        if (!this.active) {
            return;
        }

        this.active = false;
        this.onMove(0, 0, 0);
        this.onCancel();
    }
}
