<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * WhatsApp inbound calling — wakes a routed recipient's OS even when their
 * browser is closed/backgrounded (Web Push), so the softphone's own in-tab
 * ring (resources/js/softphone/ringtone.js) isn't the only cue. Sent by
 * App\Jobs\SendIncomingCallPushNotification, only for a call still Ringing
 * at send time.
 *
 * webpush-only (never 'database') — deliberately not a bell entry: the bell
 * is for durable, click-through records (MissedCallNotification etc.); this
 * is a transient "your phone is ringing right now" wake-up that's pointless
 * once the call is answered or missed, same reasoning
 * MeetingCancelledNotification's docblock gives for picking its own channel
 * rather than reusing an unrelated one.
 *
 * tag(callId) + renotify(true) means a second push for the same call (e.g. a
 * retried job) replaces the OS notification instead of stacking a duplicate;
 * requireInteraction(true) keeps it visible until the user acts, matching a
 * real incoming-call alert rather than a disappearing toast.
 */
class IncomingWhatsAppCallNotification extends Notification
{
    public function __construct(
        private readonly string $callId,
        private readonly string $leadId,
        private readonly ?string $callerName,
        private readonly ?string $callerPhone,
    ) {}

    /**
     * WebPushChannel::class, not the string 'webpush' — this package's own
     * service provider never calls Notification::extend('webpush', ...), so
     * Laravel's ChannelManager can only resolve it as a literal class name
     * (see ChannelManager::createDriver()'s class_exists() fallback); a
     * short-alias string here throws "Driver [webpush] not supported."
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, self $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title(__('Incoming WhatsApp call'))
            ->body($this->callerName ?? $this->callerPhone ?? __('Unknown caller'))
            ->icon('/apple-touch-icon.png')
            ->tag('whatsapp-call-'.$this->callId)
            ->renotify(true)
            ->requireInteraction(true)
            ->data(['url' => route('tenant-leads.show', $this->leadId)]);
    }
}
