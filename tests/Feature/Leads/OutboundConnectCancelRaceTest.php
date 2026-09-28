<?php

use App\Actions\Tenant\InitiateOutboundCall;
use App\Enums\CallEndReason;
use App\Enums\CallStatus;
use App\Models\Lead;
use App\Models\LeadCall;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\WhatsAppCallFixtures as Wa;

/*
 * WhatsApp Outbound Calling — the pre-real-Meta race fix: CancelOutboundLeadCall
 * winning its conditional UPDATE while InitiateOutboundCall's connect() is still
 * in flight at Meta must never lose the wacid Meta hands back, never revert the
 * local cancellation, and never send more than one cleanup terminate.
 *
 * The race is simulated the only way it can be from a synchronous test: the
 * Http::fake closure for connect() runs the exact conditional UPDATE
 * CancelOutboundLeadCall itself performs, in place of the real concurrent
 * request, before returning Meta's (already-accepted) response.
 */

function ocrScenario(): array
{
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $agent = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $source = Wa::source($tenant, [], ['access_token' => 'OCR-sentinel-token']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'phone' => Wa::CALLER, 'assigned_to' => $agent->id]);

    return ['tenant' => $tenant, 'agent' => $agent, 'source' => $source, 'lead' => $lead];
}

function ocrPermissionFake(): array
{
    return ['*/call_permissions*' => Http::response(['permission' => ['status' => 'permanent'], 'actions' => [
        ['action_name' => 'start_call', 'can_perform_action' => true, 'limits' => []],
    ]])];
}

/**
 * Fakes connect() to win the same conditional UPDATE CancelOutboundLeadCall
 * performs (mid "request") before Meta's success response is returned, and
 * counts every terminate() call that follows.
 */
function ocrRaceFake(array $x, string $wacid, string $endReason, int &$terminateCalls): array
{
    return array_merge(ocrPermissionFake(), [
        '*/calls' => function (Request $request) use ($x, $wacid, $endReason, &$terminateCalls) {
            $body = $request->data();

            if (($body['action'] ?? null) === 'connect') {
                $call = LeadCall::where('tenant_id', $x['tenant']->id)->where('lead_id', $x['lead']->id)->sole();

                LeadCall::where('id', $call->id)->update([
                    'status' => CallStatus::Failed->value,
                    'end_reason' => $endReason,
                    'ended_at' => now(),
                ]);

                return Http::response(['calls' => [['id' => $wacid]]]);
            }

            $terminateCalls++;

            return Http::response(['success' => true]);
        },
    ]);
}

test('a cancel that wins mid-connect leaves the local cancellation untouched, persists the wacid, and sends exactly one cleanup terminate', function () {
    $x = ocrScenario();
    $terminateCalls = 0;
    Http::fake(ocrRaceFake($x, 'wacid.RACE-1', CallEndReason::AgentCancelledOutbound->value, $terminateCalls));

    $result = app(InitiateOutboundCall::class)->handle($x['agent'], $x['lead'], Wa::sdp());

    // Treated as success: from the system's perspective the call was placed
    // then cancelled, not rejected by Meta.
    expect($result->ok)->toBeTrue();

    $call = LeadCall::sole();
    expect($call->status)->toBe(CallStatus::Failed);
    expect($call->end_reason)->toBe(CallEndReason::AgentCancelledOutbound);
    expect($call->external_call_id)->toBe('wacid.RACE-1');

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/calls') && $r->data()['action'] === 'terminate' && $r->data()['call_id'] === 'wacid.RACE-1');
    expect($terminateCalls)->toBe(1);
});

test('the local cancellation is never reverted back to Ringing by the reconciled wacid', function () {
    $x = ocrScenario();
    $terminateCalls = 0;
    Http::fake(ocrRaceFake($x, 'wacid.RACE-2', CallEndReason::AgentCancelledOutbound->value, $terminateCalls));

    app(InitiateOutboundCall::class)->handle($x['agent'], $x['lead'], Wa::sdp());

    $call = LeadCall::sole();
    expect($call->status)->not->toBe(CallStatus::Ringing);
    expect($call->status)->toBe(CallStatus::Failed);
    expect($call->ringing_expires_at)->toBeNull();
});

test('a repeated reconciliation for the same wacid never sends a second cleanup terminate', function () {
    $x = ocrScenario();
    $terminateCalls = 0;
    Http::fake(ocrRaceFake($x, 'wacid.RACE-3', CallEndReason::AgentCancelledOutbound->value, $terminateCalls));

    $action = app(InitiateOutboundCall::class);
    $action->handle($x['agent'], $x['lead'], Wa::sdp());

    expect($terminateCalls)->toBe(1);
    $reconciled = LeadCall::sole();
    expect($reconciled->external_call_id)->toBe('wacid.RACE-3');

    // Simulate a duplicate delivery of the same Meta response reaching the
    // same reconciliation path a second time.
    $method = new ReflectionMethod($action, 'persistOrphanedWacidAndCleanUp');
    $method->setAccessible(true);
    $method->invoke($action, $reconciled->fresh(), $x['source'], 'wacid.RACE-3');

    expect($terminateCalls)->toBe(1);
    expect(LeadCall::sole()->external_call_id)->toBe('wacid.RACE-3');
});

test('a lost race against any other unexpected state is never silently overwritten and never triggers a terminate', function () {
    $x = ocrScenario();
    $terminateCalls = 0;
    // Not the approved-cancellation signature: same terminal status, a
    // different end_reason (e.g. some other mechanism moved the row on).
    Http::fake(ocrRaceFake($x, 'wacid.UNEXPECTED-1', CallEndReason::OutboundConnectFailed->value, $terminateCalls));

    $result = app(InitiateOutboundCall::class)->handle($x['agent'], $x['lead'], Wa::sdp());

    expect($result->ok)->toBeFalse();
    expect($result->errorCode)->toBe('meta_rejected');

    $call = LeadCall::sole();
    expect($call->external_call_id)->toBeNull();
    expect($call->end_reason)->toBe(CallEndReason::OutboundConnectFailed);
    expect($terminateCalls)->toBe(0);
});

test('when the row vanishes entirely before reconciliation, the wacid is not persisted and no terminate is sent', function () {
    $x = ocrScenario();
    $terminateCalls = 0;

    Http::fake(array_merge(ocrPermissionFake(), [
        '*/calls' => function (Request $request) use ($x, &$terminateCalls) {
            $body = $request->data();

            if (($body['action'] ?? null) === 'connect') {
                LeadCall::where('tenant_id', $x['tenant']->id)->where('lead_id', $x['lead']->id)->delete();

                return Http::response(['calls' => [['id' => 'wacid.GONE-1']]]);
            }

            $terminateCalls++;

            return Http::response(['success' => true]);
        },
    ]));

    $result = app(InitiateOutboundCall::class)->handle($x['agent'], $x['lead'], Wa::sdp());

    expect($result->ok)->toBeFalse();
    expect($result->errorCode)->toBe('meta_rejected');
    expect(LeadCall::count())->toBe(0);
    expect($terminateCalls)->toBe(0);
});

test('the normal connect path — no race at all — is unaffected: Ringing, wacid recorded, no terminate sent', function () {
    $x = ocrScenario();
    Http::fake(array_merge(ocrPermissionFake(), [
        '*/calls' => Http::response(['calls' => [['id' => 'wacid.NORMAL-1']]]),
    ]));

    $result = app(InitiateOutboundCall::class)->handle($x['agent'], $x['lead'], Wa::sdp());

    expect($result->ok)->toBeTrue();
    $call = LeadCall::sole();
    expect($call->status)->toBe(CallStatus::Ringing);
    expect($call->external_call_id)->toBe('wacid.NORMAL-1');

    Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/calls') && ($r->data()['action'] ?? null) === 'terminate');
});
