<?php

use App\Actions\Tenant\FinalizeStaleLeadCalls;
use App\Enums\CallDirection;
use App\Enums\CallEndReason;
use App\Enums\CallStatus;
use App\Models\Lead;
use App\Models\LeadCall;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\WhatsAppCallFixtures as Wa;

/*
 * WhatsApp Outbound Calling — the three new/adjusted stale-reconciliation
 * cases (calls:reconcile-stale): Initiated with no wacid, Ringing without a
 * terminate, and confirming Connected-without-terminate already worked
 * unchanged. The full inbound suite (including the compare-and-set race
 * behavior this reuses byte-for-byte) is tests/Feature/Leads/
 * ReconcileStaleLeadCallsTest.php — not duplicated here.
 */

beforeEach(function () {
    Queue::fake();
    Event::fake();
});

function osrSweep(): array
{
    return app(FinalizeStaleLeadCalls::class)->handle();
}

function osrScenario(): array
{
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $agent = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $source = Wa::source($tenant);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    return ['tenant' => $tenant, 'agent' => $agent, 'source' => $source, 'lead' => $lead];
}

test('an outbound Ringing call past the grace window is failed as consumer_no_answer_outbound, never missed/ring_timeout', function () {
    $x = osrScenario();
    $call = LeadCall::factory()->create([
        'tenant_id' => $x['tenant']->id, 'lead_id' => $x['lead']->id, 'lead_source_id' => $x['source']->id,
        'direction' => CallDirection::Outbound, 'external_call_id' => 'wacid.STALE-RING-1',
        'status' => CallStatus::Ringing, 'claimed_by' => $x['agent']->id, 'ringing_expires_at' => now()->subSeconds(300),
    ]);

    $summary = osrSweep();

    $fresh = $call->fresh();
    expect($fresh->status)->toBe(CallStatus::Failed);
    expect($fresh->end_reason)->toBe(CallEndReason::ConsumerNoAnswerOutbound);
    expect($fresh->ended_at)->not->toBeNull();
    expect($summary['failed_ring_outbound'])->toBe(1);
    expect($summary['missed_ring'])->toBe(0);
});

test('an outbound Ringing call still inside the grace window is left untouched', function () {
    $x = osrScenario();
    $call = LeadCall::factory()->create([
        'tenant_id' => $x['tenant']->id, 'lead_id' => $x['lead']->id, 'lead_source_id' => $x['source']->id,
        'direction' => CallDirection::Outbound, 'external_call_id' => 'wacid.FRESH-RING-1',
        'status' => CallStatus::Ringing, 'claimed_by' => $x['agent']->id, 'ringing_expires_at' => now()->addSeconds(30),
    ]);

    osrSweep();

    expect($call->fresh()->status)->toBe(CallStatus::Ringing);
});

test('an inbound Ringing call and an outbound Ringing call in the same sweep each get their own correct outcome', function () {
    $x = osrScenario();
    $outbound = LeadCall::factory()->create([
        'tenant_id' => $x['tenant']->id, 'lead_id' => $x['lead']->id, 'lead_source_id' => $x['source']->id,
        'direction' => CallDirection::Outbound, 'external_call_id' => 'wacid.MIX-OUT-1',
        'status' => CallStatus::Ringing, 'claimed_by' => $x['agent']->id, 'ringing_expires_at' => now()->subSeconds(300),
    ]);
    $inbound = LeadCall::factory()->create([
        'tenant_id' => $x['tenant']->id, 'lead_id' => $x['lead']->id, 'lead_source_id' => $x['source']->id,
        'direction' => CallDirection::Inbound, 'external_call_id' => 'wacid.MIX-IN-1',
        'status' => CallStatus::Ringing, 'routed_user_ids' => [], 'ringing_expires_at' => now()->subSeconds(300),
    ]);

    osrSweep();

    expect($outbound->fresh())->status->toBe(CallStatus::Failed)
        ->and($outbound->fresh()->end_reason)->toBe(CallEndReason::ConsumerNoAnswerOutbound);
    expect($inbound->fresh())->status->toBe(CallStatus::Missed)
        ->and($inbound->fresh()->end_reason)->toBe(CallEndReason::RingTimeout);
});

test('an outbound Answering call past the grace window is failed as outbound_connect_failed, never missed/answer_timeout', function () {
    $x = osrScenario();
    $call = LeadCall::factory()->create([
        'tenant_id' => $x['tenant']->id, 'lead_id' => $x['lead']->id, 'lead_source_id' => $x['source']->id,
        'direction' => CallDirection::Outbound, 'external_call_id' => 'wacid.STALE-ANSWERING-1',
        'status' => CallStatus::Answering, 'claimed_by' => $x['agent']->id, 'ringing_expires_at' => now()->subSeconds(300),
    ]);

    $summary = osrSweep();

    $fresh = $call->fresh();
    expect($fresh->status)->toBe(CallStatus::Failed);
    expect($fresh->end_reason)->toBe(CallEndReason::OutboundConnectFailed);
    expect($fresh->connected_at)->toBeNull();
    expect($summary['failed_answering_outbound'])->toBe(1);
    expect($summary['missed_answering'])->toBe(0);
});

test('an outbound Answering call still inside the grace window is left untouched', function () {
    $x = osrScenario();
    $call = LeadCall::factory()->create([
        'tenant_id' => $x['tenant']->id, 'lead_id' => $x['lead']->id, 'lead_source_id' => $x['source']->id,
        'direction' => CallDirection::Outbound, 'external_call_id' => 'wacid.FRESH-ANSWERING-1',
        'status' => CallStatus::Answering, 'claimed_by' => $x['agent']->id, 'ringing_expires_at' => now()->addSeconds(30),
    ]);

    osrSweep();

    expect($call->fresh()->status)->toBe(CallStatus::Answering);
});

test('an inbound Answering call and an outbound Answering call in the same sweep each get their own correct outcome', function () {
    $x = osrScenario();
    $outbound = LeadCall::factory()->create([
        'tenant_id' => $x['tenant']->id, 'lead_id' => $x['lead']->id, 'lead_source_id' => $x['source']->id,
        'direction' => CallDirection::Outbound, 'external_call_id' => 'wacid.MIX-ANSWERING-OUT-1',
        'status' => CallStatus::Answering, 'claimed_by' => $x['agent']->id, 'ringing_expires_at' => now()->subSeconds(300),
    ]);
    $inboundAgent = clientUserWithLeadPermission($x['tenant'], ['view', 'update']);
    $inbound = LeadCall::factory()->create([
        'tenant_id' => $x['tenant']->id, 'lead_id' => $x['lead']->id, 'lead_source_id' => $x['source']->id,
        'direction' => CallDirection::Inbound, 'external_call_id' => 'wacid.MIX-ANSWERING-IN-1',
        'status' => CallStatus::Answering, 'claimed_by' => $inboundAgent->id, 'ringing_expires_at' => now()->subSeconds(300),
    ]);

    osrSweep();

    expect($outbound->fresh())->status->toBe(CallStatus::Failed)
        ->and($outbound->fresh()->end_reason)->toBe(CallEndReason::OutboundConnectFailed);
    expect($inbound->fresh())->status->toBe(CallStatus::Missed)
        ->and($inbound->fresh()->end_reason)->toBe(CallEndReason::AnswerTimeout);
});

test('an Initiated call past the outbound initiate grace is failed as outbound_connect_failed, external_call_id and ended_at stay null', function () {
    $x = osrScenario();
    $call = LeadCall::factory()->outbound()->create([
        'tenant_id' => $x['tenant']->id, 'lead_id' => $x['lead']->id, 'lead_source_id' => $x['source']->id,
        'claimed_by' => $x['agent']->id,
    ]);
    $call->forceFill(['created_at' => now()->subSeconds(300)])->save();

    $summary = osrSweep();

    $fresh = $call->fresh();
    expect($fresh->status)->toBe(CallStatus::Failed);
    expect($fresh->end_reason)->toBe(CallEndReason::OutboundConnectFailed);
    expect($fresh->external_call_id)->toBeNull();
    expect($fresh->ended_at)->toBeNull();
    expect($summary['failed_initiate'])->toBe(1);
});

test('an Initiated call still inside its grace window is left untouched', function () {
    $x = osrScenario();
    $call = LeadCall::factory()->outbound()->create([
        'tenant_id' => $x['tenant']->id, 'lead_id' => $x['lead']->id, 'lead_source_id' => $x['source']->id,
        'claimed_by' => $x['agent']->id,
    ]);

    osrSweep();

    expect($call->fresh()->status)->toBe(CallStatus::Initiated);
});

test('an outbound Connected call past the active window is completed as stale_no_terminate — unchanged, direction-agnostic rule', function () {
    $x = osrScenario();
    $call = LeadCall::factory()->create([
        'tenant_id' => $x['tenant']->id, 'lead_id' => $x['lead']->id, 'lead_source_id' => $x['source']->id,
        'direction' => CallDirection::Outbound, 'external_call_id' => 'wacid.STALE-CONN-1',
        'status' => CallStatus::Connected, 'claimed_by' => $x['agent']->id, 'connected_at' => now()->subMinutes(200),
    ]);

    $summary = osrSweep();

    $fresh = $call->fresh();
    expect($fresh->status)->toBe(CallStatus::Completed);
    expect($fresh->end_reason)->toBe(CallEndReason::StaleNoTerminate);
    expect($fresh->ended_at)->toBeNull();
    expect($summary['completed_connected'])->toBe(1);
});

test('a genuinely stuck Initiated row never wedges Lead deletion forever — it is Failed (terminal), not left Initiated (active)', function () {
    $x = osrScenario();
    $call = LeadCall::factory()->outbound()->create([
        'tenant_id' => $x['tenant']->id, 'lead_id' => $x['lead']->id, 'lead_source_id' => $x['source']->id,
        'claimed_by' => $x['agent']->id,
    ]);
    $call->forceFill(['created_at' => now()->subSeconds(300)])->save();
    expect(CallStatus::Initiated->isActive())->toBeTrue();

    osrSweep();

    expect($call->fresh()->status->isActive())->toBeFalse();
});
