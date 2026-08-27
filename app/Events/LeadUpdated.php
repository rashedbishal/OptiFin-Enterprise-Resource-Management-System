<?php

namespace App\Events;

use App\Models\Lead;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast-only signal that an existing lead changed (e.g. a follow-up
 * webhook message got appended to it). Deliberately separate from
 * LeadReceived, which also drives auto-assignment and the "new lead"
 * notification — those must fire once, on creation, not on every follow-up.
 */
class LeadUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Lead $lead) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('tenant.'.$this->lead->tenant_id),
            new PrivateChannel('lead.'.$this->lead->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'lead.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['id' => $this->lead->id];
    }
}
