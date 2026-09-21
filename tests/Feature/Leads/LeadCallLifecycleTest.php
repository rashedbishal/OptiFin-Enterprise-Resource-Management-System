<?php

use App\Actions\Tenant\AnswerLeadCall;
use App\Actions\Tenant\ClaimLeadCall;
use App\Actions\Tenant\RecordInboundCall;
use App\Actions\Tenant\RejectLeadCall;
use App\Enums\CallEndReason;
use App\Enums\CallStatus;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Services\LeadDeletionEligibilityService;
use App\Services\LeadMergeService;
use App\Support\WhatsAppCallEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\LeadCallScenario as Lc;
use Tests\Support\WhatsAppCallFixtures as Wa;

/*
 * Step 4 against the rest of the calling stack: Step 2's terminate handling from
 * every state Step 4 can leave a call in, lead merge, and lead deletion.
 */

/**
 * The real Step 2 terminate webhook handling.
 *
 * @param  array<string, mixed>  $call
 * @param  array<string, mixed>  $valueExtra
 */
function lclTerminate(Lc $x, array $call = [], array $valueExtra = []): string
{
    $item = Wa::terminate(array_merge(['id' => Lc::CALL_ID], $call));
    $event = WhatsAppCallEvent::fromWebhook(Wa::change([$item], $valueExtra), $item);

    return app(RecordInboundCall::class)->handle($event, $x->source, fn () => throw new RuntimeException('a terminate must never create a lead'), true);
}

function lclConnect(Lc $x): void
{
    Lc::fakeMeta();
    app(AnswerLeadCall::class)->answer($x->agent, $x->call->id, 'answer', Lc::answerSdp());
    app(AnswerLeadCall::class)->accept($x->agent, $x->call->id);
    $x->reload();
}

// ── Step 2's terminate from Step 4's states ─────────────────────────────────

test('a terminate for an answering call the caller abandoned is Missed', function () {
    $x = Lc::make()->claimed();

    expect(lclTerminate($x))->toBe(RecordInboundCall::OUTCOME_TERMINATE_APPLIED);

    $row = $x->fresh();
    expect($row->status)->toBe(CallStatus::Missed)->and($row->end_reason)->toBe(CallEndReason::EndedBeforeConnect)->and($row->claimed_by)->toBe($x->agent->id);
});

test('a terminate with errors for a pre-accepted call is Failed', function () {
    $x = Lc::make()->preAccepted();

    lclTerminate($x, valueExtra: ['errors' => [['code' => 138019, 'message' => 'Call setup failed']]]);

    expect($x->fresh()->status)->toBe(CallStatus::Failed)->and($x->fresh()->end_reason)->toBe(CallEndReason::MetaError);
});

test('a COMPLETED terminate for an answering call that was picked up is Completed with its connect time', function () {
    $x = Lc::make()->preAccepted();
    $start = now()->subMinute()->getTimestamp();

    lclTerminate($x, ['status' => 'COMPLETED', 'start_time' => (string) $start, 'end_time' => (string) now()->getTimestamp(), 'duration' => 60]);

    $row = $x->fresh();
    expect($row->status)->toBe(CallStatus::Completed)
        ->and($row->end_reason)->toBe(CallEndReason::CompletedUnconfirmedLocally)
        ->and($row->connected_at->getTimestamp())->toBe($start)
        ->and($row->duration_seconds)->toBe(60);
});

test('the caller hanging up on a connected call completes it and keeps the claim and connect time', function () {
    $x = Lc::make()->claimed();
    lclConnect($x);
    $connectedAt = $x->fresh()->connected_at;

    lclTerminate($x, ['status' => 'COMPLETED', 'start_time' => (string) now()->getTimestamp(), 'end_time' => (string) now()->addMinute()->getTimestamp(), 'duration' => 60]);

    $row = $x->fresh();
    expect($row->status)->toBe(CallStatus::Completed)
        ->and($row->end_reason)->toBe(CallEndReason::RemoteTerminated)
        ->and($row->claimed_by)->toBe($x->agent->id)
        ->and($row->connected_at->equalTo($connectedAt))->toBeTrue()
        ->and($row->duration_seconds)->toBe(60);
});

test('a FAILED terminate for a connected call is Failed', function () {
    $x = Lc::make()->claimed();
    lclConnect($x);

    lclTerminate($x, ['status' => 'FAILED']);

    expect($x->fresh()->status)->toBe(CallStatus::Failed)->and($x->fresh()->end_reason)->toBe(CallEndReason::MetaFailedAfterConnect);
});

test('a terminate after a Step 4 reject or failure only fills in, and never changes the status', function (string $how) {
    $x = Lc::make()->claimed();
    Lc::fakeMeta($how === 'failed' ? Http::response(['error' => ['code' => 100]], 400) : null);

    if ($how === 'rejected') {
        app(RejectLeadCall::class)->handle($x->agent, $x->call->id);
    } else {
        app(AnswerLeadCall::class)->answer($x->agent, $x->call->id, 'answer', Lc::answerSdp());
    }

    $before = $x->fresh();
    lclTerminate($x, ['status' => 'FAILED', 'duration' => 3]);

    $after = $x->fresh();
    expect($after->status)->toBe($before->status)
        ->and($after->end_reason)->toBe($before->end_reason)
        ->and($after->meta_terminated_at)->not->toBeNull()
        ->and($after->ended_at->equalTo($before->ended_at))->toBeTrue();
})->with(['rejected', 'failed']);

test('a redelivered terminate after Step 4 has moved the call is a no-op', function () {
    $x = Lc::make()->claimed();
    lclTerminate($x);
    $terminatedAt = $x->fresh()->meta_terminated_at;

    expect(lclTerminate($x))->toBe(RecordInboundCall::OUTCOME_TERMINATE_NOOP);
    expect($x->fresh()->meta_terminated_at->equalTo($terminatedAt))->toBeTrue();
});

test('a duplicate connect webhook for a claimed call does not disturb the claim or re-arm the offer', function () {
    $x = Lc::make();
    app(ClaimLeadCall::class)->handle($x->agent, $x->call->id);

    $item = Wa::connect(['id' => Lc::CALL_ID]);
    $event = WhatsAppCallEvent::fromWebhook(Wa::change([$item]), $item);
    $outcome = app(RecordInboundCall::class)->handle($event, $x->source, fn () => throw new RuntimeException('must not create'), true);

    expect($outcome)->toBe(RecordInboundCall::OUTCOME_DUPLICATE);
    expect($x->fresh()->status)->toBe(CallStatus::Answering)->and($x->fresh()->claimed_by)->toBe($x->agent->id);
});

// ── Lead merge ──────────────────────────────────────────────────────────────

test('a call reparented by a lead merge is still claimable, answerable and acceptable on the survivor', function () {
    $x = Lc::make();
    $survivor = Lead::factory()->create(['tenant_id' => $x->tenant->id, 'email' => 'dup@example.com']);
    $x->lead->update(['email' => 'dup@example.com']);

    app(LeadMergeService::class)->merge($survivor, $x->lead, $x->admin, []);

    expect($x->fresh()->lead_id)->toBe($survivor->id);

    $claim = app(ClaimLeadCall::class)->handle($x->agent, $x->call->id);
    expect($claim->ok)->toBeTrue()->and($claim->session['sdp'])->toBe(Wa::sdp());

    Lc::fakeMeta();
    expect(app(AnswerLeadCall::class)->answer($x->agent, $x->call->id, 'answer', Lc::answerSdp())->ok)->toBeTrue();
    expect(app(AnswerLeadCall::class)->accept($x->agent, $x->call->id)->ok)->toBeTrue();

    $row = $x->fresh();
    expect($row->status)->toBe(CallStatus::Connected)->and($row->lead_id)->toBe($survivor->id)->and($row->routed_user_ids)->toBe([$x->agent->id, $x->other->id]);
});

test('the routing snapshot, not the lead\'s later assignee, decides who may act', function () {
    $x = Lc::make();
    $stranger = clientUserWithLeadPermission($x->tenant, ['view', 'update']);
    $x->lead->update(['assigned_to' => $stranger->id]);

    expect(fn () => app(ClaimLeadCall::class)->handle($stranger, $x->call->id))->toThrow(AuthorizationException::class);
    expect(app(ClaimLeadCall::class)->handle($x->agent, $x->call->id)->ok)->toBeTrue();
});

// ── Lead deletion ───────────────────────────────────────────────────────────

test('an answering or connected call still blocks deleting its lead, a rejected one does not', function () {
    $x = Lc::make();
    $eligibility = fn () => app(LeadDeletionEligibilityService::class)->evaluate($x->lead->fresh())->eligible;

    expect($eligibility())->toBeFalse();

    app(ClaimLeadCall::class)->handle($x->agent, $x->call->id);
    expect($x->fresh()->status)->toBe(CallStatus::Answering)->and($eligibility())->toBeFalse();

    lclConnect($x);
    expect($x->fresh()->status)->toBe(CallStatus::Connected)->and($eligibility())->toBeFalse();

    $y = Lc::make(['external_call_id' => 'wacid.REJECTED']);
    Lc::fakeMeta();
    app(RejectLeadCall::class)->handle($y->agent, $y->call->id);

    expect(app(LeadDeletionEligibilityService::class)->evaluate($y->lead->fresh())->eligible)->toBeTrue();
});

test('the call actions never touch the lead row', function () {
    $x = Lc::make();
    $before = $x->lead->fresh()->only(['updated_at', 'assigned_to', 'status_id', 'merged_into_lead_id']);
    $queries = [];

    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    Lc::fakeMeta();
    app(ClaimLeadCall::class)->handle($x->agent, $x->call->id);
    app(AnswerLeadCall::class)->answer($x->agent, $x->call->id, 'answer', Lc::answerSdp());
    app(AnswerLeadCall::class)->accept($x->agent, $x->call->id);

    $leadQueries = array_filter($queries, fn (string $sql): bool => str_contains($sql, '"leads"'));

    expect($leadQueries)->toBe([]);
    expect($x->lead->fresh()->only(['updated_at', 'assigned_to', 'status_id', 'merged_into_lead_id']))->toEqual($before);
    expect(LeadCall::count())->toBe(1);
});
