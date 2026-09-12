<?php

namespace App\Events;

use App\Models\LeadImport;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fires exactly twice per import, at Feature #7's two existing terminal
 * finalization points (App\Imports\LeadCsvImport::registerEvents()'s
 * AfterImport listener, and its failed() hook) — never per-row, never on
 * the Pending -> Processing transition. ShouldDispatchAfterCommit is
 * defensive: neither finalization call site currently wraps its
 * LeadImport::update() in a transaction (each is a single autocommitting
 * UPDATE), but this guarantees the event can never broadcast the outcome
 * before it's durably persisted even if that changes later.
 */
class LeadImportStatusChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public LeadImport $leadImport) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('lead-import.'.$this->leadImport->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'lead-import.status-changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->leadImport->id,
        ];
    }
}
