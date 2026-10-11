<?php

namespace App\Events;

use App\Models\Lead;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * ShouldBroadcastNow, not ShouldBroadcast: a new lead's broadcast must
 * reach the Client Admin's open board the instant it fires, not whenever
 * the queue worker next wakes from its `--sleep` interval (production runs
 * `queue:work --sleep=3`, confirmed via Supervisor — that sleep was the
 * actual cause of a real, reported delay before this change). This only
 * changes how THIS event's own broadcast is dispatched — its other
 * listeners (AutoAssignLead, SendNewLeadNotification,
 * RecomputeLeadScoreOnReceipt) all implement ShouldQueue independently and
 * stay queued exactly as before.
 */
class LeadReceived implements ShouldBroadcastNow
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
        ];
    }

    public function broadcastAs(): string
    {
        return 'lead.received';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->lead->id,
        ];
    }
}
