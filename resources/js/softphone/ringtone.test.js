import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createRingtone } from './ringtone.js';

/*
 * WhatsApp inbound calling — mirrors outbound-media.test.js's ringback test
 * doubles exactly (a minimal Web Audio double proving ordering/idempotency,
 * not real audio).
 */

class FakeGainNode {
    constructor() {
        this.gain = { value: 0, setValueAtTime: vi.fn(), linearRampToValueAtTime: vi.fn(), cancelScheduledValues: vi.fn() };
        this.disconnected = false;
    }

    connect() {}

    disconnect() {
        this.disconnected = true;
    }
}

class FakeOscillatorNode {
    constructor() {
        this.frequency = { value: 0 };
        this.type = 'sine';
        this.started = false;
        this.stopped = false;
        this.disconnected = false;
    }

    connect() {}

    disconnect() {
        this.disconnected = true;
    }

    start() {
        this.started = true;
    }

    stop() {
        this.stopped = true;
    }
}

class FakeAudioContext {
    constructor() {
        this.currentTime = 0;
        this.closed = false;
        this.destination = {};
        FakeAudioContext.instances.push(this);
    }

    createGain() {
        this.lastGain = new FakeGainNode();

        return this.lastGain;
    }

    createOscillator() {
        this.lastOscillator = new FakeOscillatorNode();

        return this.lastOscillator;
    }

    close() {
        this.closed = true;

        return Promise.resolve();
    }
}
FakeAudioContext.instances = [];

class ThrowingAudioContext {
    constructor() {
        throw new Error('AudioContext is not supported.');
    }
}

function fakeTimers() {
    let nextId = 1;
    const active = new Map();

    return {
        setInterval: (fn) => {
            const id = nextId++;
            active.set(id, fn);

            return id;
        },
        clearInterval: (id) => {
            active.delete(id);
        },
        active,
    };
}

beforeEach(() => {
    FakeAudioContext.instances = [];
});

describe('createRingtone()', () => {
    it('does nothing when no AudioContext is wired in (unsupported browser)', () => {
        const ringtone = createRingtone({});

        expect(() => ringtone.start()).not.toThrow();
        expect(ringtone.isActive()).toBe(false);
    });

    it('start() creates and starts one oscillator, and is idempotent', () => {
        const timers = fakeTimers();
        const ringtone = createRingtone({ AudioContext: FakeAudioContext, timers });

        ringtone.start();
        ringtone.start();

        expect(FakeAudioContext.instances).toHaveLength(1);
        expect(FakeAudioContext.instances[0].lastOscillator.started).toBe(true);
        expect(ringtone.isActive()).toBe(true);
    });

    it('stop() tears the oscillator/context down and is safe to call twice', () => {
        const timers = fakeTimers();
        const ringtone = createRingtone({ AudioContext: FakeAudioContext, timers });

        ringtone.start();
        const context = FakeAudioContext.instances[0];

        ringtone.stop();
        ringtone.stop();

        expect(context.lastOscillator.stopped).toBe(true);
        expect(context.lastOscillator.disconnected).toBe(true);
        expect(context.closed).toBe(true);
        expect(ringtone.isActive()).toBe(false);
    });

    it('stop() before any start() is a safe no-op', () => {
        const ringtone = createRingtone({ AudioContext: FakeAudioContext, timers: fakeTimers() });

        expect(() => ringtone.stop()).not.toThrow();
    });

    it('AudioContext construction failure never throws — the call proceeds with no audible cue', () => {
        const ringtone = createRingtone({ AudioContext: ThrowingAudioContext, timers: fakeTimers() });

        expect(() => ringtone.start()).not.toThrow();
        expect(ringtone.isActive()).toBe(false);
    });

    it('with no audioUrl, construction never touches the audio-element factory', () => {
        const createAudioElement = vi.fn();
        const ringtone = createRingtone({ AudioContext: FakeAudioContext, timers: fakeTimers(), createAudioElement });

        ringtone.start();

        expect(createAudioElement).not.toHaveBeenCalled();
    });

    it('the cadence interval re-bursts the gain envelope without creating new nodes', () => {
        const timers = fakeTimers();
        const ringtone = createRingtone({ AudioContext: FakeAudioContext, timers });

        ringtone.start();
        const gain = FakeAudioContext.instances[0].lastGain;
        const callsAfterStart = gain.gain.linearRampToValueAtTime.mock.calls.length;

        for (const tick of timers.active.values()) {
            tick();
        }

        expect(FakeAudioContext.instances).toHaveLength(1);
        expect(gain.gain.linearRampToValueAtTime.mock.calls.length).toBeGreaterThan(callsAfterStart);
    });
});

/*
 * The custom-MP3 ringtone path (tenant-uploaded, via CallingSettings\Index)
 * — a completely separate code path from the synthesized tone above, proven
 * here with a fake <audio> element rather than a real one (jsdom has no
 * real audio playback).
 */
class FakeAudioElement {
    constructor(src) {
        this.src = src;
        this.loop = false;
        this.currentTime = 0;
        this.paused = true;
        this.playCalls = 0;
    }

    play() {
        this.playCalls += 1;
        this.paused = false;

        return Promise.resolve();
    }

    pause() {
        this.paused = true;
    }
}

describe('createRingtone() with a custom audioUrl', () => {
    it('start() plays a looping <audio> element pointed at the url instead of synthesizing a tone', () => {
        const createAudioElement = vi.fn((src) => new FakeAudioElement(src));
        const ringtone = createRingtone({ AudioContext: FakeAudioContext, audioUrl: 'https://example.test/ringtone.mp3', createAudioElement });

        ringtone.start();

        expect(createAudioElement).toHaveBeenCalledWith('https://example.test/ringtone.mp3');
        expect(FakeAudioContext.instances).toHaveLength(0);
        expect(ringtone.isActive()).toBe(true);

        const audio = createAudioElement.mock.results[0].value;
        expect(audio.loop).toBe(true);
        expect(audio.playCalls).toBe(1);
    });

    it('start() is idempotent for the custom path too — a second call does not re-create the element', () => {
        const createAudioElement = vi.fn((src) => new FakeAudioElement(src));
        const ringtone = createRingtone({ audioUrl: 'https://example.test/ringtone.mp3', createAudioElement });

        ringtone.start();
        ringtone.start();

        expect(createAudioElement).toHaveBeenCalledTimes(1);
    });

    it('stop() pauses and resets the custom audio element', () => {
        const createAudioElement = vi.fn((src) => new FakeAudioElement(src));
        const ringtone = createRingtone({ audioUrl: 'https://example.test/ringtone.mp3', createAudioElement });

        ringtone.start();
        const audio = createAudioElement.mock.results[0].value;
        audio.currentTime = 1.5;

        ringtone.stop();

        expect(audio.paused).toBe(true);
        expect(audio.currentTime).toBe(0);
        expect(ringtone.isActive()).toBe(false);
    });

    it('stop() before any start() is a safe no-op for the custom path too', () => {
        const ringtone = createRingtone({ audioUrl: 'https://example.test/ringtone.mp3', createAudioElement: () => new FakeAudioElement() });

        expect(() => ringtone.stop()).not.toThrow();
    });

    it('a rejected play() promise (autoplay blocked) never throws', () => {
        const createAudioElement = vi.fn(() => ({ loop: false, currentTime: 0, play: () => Promise.reject(new Error('autoplay blocked')), pause: vi.fn() }));
        const ringtone = createRingtone({ audioUrl: 'https://example.test/ringtone.mp3', createAudioElement });

        expect(() => ringtone.start()).not.toThrow();
        expect(ringtone.isActive()).toBe(true);
    });

    it('an element-construction failure never throws — the call proceeds with no audible cue', () => {
        const ringtone = createRingtone({
            audioUrl: 'https://example.test/ringtone.mp3',
            createAudioElement: () => {
                throw new Error('Audio is not supported.');
            },
        });

        expect(() => ringtone.start()).not.toThrow();
        expect(ringtone.isActive()).toBe(false);
    });

    it('start()/stop() can cycle more than once, reusing the same element', () => {
        const createAudioElement = vi.fn((src) => new FakeAudioElement(src));
        const ringtone = createRingtone({ audioUrl: 'https://example.test/ringtone.mp3', createAudioElement });

        ringtone.start();
        ringtone.stop();
        ringtone.start();
        ringtone.stop();

        expect(createAudioElement).toHaveBeenCalledTimes(1);
    });
});
