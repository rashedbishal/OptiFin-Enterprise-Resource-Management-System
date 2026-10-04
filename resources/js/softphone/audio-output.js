/**
 * WhatsApp inbound/outbound calling — toggles the remote call audio between
 * the earpiece and the loudspeaker on a phone/tablet, via
 * HTMLMediaElement.setSinkId() (the Audio Output Devices API). Shared by
 * AnswerSession and OutboundSession, which each own their own <audio>
 * element — kept as plain functions rather than a class since there is no
 * state to hold here, only the sessions' own speakerOn/speakerAvailable do.
 *
 * Chrome/Edge on Android only. iOS Safari has no equivalent API at all
 * (Apple exposes no Audio Output Devices API to web pages — a hard
 * platform limitation, not something fixable here), so detectSpeakerSupport()
 * simply resolves unavailable there, same "a missing capability is never a
 * gate" principle used throughout this module (ringback/ringtone/push).
 */

/**
 * Finds distinct loudspeaker/earpiece output devices by label, after the
 * call's own getUserMedia grant has already populated real device labels
 * (enumerateDevices() returns blank labels without an active mic
 * permission — never requested here on its own). Best-effort: device label
 * conventions are not standardized, so this only recognizes the common
 * ones ("Speaker"/"Speakerphone" and "Earpiece"/"Receiver").
 *
 * @return {Promise<{speakerId: ?string, earpieceId: ?string}>}
 */
export async function findAudioOutputDevices(mediaDevices) {
    if (typeof mediaDevices?.enumerateDevices !== 'function') {
        return { speakerId: null, earpieceId: null };
    }

    try {
        const devices = await mediaDevices.enumerateDevices();
        const outputs = devices.filter((device) => device.kind === 'audiooutput');

        return {
            speakerId: outputs.find((device) => /speaker/i.test(device.label))?.deviceId ?? null,
            earpieceId: outputs.find((device) => /earpiece|receiver/i.test(device.label))?.deviceId ?? null,
        };
    } catch {
        return { speakerId: null, earpieceId: null };
    }
}

/**
 * Only meaningful once a distinct speaker device was actually found — an
 * earpiece-labelled one is a bonus (used when switching back), not a
 * requirement: the empty-string "system default" sinkId is a reasonable
 * fallback for "not the speaker" on a phone, since the call audio was the
 * browser's own generic media routing to begin with (the original problem
 * this feature exists for).
 */
export function canToggleSpeaker({ speakerId }) {
    return typeof speakerId === 'string';
}

export async function setAudioOutputDevice(audioEl, deviceId) {
    if (typeof audioEl?.setSinkId !== 'function') {
        return false;
    }

    try {
        await audioEl.setSinkId(deviceId ?? '');

        return true;
    } catch {
        return false;
    }
}
