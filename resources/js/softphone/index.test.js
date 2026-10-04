import { describe, expect, it } from 'vitest';
import { registerSoftphone } from './index.js';

/**
 * WhatsApp inbound calling — the thin Alpine adapter in index.js forwards each
 * click handler to SoftphoneController by name, one property per method
 * (`decline: (id) => controller?.decline(id)`, etc.), so a method added to the
 * controller (like Step 7a's `hangup`) is NOT automatically callable from the
 * blade template — it must be forwarded here too, or Alpine throws "X is not
 * defined" the instant the button is clicked. controller.test.js exercises
 * SoftphoneController directly and cannot catch a missing forward; this is the
 * one place that does.
 */

function capturedComponent(config = { urls: {}, texts: {} }) {
    let factory;
    registerSoftphone({ data: (name, f) => { factory = f; } });

    return factory(config);
}

describe('the Alpine adapter forwards every softphone action the template calls', () => {
    it.each(['answer', 'decline', 'dismiss', 'hangup', 'retry', 'toggleMute', 'toggleSpeaker', 'enableAudio', 'toggleMinimized', 'format', 'init', 'destroy'])(
        'exposes %s as a callable method',
        (name) => {
            expect(typeof capturedComponent()[name]).toBe('function');
        },
    );

    it('before init() (controller not yet created), every forwarded call action is a safe no-op rather than a ReferenceError', () => {
        const component = capturedComponent();

        for (const name of ['answer', 'decline', 'dismiss', 'hangup', 'retry', 'toggleMute', 'toggleSpeaker', 'enableAudio']) {
            expect(() => component[name]('call-1')).not.toThrow();
        }
    });
});
