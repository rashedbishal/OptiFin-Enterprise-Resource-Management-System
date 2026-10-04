import { describe, expect, it, vi } from 'vitest';
import { canToggleSpeaker, findAudioOutputDevices, setAudioOutputDevice } from './audio-output.js';

/*
 * WhatsApp calling — the earpiece/loudspeaker toggle's device-detection and
 * sinkId-switching, independent of AnswerSession/OutboundSession.
 */

function fakeMediaDevices(devices) {
    return { enumerateDevices: vi.fn(async () => devices) };
}

describe('findAudioOutputDevices()', () => {
    it('finds a speaker-labelled and an earpiece-labelled output device', async () => {
        const mediaDevices = fakeMediaDevices([
            { kind: 'audioinput', label: 'Microphone', deviceId: 'mic-1' },
            { kind: 'audiooutput', label: 'Earpiece', deviceId: 'earpiece-1' },
            { kind: 'audiooutput', label: 'Speaker', deviceId: 'speaker-1' },
        ]);

        await expect(findAudioOutputDevices(mediaDevices)).resolves.toEqual({
            speakerId: 'speaker-1',
            earpieceId: 'earpiece-1',
        });
    });

    it('matches "Speakerphone" and "Receiver" label variants', async () => {
        const mediaDevices = fakeMediaDevices([
            { kind: 'audiooutput', label: 'Built-in Receiver', deviceId: 'receiver-1' },
            { kind: 'audiooutput', label: 'Speakerphone', deviceId: 'speakerphone-1' },
        ]);

        await expect(findAudioOutputDevices(mediaDevices)).resolves.toEqual({
            speakerId: 'speakerphone-1',
            earpieceId: 'receiver-1',
        });
    });

    it('resolves nulls when only one generic output device is exposed', async () => {
        const mediaDevices = fakeMediaDevices([{ kind: 'audiooutput', label: 'Default', deviceId: 'default-1' }]);

        await expect(findAudioOutputDevices(mediaDevices)).resolves.toEqual({ speakerId: null, earpieceId: null });
    });

    it('resolves nulls, never throws, when enumerateDevices() is unsupported', async () => {
        await expect(findAudioOutputDevices({})).resolves.toEqual({ speakerId: null, earpieceId: null });
    });

    it('resolves nulls, never throws, when enumerateDevices() rejects', async () => {
        const mediaDevices = { enumerateDevices: vi.fn(async () => { throw new Error('permission revoked'); }) };

        await expect(findAudioOutputDevices(mediaDevices)).resolves.toEqual({ speakerId: null, earpieceId: null });
    });
});

describe('canToggleSpeaker()', () => {
    it('is true once a distinct speaker device was found', () => {
        expect(canToggleSpeaker({ speakerId: 'speaker-1', earpieceId: null })).toBe(true);
    });

    it('is false with no speaker device, even with an earpiece one', () => {
        expect(canToggleSpeaker({ speakerId: null, earpieceId: 'earpiece-1' })).toBe(false);
    });
});

describe('setAudioOutputDevice()', () => {
    it('calls setSinkId with the given device id', async () => {
        const audioEl = { setSinkId: vi.fn(async () => undefined) };

        await expect(setAudioOutputDevice(audioEl, 'speaker-1')).resolves.toBe(true);
        expect(audioEl.setSinkId).toHaveBeenCalledWith('speaker-1');
    });

    it('passes an empty string for a null device id (system default)', async () => {
        const audioEl = { setSinkId: vi.fn(async () => undefined) };

        await expect(setAudioOutputDevice(audioEl, null)).resolves.toBe(true);
        expect(audioEl.setSinkId).toHaveBeenCalledWith('');
    });

    it('resolves false, never throws, when setSinkId is unsupported (iOS Safari)', async () => {
        await expect(setAudioOutputDevice({}, 'speaker-1')).resolves.toBe(false);
    });

    it('resolves false, never throws, when setSinkId() rejects', async () => {
        const audioEl = { setSinkId: vi.fn(async () => { throw new Error('NotFoundError'); }) };

        await expect(setAudioOutputDevice(audioEl, 'speaker-1')).resolves.toBe(false);
    });
});
