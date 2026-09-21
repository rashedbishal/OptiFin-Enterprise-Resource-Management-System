<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * WhatsApp inbound calling — "a call changed", broadcast to each user it was
 * routed to (the call's frozen routing snapshot), on their own private
 * `tenant-user.{id}` channel: the same per-user channel, and the same
 * authorization callback, the notification bell already uses, so a user can
 * only ever listen to their own and no new channel exists.
 *
 * One generic event rather than one per transition — the softphone is the only
 * consumer and treats every transition the same way (refetch its active calls),
 * so it never trusts this payload as state. The payload is therefore the call id
 * and its status at the moment of the transition and nothing else: no SDP, no
 * credentials, no phone number, no Meta data.
 *
 * Dispatched only through App\Support\LeadCallBroadcaster, which is what keeps
 * a broadcast failure from ever failing a webhook or an agent action.
 */
class LeadCallUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<int, string>  $recipientIds  TenantUser ids from the call's routing snapshot
     */
    public function __construct(
        public string $callId,
        public string $status,
        public array $recipientIds,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return array_map(
            fn (string $userId): PrivateChannel => new PrivateChannel('tenant-user.'.$userId),
            array_values(array_unique($this->recipientIds)),
        );
    }

    public function broadcastAs(): string
    {
        return 'call.updated';
    }

    /**
     * @return array{id: string, status: string}
     */
    public function broadcastWith(): array
    {
        return ['id' => $this->callId, 'status' => $this->status];
    }
}
