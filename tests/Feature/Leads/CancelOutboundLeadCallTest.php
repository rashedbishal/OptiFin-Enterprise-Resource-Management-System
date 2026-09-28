<?php

use App\Actions\Tenant\CancelOutboundLeadCall;
use App\Enums\CallEndReason;
use App\Enums\CallStatus;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Models\TenantUser;
use App\Support\LeadCallActionResult;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Http;
use Tests\Support\LeadCallScenario as Lc;
use Tests\Support\WhatsAppCallFixtures as Wa;

/*
 * WhatsApp Outbound Calling — CancelOutboundLeadCall. Mirrors RejectLeadCall/
 * HangupLeadCall's exact local-first, Meta-once, never-rolled-back safety
 * shape, scoped to the initiating agent and to Initiated/Ringing only.
 */

function coScenario(array $callOverrides = []): array
{
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $agent = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $other = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $source = Wa::source($tenant, [], ['access_token' => 'CO-sentinel-token']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    $call = LeadCall::factory()->outbound()->create(array_merge([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'lead_source_id' => $source->id,
        'routed_user_ids' => [$agent->id],
        'claimed_by' => $agent->id,
    ], $callOverrides));

    return ['tenant' => $tenant, 'agent' => $agent, 'other' => $other, 'source' => $source, 'lead' => $lead, 'call' => $call];
}

function coCancel(array $x, ?TenantUser $user = null): LeadCallActionResult
{
    return app(CancelOutboundLeadCall::class)->handle($user ?? $x['agent'], $x['call']->id);
}

test('cancelling a still-Initiated call (no wacid yet) never calls Meta', function () {
    $log = Lc::fakeMeta();
    $x = coScenario();
    expect($x['call']->status)->toBe(CallStatus::Initiated);
    expect($x['call']->external_call_id)->toBeNull();

    $result = coCancel($x);

    expect($result->ok)->toBeTrue();
    $row = $x['call']->fresh();
    expect($row->status)->toBe(CallStatus::Failed);
    expect($row->end_reason)->toBe(CallEndReason::AgentCancelledOutbound);
    expect($row->ended_at)->not->toBeNull();
    expect($log)->toHaveCount(0);
});

test('cancelling a Ringing call (wacid known) sends Meta terminate exactly once', function () {
    $log = Lc::fakeMeta();
    $x = coScenario(['status' => CallStatus::Ringing, 'external_call_id' => 'wacid.OUT-CANCEL-1', 'ringing_expires_at' => now()->addSeconds(60)]);

    $result = coCancel($x);

    expect($result->ok)->toBeTrue()->and($result->metaConfirmed)->toBeTrue();
    expect($x['call']->fresh()->status)->toBe(CallStatus::Failed);
    expect($log)->toHaveCount(1);
    expect($log[0]['body']['action'])->toBe('terminate');
    expect($log[0]['body']['call_id'])->toBe('wacid.OUT-CANCEL-1');
});

test('only the initiating agent may cancel — anyone else is refused by the policy gate (not routed to this call)', function () {
    Lc::fakeMeta();
    $x = coScenario();

    expect(fn () => coCancel($x, $x['other']))->toThrow(AuthorizationException::class);
    expect($x['call']->fresh()->status)->toBe(CallStatus::Initiated);
});

test('a user named in routed_user_ids but not the claimant is refused by the action itself, not just the policy gate', function () {
    Lc::fakeMeta();
    $x = coScenario();
    // Both users routed so the POLICY gate passes for $other too, isolating
    // the action's own claimed_by check.
    $x['call']->forceFill(['routed_user_ids' => [$x['agent']->id, $x['other']->id]])->save();

    $result = coCancel($x, $x['other']);

    expect($result->ok)->toBeFalse();
    expect($result->errorCode)->toBe('not_claimant');
    expect($x['call']->fresh()->status)->toBe(CallStatus::Initiated);
});

test('a Connected call cannot be cancelled — must use Hang Up instead', function () {
    Lc::fakeMeta();
    $x = coScenario(['status' => CallStatus::Connected, 'external_call_id' => 'wacid.OUT-CONNECTED-1', 'connected_at' => now()]);

    $result = coCancel($x);

    expect($result->ok)->toBeFalse();
    expect($result->errorCode)->toBe('call_not_cancellable');
    expect($x['call']->fresh()->status)->toBe(CallStatus::Connected);
});

test('a repeated cancel by the same claimant is idempotent', function () {
    Lc::fakeMeta();
    $x = coScenario();

    coCancel($x);
    $second = coCancel($x);

    expect($second->ok)->toBeTrue();
    expect($x['call']->fresh()->status)->toBe(CallStatus::Failed);
});

test('a Meta terminate failure does not roll back the local cancellation', function () {
    Lc::fakeMeta(fn () => Http::response(['error' => ['message' => 'down']], 500));
    $x = coScenario(['status' => CallStatus::Ringing, 'external_call_id' => 'wacid.OUT-FAIL-1', 'ringing_expires_at' => now()->addSeconds(60)]);

    $result = coCancel($x);

    expect($result->ok)->toBeTrue();
    expect($result->metaConfirmed)->toBeFalse();
    expect($x['call']->fresh()->status)->toBe(CallStatus::Failed);
});

test('a call from a different tenant is not found', function () {
    Lc::fakeMeta();
    $x = coScenario();
    ['tenant' => $otherTenant] = tenantWithLeadManagement();
    $otherTenantAgent = clientUserWithLeadPermission($otherTenant, ['view', 'update']);

    $result = coCancel($x, $otherTenantAgent);

    expect($result->ok)->toBeFalse();
    expect($result->status)->toBe(404);
});
