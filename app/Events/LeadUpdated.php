<?php

namespace App\Events;

use App\Models\Lead;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast-only signal that an existing lead changed (e.g. a follow-up
 * webhook message got appended to it). Deliberately separate from
 * LeadReceived, which also drives auto-assignment and the "new lead"
 * notification — those must fire once, on creation, not on every follow-up.
 *
 * ShouldBroadcastNow, not ShouldBroadcast — this event broadcasts and does
 * nothing else (no other listener depends on it being queued, unlike
 * LeadReceived), so there is no reason to ever wait on the queue worker's
 * `--sleep` interval for it. See LeadReceived's own docblock for the full
 * reasoning (same fix, same real reported delay).
 *
 * ShouldRescue + ShouldDispatchAfterCommit, for the same reasons as
 * LeadReceived: this event is fired from inside status/assignment/bulk
 * transactions, where a synchronous Reverb failure would otherwise roll back
 * the user's own change, and a pre-commit broadcast would make other open
 * boards re-query stale state (and add a network round-trip while the
 * tenant-row coordination lock is still held).
 */
class LeadUpdated implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
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
