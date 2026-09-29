<?php

use App\Actions\Tenant\ClaimLeadCall;
use App\Actions\Tenant\HangupLeadCall;
use App\Enums\CallDirection;
use App\Enums\CallRoutingMode;
use App\Models\LeadCall;
use Tests\Support\LeadCallScenario as Lc;

/*
 * WhatsApp Call Backup / Overflow Receiver, Phase 6 — the acted_as field
 * added to the existing "WA CALL ACTION" log line (InteractsWithLeadCalls::
 * logCallAction()/actedAs()). No new audit table, no changes to routing,
 * authorization, claim/CAS behavior, realtime, outbound permissions or lead
 * assignment — this file proves exactly that boundary.
 */

test('1. an assignee acting on their own Assignee-routed call logs acted_as=assignee', function () {
    $logs = Lc::captureLogs();
    $x = Lc::make();
    $x->call->update(['routing_mode' => CallRoutingMode::Assignee, 'assignee_id_at_arrival' => $x->agent->id, 'routed_user_ids' => [$x->agent->id]]);

    app(ClaimLeadCall::class)->handle($x->agent, $x->call->id);

    expect(Lc::logText($logs))->toContain('"acted_as":"assignee"');
});

test('2. a backup receiver acting on a BackupFallback call logs acted_as=backup', function () {
    $logs = Lc::captureLogs();
    $x = Lc::make();
    // $x->admin is the original assignee (not routed); $x->agent is the
    // backup receiver actually claiming.
    $x->call->update(['routing_mode' => CallRoutingMode::BackupFallback, 'assignee_id_at_arrival' => $x->admin->id, 'routed_user_ids' => [$x->agent->id]]);

    app(ClaimLeadCall::class)->handle($x->agent, $x->call->id);

    expect(Lc::logText($logs))->toContain('"acted_as":"backup"');
});

test('3 & 9. an admin acting on an AdminFallback (including genuinely unassigned) call logs acted_as=admin_fallback', function () {
    $logs = Lc::captureLogs();
    // Lc::make()'s own default shape: routing_mode=AdminFallback,
    // assignee_id_at_arrival=null, routed_user_ids=[agent, other] — the
    // genuinely-unassigned case, unmodified.
    $x = Lc::make();

    app(ClaimLeadCall::class)->handle($x->agent, $x->call->id);

    expect($x->call->routing_mode)->toBe(CallRoutingMode::AdminFallback);
    expect($x->call->assignee_id_at_arrival)->toBeNull();
    expect(Lc::logText($logs))->toContain('"acted_as":"admin_fallback"');
});

test('4. the agent who placed an outbound call logs acted_as=agent_initiated', function () {
    $logs = Lc::captureLogs();
    $x = Lc::make();
    $outbound = LeadCall::factory()->connected()->create([
        'tenant_id' => $x->tenant->id,
        'lead_id' => $x->lead->id,
        'lead_source_id' => $x->source->id,
        'direction' => CallDirection::Outbound,
        'external_call_id' => 'wacid.OUTBOUND-ACTEDAS-1',
        'routing_mode' => CallRoutingMode::AgentInitiated,
        'assignee_id_at_arrival' => $x->admin->id,
        'routed_user_ids' => [$x->agent->id],
        'claimed_by' => $x->agent->id,
    ]);

    app(HangupLeadCall::class)->handle($x->agent, $outbound->id);

    expect(Lc::logText($logs))->toContain('"acted_as":"agent_initiated"');
});

test('5. a client-supplied acted_as value in the request body is completely ignored — the claim endpoint never reads it', function () {
    $logs = Lc::captureLogs();
    $x = Lc::make();
    $x->call->update(['routing_mode' => CallRoutingMode::BackupFallback, 'assignee_id_at_arrival' => $x->admin->id, 'routed_user_ids' => [$x->agent->id]]);

    $this->actingAs($x->agent)->postJson(route('tenant-lead-calls.claim', $x->call->id), [
        'acted_as' => 'assignee',
        'action' => 'claim',
        'outcome' => 'claimed',
    ])->assertOk();

    // Server-derived truth (backup) wins, never the smuggled value.
    expect(Lc::logText($logs))->toContain('"acted_as":"backup"')->not->toContain('"acted_as":"assignee"');
});

test('6. the existing claim race/CAS behavior is unchanged, and both the winner and loser log lines carry the correct acted_as', function () {
    $logs = Lc::captureLogs();
    $x = Lc::make();
    $x->call->update(['routing_mode' => CallRoutingMode::BackupFallback, 'assignee_id_at_arrival' => $x->admin->id, 'routed_user_ids' => [$x->agent->id, $x->other->id]]);

    Lc::interleaveAfterQuery('from "lead_sources"', fn () => app(ClaimLeadCall::class)->handle($x->other, $x->call->id));
    $result = app(ClaimLeadCall::class)->handle($x->agent, $x->call->id);

    // Unchanged CAS outcome: exactly one winner, the loser gets a conflict.
    expect($result->ok)->toBeFalse()->and($result->errorCode)->toBe('already_claimed');
    expect($x->fresh()->claimed_by)->toBe($x->other->id);

    $text = Lc::logText($logs);
    expect($text)->toContain('"outcome":"claimed"')->toContain('"outcome":"already_claimed"');
    // Both log lines are for the same BackupFallback call, so both carry the
    // same routing-derived context regardless of who actually won.
    expect(substr_count($text, '"acted_as":"backup"'))->toBe(2);
});

test('7. tenant isolation: one tenant\'s logged acted_as is never influenced by another tenant\'s identically-shaped call', function () {
    $logs = Lc::captureLogs();
    $mine = Lc::make();
    $mine->call->update(['routing_mode' => CallRoutingMode::Assignee, 'assignee_id_at_arrival' => $mine->agent->id, 'routed_user_ids' => [$mine->agent->id]]);

    $theirs = Lc::make();
    $theirs->call->update(['routing_mode' => CallRoutingMode::BackupFallback, 'assignee_id_at_arrival' => $theirs->admin->id, 'routed_user_ids' => [$theirs->agent->id]]);

    app(ClaimLeadCall::class)->handle($mine->agent, $mine->call->id);
    app(ClaimLeadCall::class)->handle($theirs->agent, $theirs->call->id);

    $text = Lc::logText($logs);
    expect(substr_count($text, '"acted_as":"assignee"'))->toBe(1);
    expect(substr_count($text, '"acted_as":"backup"'))->toBe(1);
});

test('8. every pre-existing log field is still present alongside the new acted_as field', function () {
    $logs = Lc::captureLogs();
    $x = Lc::make();

    app(ClaimLeadCall::class)->handle($x->agent, $x->call->id);

    $text = Lc::logText($logs);
    foreach (['tenant_id', 'lead_call_id', 'lead_source_id', 'action', 'outcome', 'tenant_user_id', 'acted_as'] as $field) {
        expect($text)->toContain('"'.$field.'"');
    }
});

test('10. logging a backup action never reassigns the lead', function () {
    $x = Lc::make();
    $x->call->update(['routing_mode' => CallRoutingMode::BackupFallback, 'assignee_id_at_arrival' => $x->admin->id, 'routed_user_ids' => [$x->agent->id]]);
    $before = $x->lead->fresh()->assigned_to;

    app(ClaimLeadCall::class)->handle($x->agent, $x->call->id);

    expect($x->lead->fresh()->assigned_to)->toBe($before);
});
