<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\Fcm\FcmMessage;

/**
 * WhatsApp calling companion app — a DATA-ONLY, high-priority Firebase push
 * to one paired phone (the CallingAppDevice is the notifiable). Data-only on
 * purpose: the app's own background handler turns `incoming_call` into its
 * full-screen ringing screen and `call_ended` into stopping it — a
 * system-drawn notification would bypass both.
 *
 * Carries only what the ringing screen shows (call id, lead name/phone) — no
 * SDP, no token, nothing Meta-side. FCM data values must be strings.
 */
class CallingAppCallPush extends Notification
{
    public const INCOMING_CALL = 'incoming_call';

    public const CALL_ENDED = 'call_ended';

    /**
     * @param  array<string, string>  $data
     */
    private function __construct(
        public readonly string $type,
        public readonly array $data,
        public readonly int $ttlSeconds,
    ) {}

    public static function incomingCall(string $callId, ?string $leadName, ?string $leadPhone, int $ringSecondsLeft): self
    {
        return new self(self::INCOMING_CALL, [
            'call_id' => $callId,
            'lead_name' => (string) $leadName,
            'lead_phone' => (string) $leadPhone,
            'ring_seconds' => (string) $ringSecondsLeft,
        ], max(1, $ringSecondsLeft));
    }

    public static function callEnded(string $callId): self
    {
        return new self(self::CALL_ENDED, ['call_id' => $callId], 120);
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [FcmChannel::class];
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return (new FcmMessage)
            ->data(['type' => $this->type, ...$this->data])
            ->custom([
                'android' => [
                    // High priority is what lets a data message wake a dozing
                    // phone; the TTL drops a ring push that couldn't be
                    // delivered before the call stopped ringing anyway.
                    'priority' => 'high',
                    'ttl' => $this->ttlSeconds.'s',
                ],
                'apns' => [
                    'headers' => ['apns-priority' => '10'],
                    'payload' => ['aps' => ['content-available' => 1]],
                ],
            ]);
    }
}
