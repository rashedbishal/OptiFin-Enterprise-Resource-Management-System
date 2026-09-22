import { DragGesture } from './drag.js';

/**
 * WhatsApp inbound calling — the Alpine adapter over DragGesture for the
 * mobile full-screen answer/decline buttons. Pointer capture means move/up
 * are routed straight to this element regardless of where the finger travels,
 * so no window-level listeners are needed. Purely presentational (an x/y
 * offset for the button's own transform while dragging); it never touches a
 * call — it only tells its parent a gesture completed via a dispatched event,
 * exactly like index.js forwards clicks, so the caller decides what
 * "triggered" means (answer vs decline).
 */
export function registerCallDragButton(alpine) {
    alpine.data('callDragButton', ({ tapThreshold = 8, dragThreshold = 64, maxOffset = 96 } = {}) => {
        let gesture = null;

        return {
            dx: 0,
            dy: 0,
            dragging: false,

            init() {
                gesture = new DragGesture({
                    tapThreshold,
                    dragThreshold,
                    onMove: (dx, dy, distance) => {
                        // The gesture's own threshold decision (drag.js) always
                        // uses the true, unclamped distance — only the visual
                        // transform is capped, so the button never drifts an
                        // unbounded distance from its resting position while
                        // still tracking the finger's direction faithfully.
                        const scale = distance > maxOffset ? maxOffset / distance : 1;
                        this.dx = dx * scale;
                        this.dy = dy * scale;
                        this.dragging = dx !== 0 || dy !== 0;
                    },
                    onTrigger: () => this.$dispatch('call-drag-trigger'),
                    onCancel: () => {},
                });
            },

            start(event) {
                this.$el.setPointerCapture?.(event.pointerId);
                gesture.start(event.clientX, event.clientY);
            },

            move(event) {
                gesture.move(event.clientX, event.clientY);
            },

            end(event) {
                gesture.end(event.clientX, event.clientY);
            },

            cancel() {
                gesture.cancel();
            },

            get dragStyle() {
                return this.dragging ? `transform: translate(${this.dx}px, ${this.dy}px)` : '';
            },
        };
    });
}
