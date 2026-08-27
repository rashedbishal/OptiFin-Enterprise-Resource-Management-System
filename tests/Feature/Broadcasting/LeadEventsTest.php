<?php

use App\Events\LeadReceived;
use App\Events\LeadUpdated;
use App\Models\Lead;
use App\Models\Tenant;
use Illuminate\Broadcasting\PrivateChannel;

test('lead received broadcasts on the tenant channel', function () {
    $tenant = Tenant::factory()->create();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    $event = new LeadReceived($lead);

    expect($event->broadcastOn())->toEqual([
        new PrivateChannel('tenant.'.$tenant->id),
    ]);
    expect($event->broadcastAs())->toBe('lead.received');
    expect($event->broadcastWith())->toBe(['id' => $lead->id]);
});

test('lead updated broadcasts on the tenant channel and the lead-specific channel', function () {
    $tenant = Tenant::factory()->create();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    $event = new LeadUpdated($lead);

    expect($event->broadcastOn())->toEqual([
        new PrivateChannel('tenant.'.$tenant->id),
        new PrivateChannel('lead.'.$lead->id),
    ]);
    expect($event->broadcastAs())->toBe('lead.updated');
    expect($event->broadcastWith())->toBe(['id' => $lead->id]);
});
