<?php

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Http\Controllers\LeadCallController;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Support\LeadCallSdp;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Support\WhatsAppCallFixtures as Wa;

/*
 * The WhatsApp Outbound Calling JSON routes: route shape, the fail-open
 * throttle, guest/security behaviour, and that the browser can only ever
 * supply a Lead id + its own SDP — never a tenant, source, phone number,
 * token, or wacid. The five pre-existing routes (claim/answer/accept/
 * reject/hangup) are untouched — see LeadCallEndpointsTest.php.
 */

function olceScenario(): array
{
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $agent = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $source = Wa::source($tenant, [], ['access_token' => 'OLCE-sentinel-token']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'phone' => Wa::CALLER, 'assigned_to' => $agent->id]);

    return ['tenant' => $tenant, 'agent' => $agent, 'source' => $source, 'lead' => $lead];
}

test('the three new routes exist with the approved names, paths, methods and throttle', function () {
    $call = Route::getRoutes()->getByName('tenant-lead-calls.cancel-outbound');
    expect($call->uri())->toBe('lead-management/calls/{leadCall}/cancel-outbound')
        ->and($call->methods())->toBe(['POST'])
        ->and($call->getActionName())->toBe(LeadCallController::class.'@cancelOutbound')
        ->and($call->gatherMiddleware())->toContain('throttle.fail-open:lead-call-actions');

    $answer = Route::getRoutes()->getByName('tenant-lead-calls.outbound-answer');
    expect($answer->uri())->toBe('lead-management/calls/{leadCall}/outbound-answer')
        ->and($answer->methods())->toBe(['GET', 'HEAD'])
        ->and($answer->getActionName())->toBe(LeadCallController::class.'@outboundAnswer');

    $connected = Route::getRoutes()->getByName('tenant-lead-calls.outbound-connected');
    expect($connected->uri())->toBe('lead-management/calls/{leadCall}/outbound-connected')
        ->and($connected->methods())->toBe(['POST'])
        ->and($connected->getActionName())->toBe(LeadCallController::class.'@outboundConnected')
        ->and($connected->gatherMiddleware())->toContain('throttle.fail-open:lead-call-actions');

    $initiate = Route::getRoutes()->getByName('tenant-lead-calls.initiate-outbound');
    expect($initiate->uri())->toBe('lead-management/leads/{lead}/calls/outbound')
        ->and($initiate->methods())->toBe(['POST'])
        ->and($initiate->getActionName())->toBe(LeadCallController::class.'@initiateOutbound')
        ->and($initiate->gatherMiddleware())->toContain('throttle.fail-open:lead-call-actions');
});

test('a guest is turned away from every new route', function () {
    $x = olceScenario();
    $call = LeadCall::factory()->outbound()->create(['tenant_id' => $x['tenant']->id, 'lead_id' => $x['lead']->id, 'lead_source_id' => $x['source']->id, 'claimed_by' => $x['agent']->id]);

    $this->postJson(route('tenant-lead-calls.initiate-outbound', $x['lead']->id), ['sdp' => Wa::sdp()])->assertUnauthorized();
    $this->postJson(route('tenant-lead-calls.cancel-outbound', $call->id))->assertUnauthorized();
    $this->getJson(route('tenant-lead-calls.outbound-answer', $call->id))->assertUnauthorized();
    $this->postJson(route('tenant-lead-calls.outbound-connected', $call->id))->assertUnauthorized();
    expect($call->fresh()->status)->toBe(CallStatus::Initiated);
});

test('initiating through HTTP only ever accepts a lead id and an sdp body field — never a tenant, source, phone, token or wacid', function () {
    $x = olceScenario();
    Http::fake([
        '*/call_permissions*' => Http::response(['permission' => ['status' => 'permanent'], 'actions' => [
            ['action_name' => 'start_call', 'can_perform_action' => true, 'limits' => []],
        ]]),
        '*/calls' => Http::response(['calls' => [['id' => 'wacid.HTTP-OUT-1']]]),
    ]);

    $response = $this->actingAs($x['agent'])->postJson(route('tenant-lead-calls.initiate-outbound', $x['lead']->id), [
        'sdp' => Wa::sdp(),
        // Every one of these must be silently ignored — InitiateOutboundCall
        // never reads $request at all beyond the sdp field the controller
        // extracts, so there is no code path that could honour them.
        'tenant_id' => 'attacker-tenant',
        'lead_source_id' => 'attacker-source',
        'phone_number_id' => 'attacker-phone',
        'access_token' => 'attacker-token',
        'wacid' => 'attacker-wacid',
        'biz_opaque_callback_data' => 'attacker-token',
    ])->assertOk();

    $call = LeadCall::sole();
    expect($call->tenant_id)->toBe($x['tenant']->id);
    expect($call->lead_source_id)->toBe($x['source']->id);
    expect($call->external_call_id)->toBe('wacid.HTTP-OUT-1');
    expect($response->json('data.call.id'))->toBe($call->id);
});

test('cancel-outbound through HTTP ends an Initiated call as Failed', function () {
    $x = olceScenario();
    $call = LeadCall::factory()->outbound()->create(['tenant_id' => $x['tenant']->id, 'lead_id' => $x['lead']->id, 'lead_source_id' => $x['source']->id, 'claimed_by' => $x['agent']->id, 'routed_user_ids' => [$x['agent']->id]]);

    $this->actingAs($x['agent'])->postJson(route('tenant-lead-calls.cancel-outbound', $call->id))->assertOk();

    expect($call->fresh()->status)->toBe(CallStatus::Failed);
});

test('outbound-answer through HTTP hands the claimant the cached answer once Answering', function () {
    $x = olceScenario();
    $call = LeadCall::factory()->create([
        'tenant_id' => $x['tenant']->id, 'lead_id' => $x['lead']->id, 'lead_source_id' => $x['source']->id,
        'direction' => CallDirection::Outbound, 'external_call_id' => 'wacid.HTTP-ANSWER-1',
        'status' => CallStatus::Answering, 'claimed_by' => $x['agent']->id, 'routed_user_ids' => [$x['agent']->id],
    ]);
    LeadCallSdp::storeAnswer($x['source'], 'wacid.HTTP-ANSWER-1', Wa::sdp());

    $response = $this->actingAs($x['agent'])->getJson(route('tenant-lead-calls.outbound-answer', $call->id))->assertOk();

    expect($response->json('data.session.sdp_type'))->toBe('answer');
    expect($response->json('data.session.sdp'))->toContain('SENTINEL-SDP');
});

test('outbound-answer still works once Connected too — repeat-safe for a reload/second tab', function () {
    $x = olceScenario();
    $call = LeadCall::factory()->create([
        'tenant_id' => $x['tenant']->id, 'lead_id' => $x['lead']->id, 'lead_source_id' => $x['source']->id,
        'direction' => CallDirection::Outbound, 'external_call_id' => 'wacid.HTTP-ANSWER-2',
        'status' => CallStatus::Connected, 'connected_at' => now(), 'claimed_by' => $x['agent']->id, 'routed_user_ids' => [$x['agent']->id],
    ]);
    LeadCallSdp::storeAnswer($x['source'], 'wacid.HTTP-ANSWER-2', Wa::sdp());

    $this->actingAs($x['agent'])->getJson(route('tenant-lead-calls.outbound-answer', $call->id))->assertOk();
});

test('outbound-answer refuses (retryable) before the call has at least reached Answering', function () {
    $x = olceScenario();
    $call = LeadCall::factory()->outbound()->create(['tenant_id' => $x['tenant']->id, 'lead_id' => $x['lead']->id, 'lead_source_id' => $x['source']->id, 'claimed_by' => $x['agent']->id, 'routed_user_ids' => [$x['agent']->id]]);

    $response = $this->actingAs($x['agent'])->getJson(route('tenant-lead-calls.outbound-answer', $call->id))->assertStatus(409);

    expect($response->json('error.code'))->toBe('answer_not_yet_available');
    expect($response->json('error.retryable'))->toBeTrue();
});

test('outbound-connected through HTTP moves Answering to Connected and sets connected_at once', function () {
    $x = olceScenario();
    $call = LeadCall::factory()->outbound()->create(['tenant_id' => $x['tenant']->id, 'lead_id' => $x['lead']->id, 'lead_source_id' => $x['source']->id, 'status' => CallStatus::Answering, 'claimed_by' => $x['agent']->id, 'routed_user_ids' => [$x['agent']->id]]);

    $response = $this->actingAs($x['agent'])->postJson(route('tenant-lead-calls.outbound-connected', $call->id))->assertOk();

    $row = $call->fresh();
    expect($row->status)->toBe(CallStatus::Connected);
    expect($row->connected_at)->not->toBeNull();
    expect($response->json('data.call.id'))->toBe($call->id);
});

test('outbound-connected refuses a call still Ringing — never Ringing straight to Connected', function () {
    $x = olceScenario();
    $call = LeadCall::factory()->outbound()->create(['tenant_id' => $x['tenant']->id, 'lead_id' => $x['lead']->id, 'lead_source_id' => $x['source']->id, 'status' => CallStatus::Ringing, 'external_call_id' => 'wacid.STILL-RINGING', 'ringing_expires_at' => now()->addSeconds(60), 'claimed_by' => $x['agent']->id, 'routed_user_ids' => [$x['agent']->id]]);

    $response = $this->actingAs($x['agent'])->postJson(route('tenant-lead-calls.outbound-connected', $call->id))->assertStatus(409);

    expect($response->json('error.code'))->toBe('call_not_confirmable');
    expect($call->fresh()->status)->toBe(CallStatus::Ringing);
});

test('outbound-connected is idempotent — a repeated confirmation of an already-Connected call succeeds without a second side effect', function () {
    $x = olceScenario();
    $call = LeadCall::factory()->outbound()->create(['tenant_id' => $x['tenant']->id, 'lead_id' => $x['lead']->id, 'lead_source_id' => $x['source']->id, 'status' => CallStatus::Answering, 'claimed_by' => $x['agent']->id, 'routed_user_ids' => [$x['agent']->id]]);

    $this->actingAs($x['agent'])->postJson(route('tenant-lead-calls.outbound-connected', $call->id))->assertOk();
    $connectedAt = $call->fresh()->connected_at;

    $this->actingAs($x['agent'])->postJson(route('tenant-lead-calls.outbound-connected', $call->id))->assertOk();

    expect($call->fresh()->connected_at->eq($connectedAt))->toBeTrue();
});

test('a user not routed to the call is refused on every new route', function () {
    $x = olceScenario();
    $stranger = clientUserWithLeadPermission($x['tenant'], ['view', 'update']);
    $call = LeadCall::factory()->outbound()->create(['tenant_id' => $x['tenant']->id, 'lead_id' => $x['lead']->id, 'lead_source_id' => $x['source']->id, 'status' => CallStatus::Answering, 'claimed_by' => $x['agent']->id, 'routed_user_ids' => [$x['agent']->id]]);

    $this->actingAs($stranger)->postJson(route('tenant-lead-calls.cancel-outbound', $call->id))->assertForbidden();
    $this->actingAs($stranger)->getJson(route('tenant-lead-calls.outbound-answer', $call->id))->assertForbidden();
    $this->actingAs($stranger)->postJson(route('tenant-lead-calls.outbound-connected', $call->id))->assertForbidden();
});
