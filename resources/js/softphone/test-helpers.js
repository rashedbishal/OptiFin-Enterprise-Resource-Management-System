import { vi } from 'vitest';

// Browser API mocks for the softphone unit tests. They model the SHAPE of
// RTCPeerConnection / MediaStream / HTMLAudioElement closely enough to check the
// order in which the softphone drives them — they do not, and cannot, prove that
// real WebRTC audio connects.

export const OFFER_SDP = 'v=0\r\no=- 1 2 IN IP4 127.0.0.1\r\ns=OFFER-SENTINEL\r\nt=0 0\r\nm=audio 9 UDP/TLS/RTP/SAVPF 111\r\n';
export const ANSWER_SDP = 'v=0\r\no=- 9 2 IN IP4 127.0.0.1\r\ns=ANSWER-SENTINEL\r\nt=0 0\r\nm=audio 9 UDP/TLS/RTP/SAVPF 111\r\na=setup:active\r\n';

export function fakeStream() {
    const tracks = [{ kind: 'audio', enabled: true, stopped: false, stop() { this.stopped = true; } }];

    return { tracks, getAudioTracks: () => tracks, getTracks: () => tracks };
}

export class FakePeerConnection {
    static instances = [];

    constructor(config) {
        this.config = config;
        this.log = [];
        this.connectionState = 'new';
        this.closed = false;
        FakePeerConnection.instances.push(this);
    }

    async setRemoteDescription(description) {
        this.log.push(['setRemoteDescription', description]);
    }

    addTrack(track) {
        this.log.push(['addTrack', track.enabled]);
    }

    async createAnswer() {
        this.log.push(['createAnswer']);

        return { type: 'answer', sdp: ANSWER_SDP };
    }

    async setLocalDescription(description) {
        this.log.push(['setLocalDescription', description]);
    }

    close() {
        this.closed = true;
    }

    setState(state) {
        this.connectionState = state;
        this.onconnectionstatechange?.();
    }
}

export function fakeAudio({ playRejects = false } = {}) {
    const audio = {
        srcObject: null,
        play: vi.fn(async () => {
            if (audio.rejectPlay) {
                throw new Error('NotAllowedError');
            }
        }),
        pause: vi.fn(),
        rejectPlay: playRejects,
    };

    return audio;
}

export function fakeApi(overrides = {}) {
    return {
        active: vi.fn(async () => []),
        claim: vi.fn(async () => ({ call: { id: 'c1', status: 'answering' }, session: { sdp_type: 'offer', sdp: OFFER_SDP } })),
        answer: vi.fn(async () => ({ call: { id: 'c1', status: 'answering' } })),
        accept: vi.fn(async () => ({ call: { id: 'c1', status: 'connected' } })),
        reject: vi.fn(async () => ({ call: { id: 'c1', status: 'rejected' }, meta_confirmed: true })),
        ...overrides,
    };
}

export function rawCall(overrides = {}) {
    return {
        id: 'c1',
        status: 'ringing',
        claimed_by_me: false,
        lead: { id: 'l1', name: 'Ada Lovelace', phone: '+15551230000' },
        expires_in_seconds: 45,
        connected_at: null,
        ...overrides,
    };
}
