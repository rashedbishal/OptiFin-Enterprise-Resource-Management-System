<?php

use App\Actions\Tenant\InitiateOutboundCall;
use App\Enums\CallDirection;
use App\Enums\CallEndReason;
use App\Enums\CallRoutingMode;
use App\Enums\CallStatus;
use App\Enums\MessageChannel;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Models\LeadSource;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Http;
use Tests\Support\WhatsAppCallFixtures as Wa;

/*
 * WhatsApp Outbound Calling — InitiateOutboundCall. Meta is always faked;
 * nothing here hits the network.
 */

function iocScenario(array $sourceConfig = []): array
{
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $agent = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $source = Wa::source($tenant, [], array_merge(['access_token' => 'IOC-sentinel-token'], $sourceConfig));
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'phone' => Wa::CALLER, 'assigned_to' => $agent->id]);

    return ['tenant' => $tenant, 'agent' => $agent, 'source' => $source, 'lead' => $lead];
}

function iocFakePermitted(): void
{
    Http::fake([
        '*/call_permissions*' => Http::response(['permission' => ['status' => 'permanent'], 'actions' => [
            ['action_name' => 'start_call', 'can_perform_action' => true, 'limits' => []],
        ]]),
        '*/calls' => Http::response(['calls' => [['id' => 'wacid.OUTBOUND-1']]]),
    ]);
}

test('a permitted, authorized agent successfully initiates a call: Ringing, with the wacid recorded', function () {
    $x = iocScenario();
    iocFakePermitted();

    $result = app(InitiateOutboundCall::class)->handle($x['agent'], $x['lead'], Wa::sdp());

    expect($result->ok)->toBeTrue();
    $call = LeadCall::sole();
    expect($call->direction)->toBe(CallDirection::Outbound);
    expect($call->status)->toBe(CallStatus::Ringing);
    expect($call->external_call_id)->toBe('wacid.OUTBOUND-1');
    expect($call->routing_mode)->toBe(CallRoutingMode::AgentInitiated);
    expect($call->routed_user_ids)->toBe([$x['agent']->id]);
    expect($call->claimed_by)->toBe($x['agent']->id);
    expect($call->biz_opaque_callback_data)->not->toBeNull();
    expect($call->ringing_expires_at)->not->toBeNull();
});

test('sends the offer, the resolved recipient and the correlation token to Meta connect', function () {
    $x = iocScenario();
    iocFakePermitted();

    app(InitiateOutboundCall::class)->handle($x['agent'], $x['lead'], Wa::sdp());

    Http::assertSent(function ($request) {
        if (! str_ends_with($request->url(), '/calls')) {
            return false;
        }

        $call = LeadCall::sole();

        return $request['to'] === Wa::CALLER
            && $request['action'] === 'connect'
            && $request['session']['sdp_type'] === 'offer'
            && $request['biz_opaque_callback_data'] === $call->biz_opaque_callback_data;
    });
});

test('a user without lead-management update permission is refused', function () {
    $x = iocScenario();
    $viewOnly = clientUserWithLeadPermission($x['tenant'], ['view']);
    iocFakePermitted();

    expect(fn () => app(InitiateOutboundCall::class)->handle($viewOnly, $x['lead'], Wa::sdp()))
        ->toThrow(AuthorizationException::class);
    expect(LeadCall::count())->toBe(0);
});

test('a lead with no WhatsApp source is refused, no Meta call attempted', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $agent = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $nonWhatsappSource = LeadSource::factory()->create(['tenant_id' => $tenant->id]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $nonWhatsappSource->id, 'phone' => Wa::CALLER, 'assigned_to' => $agent->id]);
    iocFakePermitted();

    $result = app(InitiateOutboundCall::class)->handle($agent, $lead, Wa::sdp());

    expect($result->ok)->toBeFalse();
    expect($result->errorCode)->toBe('no_whatsapp_source');
    Http::assertNothingSent();
});

test('resolves the WhatsApp source via an existing conversation even when the lead itself came from a different channel', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $agent = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $otherSource = LeadSource::factory()->create(['tenant_id' => $tenant->id]);
    $waSource = Wa::source($tenant, [], ['access_token' => 'IOC-sentinel-token']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $otherSource->id, 'phone' => Wa::CALLER, 'assigned_to' => $agent->id]);
    Conversation::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'lead_source_id' => $waSource->id, 'channel' => MessageChannel::Whatsapp]);
    iocFakePermitted();

    $result = app(InitiateOutboundCall::class)->handle($agent, $lead, Wa::sdp());

    expect($result->ok)->toBeTrue();
    expect(LeadCall::sole()->lead_source_id)->toBe($waSource->id);
});

test('a disabled/inactive source is refused, no Meta call attempted', function () {
    $x = iocScenario();
    $x['source']->update(['calling_enabled' => false]);
    iocFakePermitted();

    $result = app(InitiateOutboundCall::class)->handle($x['agent'], $x['lead'], Wa::sdp());

    expect($result->ok)->toBeFalse();
    expect($result->errorCode)->toBe('calling_unavailable');
    Http::assertNothingSent();
});

test('an invalid SDP offer is refused before any Meta call', function () {
    $x = iocScenario();
    iocFakePermitted();

    $result = app(InitiateOutboundCall::class)->handle($x['agent'], $x['lead'], 'not an sdp');

    expect($result->ok)->toBeFalse();
    expect($result->errorCode)->toBe('invalid_sdp');
    Http::assertNothingSent();
});

test('missing call permission (live check) refuses without ever calling Meta connect', function () {
    $x = iocScenario();
    Http::fake(['*/call_permissions*' => Http::response(['permission' => ['status' => 'no_permission'], 'actions' => [
        ['action_name' => 'start_call', 'can_perform_action' => false, 'limits' => []],
    ]])]);

    $result = app(InitiateOutboundCall::class)->handle($x['agent'], $x['lead'], Wa::sdp());

    expect($result->ok)->toBeFalse();
    expect($result->errorCode)->toBe('permission_required');
    Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/calls'));
    expect(LeadCall::count())->toBe(0);
});

test('a second initiation attempt while one is already active is refused', function () {
    $x = iocScenario();
    LeadCall::factory()->outbound()->create(['tenant_id' => $x['tenant']->id, 'lead_id' => $x['lead']->id, 'lead_source_id' => $x['source']->id, 'claimed_by' => $x['agent']->id]);
    iocFakePermitted();

    $result = app(InitiateOutboundCall::class)->handle($x['agent'], $x['lead'], Wa::sdp());

    expect($result->ok)->toBeFalse();
    expect($result->errorCode)->toBe('call_already_in_progress');
    expect(LeadCall::count())->toBe(1);
});

test('a Meta connect failure records the attempt as Failed and refuses cleanly', function () {
    $x = iocScenario();
    Http::fake([
        '*/call_permissions*' => Http::response(['permission' => ['status' => 'permanent'], 'actions' => [
            ['action_name' => 'start_call', 'can_perform_action' => true, 'limits' => []],
        ]]),
        '*/calls' => Http::response(['error' => ['message' => 'nope', 'code' => 138006]], 401),
    ]);

    $result = app(InitiateOutboundCall::class)->handle($x['agent'], $x['lead'], Wa::sdp());

    expect($result->ok)->toBeFalse();
    expect($result->errorCode)->toBe('meta_rejected');
    $call = LeadCall::sole();
    expect($call->status)->toBe(CallStatus::Failed);
    expect($call->end_reason)->toBe(CallEndReason::OutboundConnectFailed);
    expect($call->external_call_id)->toBeNull();
});

test('a Meta success response with no wacid is refused and never fakes a call id', function () {
    $x = iocScenario();
    Http::fake([
        '*/call_permissions*' => Http::response(['permission' => ['status' => 'permanent'], 'actions' => [
            ['action_name' => 'start_call', 'can_perform_action' => true, 'limits' => []],
        ]]),
        '*/calls' => Http::response(['messaging_product' => 'whatsapp']),
    ]);

    $result = app(InitiateOutboundCall::class)->handle($x['agent'], $x['lead'], Wa::sdp());

    expect($result->ok)->toBeFalse();
    expect($result->errorCode)->toBe('meta_rejected');
});
