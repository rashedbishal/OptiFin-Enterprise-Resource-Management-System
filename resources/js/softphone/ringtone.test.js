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
        this.listeners = {};
    }

    addEventListener(type, handler) {
        (this.listeners[type] ??= []).push(handler);
    }

    play() {
        this.playCalls += 1;
        this.paused = false;

        return Promise.resolve();
    }

    pause() {
        this.paused = true;
        // Real <audio> elements fire a native 'pause' event on every
        // pause() call, whoever called it — the OS, the browser, or our
        // own code — which is exactly the signal ringtone.js's own pause
        // listener relies on.
        for (const handler of this.listeners.pause ?? []) {
            handler();
        }
    }
}

function fakeDoc(visibilityState = 'visible') {
    const listeners = {};

    return {
        visibilityState,
        addEventListener: vi.fn((type, handler) => {
            (listeners[type] ??= []).push(handler);
        }),
        fireVisibilityChange(nextState) {
            this.visibilityState = nextState;

            for (const handler of listeners.visibilitychange ?? []) {
                handler();
            }
        },
    };
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

describe('createRingtone().prime()', () => {
    it('creates and silently unlocks the custom audio element without starting an active ring', async () => {
        const createAudioElement = vi.fn((src) => new FakeAudioElement(src));
        const ringtone = createRingtone({ audioUrl: 'https://example.test/ringtone.mp3', createAudioElement });

        ringtone.prime();
        await vi.waitFor(() => expect(createAudioElement).toHaveBeenCalledTimes(1));

        const audio = createAudioElement.mock.results[0].value;
        await vi.waitFor(() => expect(audio.paused).toBe(true));
        expect(audio.playCalls).toBe(1);
        expect(ringtone.isActive()).toBe(false);
    });

    it('mutes during the priming play, then restores the original muted state', async () => {
        const audio = new FakeAudioElement();
        audio.muted = false;
        const ringtone = createRingtone({ audioUrl: 'https://example.test/ringtone.mp3', createAudioElement: () => audio });

        ringtone.prime();

        expect(audio.muted).toBe(true);
        await vi.waitFor(() => expect(audio.paused).toBe(true));
        expect(audio.muted).toBe(false);
    });

    it('is a no-op for the synthesized path (no audioUrl)', () => {
        const createAudioElement = vi.fn();
        const ringtone = createRingtone({ AudioContext: FakeAudioContext, createAudioElement });

        ringtone.prime();

        expect(createAudioElement).not.toHaveBeenCalled();
    });

    it('never interferes with an already-active ring (would otherwise mute/pause it mid-ring)', () => {
        const createAudioElement = vi.fn((src) => new FakeAudioElement(src));
        const ringtone = createRingtone({ audioUrl: 'https://example.test/ringtone.mp3', createAudioElement });

        ringtone.start();
        const audio = createAudioElement.mock.results[0].value;
        audio.muted = false;

        ringtone.prime();

        expect(audio.muted).toBe(false);
        expect(audio.paused).toBe(false);
        expect(ringtone.isActive()).toBe(true);
    });

    it('is safe to call again after a ring has since stopped', async () => {
        const createAudioElement = vi.fn((src) => new FakeAudioElement(src));
        const ringtone = createRingtone({ audioUrl: 'https://example.test/ringtone.mp3', createAudioElement });

        ringtone.start();
        ringtone.stop();

        expect(() => ringtone.prime()).not.toThrow();
        await vi.waitFor(() => expect(createAudioElement.mock.results[0].value.paused).toBe(true));
        expect(createAudioElement).toHaveBeenCalledTimes(1);
    });

    it('never throws when construction or play() fails', async () => {
        const throwing = createRingtone({ audioUrl: 'https://example.test/ringtone.mp3', createAudioElement: () => { throw new Error('nope'); } });
        expect(() => throwing.prime()).not.toThrow();

        const rejecting = createRingtone({
            audioUrl: 'https://example.test/ringtone.mp3',
            createAudioElement: () => ({ muted: false, paused: true, currentTime: 0, play: () => Promise.reject(new Error('blocked')), pause: vi.fn() }),
        });
        expect(() => rejecting.prime()).not.toThrow();
    });
});

/*
 * The Media Session declaration — why the ring survives the tab being
 * backgrounded or the screen locked (mobile OSes exempt an active media
 * session from background-tab suspension). Proven with a fake
 * navigator.mediaSession + MediaMetadata rather than real ones (jsdom
 * implements neither).
 */
describe('createRingtone() resumes after an unexpected pause or backgrounded tab', () => {
    it('retries play() when the custom audio is paused while still the active ring (e.g. the OS silently stopped it)', () => {
        const createAudioElement = vi.fn((src) => new FakeAudioElement(src));
        const ringtone = createRingtone({ audioUrl: 'https://example.test/ringtone.mp3', createAudioElement });

        ringtone.start();
        const audio = createAudioElement.mock.results[0].value;
        const playCallsAfterStart = audio.playCalls;

        // Simulates the browser/OS pausing it out from under us — nothing
        // in this module itself called pause().
        audio.pause();

        expect(audio.playCalls).toBeGreaterThan(playCallsAfterStart);
        expect(ringtone.isActive()).toBe(true);
    });

    it('does NOT resume after our own stop() pauses it', () => {
        const createAudioElement = vi.fn((src) => new FakeAudioElement(src));
        const ringtone = createRingtone({ audioUrl: 'https://example.test/ringtone.mp3', createAudioElement });

        ringtone.start();
        const audio = createAudioElement.mock.results[0].value;

        ringtone.stop();

        expect(audio.playCalls).toBe(1);
        expect(audio.paused).toBe(true);
    });

    it('does NOT resume after prime()\'s own deliberate pause', async () => {
        const createAudioElement = vi.fn((src) => new FakeAudioElement(src));
        const ringtone = createRingtone({ audioUrl: 'https://example.test/ringtone.mp3', createAudioElement });

        ringtone.prime();
        await vi.waitFor(() => expect(createAudioElement.mock.results[0].value.paused).toBe(true));

        expect(createAudioElement.mock.results[0].value.playCalls).toBe(1);
    });

    it('retries play() when the tab becomes visible again while the custom ring is still active', () => {
        const createAudioElement = vi.fn((src) => new FakeAudioElement(src));
        const doc = fakeDoc('hidden');
        const ringtone = createRingtone({ audioUrl: 'https://example.test/ringtone.mp3', createAudioElement, doc });

        ringtone.start();
        const audio = createAudioElement.mock.results[0].value;
        const playCallsWhileHidden = audio.playCalls;

        doc.fireVisibilityChange('visible');

        expect(audio.playCalls).toBeGreaterThan(playCallsWhileHidden);
    });

    it('resumes the AudioContext when the tab becomes visible again while the synthesized ring is still active', () => {
        const doc = fakeDoc('hidden');
        const ringtone = createRingtone({ AudioContext: FakeAudioContext, timers: fakeTimers(), doc });

        ringtone.start();
        const context = FakeAudioContext.instances[0];
        context.resume = vi.fn(async () => undefined);

        doc.fireVisibilityChange('visible');

        expect(context.resume).toHaveBeenCalledOnce();
    });

    it('does nothing on visibilitychange while no ring is active', () => {
        const createAudioElement = vi.fn((src) => new FakeAudioElement(src));
        const doc = fakeDoc('hidden');
        createRingtone({ audioUrl: 'https://example.test/ringtone.mp3', createAudioElement, doc });

        expect(() => doc.fireVisibilityChange('visible')).not.toThrow();
        expect(createAudioElement).not.toHaveBeenCalled();
    });

    it('does nothing on visibilitychange to a non-visible state', () => {
        const createAudioElement = vi.fn((src) => new FakeAudioElement(src));
        const doc = fakeDoc('visible');
        const ringtone = createRingtone({ audioUrl: 'https://example.test/ringtone.mp3', createAudioElement, doc });

        ringtone.start();
        const playCallsAfterStart = createAudioElement.mock.results[0].value.playCalls;

        doc.fireVisibilityChange('hidden');

        expect(createAudioElement.mock.results[0].value.playCalls).toBe(playCallsAfterStart);
    });
});

function fakeMediaSession() {
    return { metadata: undefined, playbackState: undefined, setActionHandler: vi.fn() };
}

class FakeMediaMetadata {
    constructor(init) {
        Object.assign(this, init);
    }
}

describe('createRingtone() Media Session', () => {
    it('declares metadata and playbackState on start(), for the synthesized path', () => {
        const mediaSession = fakeMediaSession();
        const ringtone = createRingtone({ AudioContext: FakeAudioContext, timers: fakeTimers(), mediaSession, MediaMetadataClass: FakeMediaMetadata });

        ringtone.start({ title: 'Incoming WhatsApp call', artist: 'Jane Caller' });

        expect(mediaSession.metadata).toEqual(expect.objectContaining({ title: 'Incoming WhatsApp call', artist: 'Jane Caller' }));
        expect(mediaSession.playbackState).toBe('playing');
    });

    it('declares metadata and playbackState on start(), for the custom-audio path too', () => {
        const mediaSession = fakeMediaSession();
        const ringtone = createRingtone({
            audioUrl: 'https://example.test/ringtone.mp3',
            createAudioElement: () => new FakeAudioElement(),
            mediaSession,
            MediaMetadataClass: FakeMediaMetadata,
        });

        ringtone.start({ title: 'Incoming WhatsApp call', artist: 'Jane Caller' });

        expect(mediaSession.metadata).toEqual(expect.objectContaining({ title: 'Incoming WhatsApp call', artist: 'Jane Caller' }));
        expect(mediaSession.playbackState).toBe('playing');
    });

    it('falls back to a generic title/blank artist when no metadata is given', () => {
        const mediaSession = fakeMediaSession();
        const ringtone = createRingtone({ AudioContext: FakeAudioContext, timers: fakeTimers(), mediaSession, MediaMetadataClass: FakeMediaMetadata });

        ringtone.start();

        expect(mediaSession.metadata).toEqual(expect.objectContaining({ title: 'Incoming WhatsApp call', artist: '' }));
    });

    it('suppresses the seek/track controls, which make no sense for a ringing call', () => {
        const mediaSession = fakeMediaSession();
        const ringtone = createRingtone({ AudioContext: FakeAudioContext, timers: fakeTimers(), mediaSession, MediaMetadataClass: FakeMediaMetadata });

        ringtone.start();

        for (const action of ['seekbackward', 'seekforward', 'seekto', 'previoustrack', 'nexttrack', 'stop', 'skipad']) {
            expect(mediaSession.setActionHandler).toHaveBeenCalledWith(action, null);
        }
    });

    it('clears metadata and playbackState on stop()', () => {
        const mediaSession = fakeMediaSession();
        const ringtone = createRingtone({ AudioContext: FakeAudioContext, timers: fakeTimers(), mediaSession, MediaMetadataClass: FakeMediaMetadata });

        ringtone.start();
        ringtone.stop();

        expect(mediaSession.metadata).toBeNull();
        expect(mediaSession.playbackState).toBe('none');
    });

    it('is a no-op, never throws, when navigator.mediaSession is unsupported', () => {
        const ringtone = createRingtone({ AudioContext: FakeAudioContext, timers: fakeTimers(), mediaSession: undefined });

        expect(() => ringtone.start({ title: 'x' })).not.toThrow();
        expect(() => ringtone.stop()).not.toThrow();
    });

    it('a throwing mediaSession (e.g. a locked-down browser) never blocks the ring', () => {
        const mediaSession = {
            get metadata() { return undefined; },
            set metadata(_v) { throw new Error('denied'); },
            playbackState: undefined,
            setActionHandler: vi.fn(),
        };
        const ringtone = createRingtone({ AudioContext: FakeAudioContext, timers: fakeTimers(), mediaSession, MediaMetadataClass: FakeMediaMetadata });

        expect(() => ringtone.start()).not.toThrow();
        expect(ringtone.isActive()).toBe(true);
    });
});
