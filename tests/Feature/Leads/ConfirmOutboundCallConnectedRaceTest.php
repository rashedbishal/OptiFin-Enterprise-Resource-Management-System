<?php

use App\Actions\Tenant\ConfirmOutboundCallConnected;
use App\Actions\Tenant\RecordOutboundCallEvent;
use App\Enums\CallEndReason;
use App\Enums\CallStatus;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Support\WhatsAppOutboundCallEvent;
use Tests\Support\WhatsAppCallFixtures as Wa;

/*
 * WhatsApp Outbound Calling — the real race the premature-"Connected"
 * architecture fix introduces: the browser confirming its own real WebRTC
 * connection (ConfirmOutboundCallConnected) versus a Meta terminate webhook
 * (RecordOutboundCallEvent::applyTerminate()) arriving for the same
 * Answering call, in both possible orderings. Both writers use a plain
 * conditional UPDATE — no lock is ever held across either — so whichever
 * request actually reaches the database first simply wins; this proves the
 * result is always consistent regardless of which one that is.
 */

function coccrScenario(): array
{
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $agent = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $source = Wa::source($tenant, [], ['access_token' => 'COCCR-sentinel-token']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'phone' => Wa::CALLER, 'assigned_to' => $agent->id]);
    $call = LeadCall::factory()->outbound()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'lead_source_id' => $source->id,
        'status' => CallStatus::Answering,
        'external_call_id' => 'wacid.RACE-CONFIRM-1',
        'claimed_by' => $agent->id,
        'routed_user_ids' => [$agent->id],
    ]);

    return ['tenant' => $tenant, 'agent' => $agent, 'source' => $source, 'lead' => $lead, 'call' => $call];
}

function coccrTerminateEvent(string $callId): WhatsAppOutboundCallEvent
{
    $change = ['value' => []];
    $item = ['id' => $callId, 'event' => 'terminate', 'direction' => 'BUSINESS_INITIATED', 'timestamp' => (string) now()->getTimestamp(), 'status' => 'COMPLETED'];

    $event = WhatsAppOutboundCallEvent::fromWebhook($change, $item);

    expect($event)->toBeInstanceOf(WhatsAppOutboundCallEvent::class);

    return $event;
}

test('terminate wins first: the browser\'s later confirm is refused, never resurrects or overwrites the terminal state', function () {
    $x = coccrScenario();

    $outcome = app(RecordOutboundCallEvent::class)->handle(coccrTerminateEvent('wacid.RACE-CONFIRM-1'), $x['source']);
    expect($outcome)->toBe(RecordOutboundCallEvent::OUTCOME_TERMINATE_APPLIED);
    expect($x['call']->fresh()->status)->toBe(CallStatus::Failed);

    $result = app(ConfirmOutboundCallConnected::class)->handle($x['agent'], $x['call']->id);

    expect($result->ok)->toBeFalse();
    expect($result->errorCode)->toBe('call_not_confirmable');

    $row = $x['call']->fresh();
    expect($row->status)->toBe(CallStatus::Failed);
    expect($row->end_reason)->toBe(CallEndReason::OutboundConnectFailed);
    expect($row->connected_at)->toBeNull();
});

test('confirm wins first: the browser\'s Connected sticks, and a terminate arriving after it cleanly ends the call as Completed', function () {
    $x = coccrScenario();

    $result = app(ConfirmOutboundCallConnected::class)->handle($x['agent'], $x['call']->id);
    expect($result->ok)->toBeTrue();
    expect($x['call']->fresh()->status)->toBe(CallStatus::Connected);
    $connectedAt = $x['call']->fresh()->connected_at;

    $outcome = app(RecordOutboundCallEvent::class)->handle(coccrTerminateEvent('wacid.RACE-CONFIRM-1'), $x['source']);
    expect($outcome)->toBe(RecordOutboundCallEvent::OUTCOME_TERMINATE_APPLIED);

    $row = $x['call']->fresh();
    expect($row->status)->toBe(CallStatus::Completed);
    expect($row->end_reason)->toBe(CallEndReason::RemoteTerminated);
    // connected_at, once set by the browser's own confirmation, is never
    // touched by the later terminate.
    expect($row->connected_at->eq($connectedAt))->toBeTrue();
});

test('a repeated confirm after the call is already Connected is idempotent, not a second race', function () {
    $x = coccrScenario();

    app(ConfirmOutboundCallConnected::class)->handle($x['agent'], $x['call']->id);
    $connectedAt = $x['call']->fresh()->connected_at;

    $result = app(ConfirmOutboundCallConnected::class)->handle($x['agent'], $x['call']->id);

    expect($result->ok)->toBeTrue();
    expect($x['call']->fresh()->connected_at->eq($connectedAt))->toBeTrue();
});
