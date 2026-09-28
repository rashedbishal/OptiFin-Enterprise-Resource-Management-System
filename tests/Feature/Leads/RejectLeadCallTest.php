<?php

use App\Actions\Tenant\ClaimLeadCall;
use App\Actions\Tenant\RejectLeadCall;
use App\Enums\CallEndReason;
use App\Enums\CallStatus;
use App\Models\TenantUser;
use App\Support\LeadCallActionResult;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\LeadCallScenario as Lc;
use Tests\Support\WhatsAppCallFixtures as Wa;

/*
 * RejectLeadCall: local-first compare-and-set to Rejected, then exactly one Meta
 * `reject`. Meta's outcome never rolls the local decision back.
 */

function rejectAs(Lc $x, ?TenantUser $user = null, ?string $callId = null): LeadCallActionResult
{
    return app(RejectLeadCall::class)->handle($user ?? $x->agent, $callId ?? $x->call->id);
}

// ── Ringing ─────────────────────────────────────────────────────────────────

test('a routed user rejects a ringing call without claiming it first', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make();

    $result = rejectAs($x);

    expect($result->ok)->toBeTrue()->and($result->metaConfirmed)->toBeTrue()->and($result->call->status)->toBe(CallStatus::Rejected);

    $row = $x->fresh();
    expect($row->status)->toBe(CallStatus::Rejected)
        ->and($row->end_reason)->toBe(CallEndReason::AgentRejected)
        ->and($row->ended_at)->not->toBeNull()
        ->and($row->claimed_by)->toBeNull();
    expect($log)->toHaveCount(1);
});

test('reject sends exactly the documented Meta request with the source\'s own credentials', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make();

    rejectAs($x);

    expect($log[0])->toMatchArray([
        'method' => 'POST',
        'url' => 'https://graph.facebook.com/v25.0/'.Wa::PHONE_NUMBER_ID.'/calls',
        'body' => ['messaging_product' => 'whatsapp', 'call_id' => Lc::CALL_ID, 'action' => 'reject'],
        'authorization' => 'Bearer '.Lc::TOKEN,
    ]);
});

test('rejecting drops the cached offer', function () {
    Lc::fakeMeta();
    $x = Lc::make();

    rejectAs($x);

    expect($x->offerCached())->toBeFalse();
});

test('a user outside the routing snapshot cannot reject', function (string $who) {
    $log = Lc::fakeMeta();
    $x = Lc::make(['routed_user_ids' => []]);

    expect(fn () => rejectAs($x, $who === 'admin' ? $x->admin : $x->agent))->toThrow(AuthorizationException::class);

    expect($x->fresh()->status)->toBe(CallStatus::Ringing)->and($log)->toHaveCount(0);
})->with(['client admin' => ['admin'], 'client user' => ['agent']]);

test('a forged id and another tenant\'s call are not found and nothing is sent', function () {
    $log = Lc::fakeMeta();
    $mine = Lc::make();
    $theirs = Lc::make(['external_call_id' => 'wacid.THEIRS']);

    expect(rejectAs($mine, callId: '01ARZ3NDEKTSV4RRFFQ69G5FAV')->status)->toBe(404);
    expect(rejectAs($theirs, $mine->agent)->status)->toBe(404);

    expect($theirs->fresh()->status)->toBe(CallStatus::Ringing)->and($log)->toHaveCount(0);
});

// ── WhatsApp Outbound Calling: the inbound pipeline must never reach it ─────

test('an outbound call can never reach the inbound reject pipeline', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make(['direction' => 'outbound']);

    $result = rejectAs($x);

    expect($result->ok)->toBeFalse()->and($result->status)->toBe(404)->and($result->errorCode)->toBe('call_not_found');
    expect($x->fresh()->status)->toBe(CallStatus::Ringing)->and($log)->toHaveCount(0);
});

test('an expired but still-ringing call can still be declined by an agent', function () {
    Lc::fakeMeta();
    $x = Lc::make(['ringing_expires_at' => now()->subMinute()]);

    $result = rejectAs($x);

    expect($result->ok)->toBeTrue();
    expect($x->fresh()->status)->toBe(CallStatus::Rejected);
});

test('declining is allowed even when calling has been switched off', function () {
    Lc::fakeMeta();
    $x = Lc::make(source: ['calling_enabled' => false, 'is_active' => false]);

    expect(rejectAs($x)->ok)->toBeTrue();
    expect($x->fresh()->status)->toBe(CallStatus::Rejected);
});

// ── Answering ───────────────────────────────────────────────────────────────

test('the claimant rejects an answering call', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make()->claimed();

    $result = rejectAs($x);

    expect($result->ok)->toBeTrue();
    $row = $x->fresh();
    expect($row->status)->toBe(CallStatus::Rejected)
        ->and($row->end_reason)->toBe(CallEndReason::AgentCancelledDuringAnswer)
        ->and($row->claimed_by)->toBe($x->agent->id)
        ->and($log)->toHaveCount(1);
});

test('another routed user cannot reject a call someone else is answering', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make()->claimed();

    $result = rejectAs($x, $x->other);

    expect($result->ok)->toBeFalse()->and($result->status)->toBe(403)->and($result->errorCode)->toBe('not_claimant');
    expect($x->fresh()->status)->toBe(CallStatus::Answering)->and($log)->toHaveCount(0);
});

test('a pre-accepted call can be rejected by its claimant and its cached answer is dropped', function () {
    Lc::fakeMeta();
    $x = Lc::make()->preAccepted();

    expect(rejectAs($x)->ok)->toBeTrue();
    expect($x->fresh()->status)->toBe(CallStatus::Rejected)->and($x->answerCached())->toBeFalse();
});

// ── Local first, Meta failure, no retry ─────────────────────────────────────

test('the call is already Rejected locally when Meta is asked', function () {
    $x = Lc::make();
    $statusAtRequest = null;

    Lc::fakeMeta(function () use ($x, &$statusAtRequest) {
        $statusAtRequest = $x->fresh()->status;

        return null;
    });

    rejectAs($x);

    expect($statusAtRequest)->toBe(CallStatus::Rejected);
});

test('a Meta failure never rolls the local rejection back and is never retried', function (mixed $failure) {
    $log = Lc::fakeMeta($failure);
    $x = Lc::make();

    $result = rejectAs($x);

    expect($result->ok)->toBeTrue()->and($result->metaConfirmed)->toBeFalse();
    expect($x->fresh()->status)->toBe(CallStatus::Rejected)->and($x->fresh()->end_reason)->toBe(CallEndReason::AgentRejected);
    expect($log)->toHaveCount(1);
})->with([
    'server error' => [fn () => Http::response(['error' => ['code' => 138004, 'message' => 'raw']], 500)],
    'rejected' => [fn () => Http::response(['error' => ['code' => 100, 'message' => 'raw']], 400)],
    'timeout' => [new ConnectionException('cURL error 28')],
    'malformed success' => [fn () => Http::response('<html>ok</html>', 200)],
]);

test('a source without credentials still rejects locally and sends nothing', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make();
    $x->source->update(['config' => ['phone_number_id' => Wa::PHONE_NUMBER_ID, 'app_secret' => Wa::APP_SECRET, 'verify_token' => 'v']]);

    $result = rejectAs($x);

    expect($result->ok)->toBeTrue()->and($result->metaConfirmed)->toBeFalse();
    expect($x->fresh()->status)->toBe(CallStatus::Rejected)->and($log)->toHaveCount(0);
});

// ── Duplicates and terminal calls ───────────────────────────────────────────

test('a duplicate reject is an idempotent success that does not call Meta again', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make();
    rejectAs($x);
    $endedAt = $x->fresh()->ended_at;

    $again = rejectAs($x);

    expect($again->ok)->toBeTrue()->and($again->call->status)->toBe(CallStatus::Rejected);
    expect($log)->toHaveCount(1)->and($x->fresh()->ended_at->equalTo($endedAt))->toBeTrue();
});

test('a duplicate reject of an answering call is idempotent for its claimant only', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make()->claimed();
    rejectAs($x);

    expect(rejectAs($x)->ok)->toBeTrue();
    $other = rejectAs($x, $x->other);

    expect($other->status)->toBe(403)->and($other->errorCode)->toBe('not_claimant');
    expect($log)->toHaveCount(1);
});

test('a finished or connected call cannot be rejected and is left exactly as it is', function (string $state) {
    $log = Lc::fakeMeta();
    $x = Lc::make(['status' => $state, 'claimed_by' => null]);
    $before = $x->fresh()->only(['status', 'end_reason', 'ended_at', 'claimed_by']);

    $result = rejectAs($x);

    expect($result->ok)->toBeFalse()->and($result->status)->toBe(409)->and($result->errorCode)->toBe('call_not_rejectable');
    expect($x->fresh()->only(['status', 'end_reason', 'ended_at', 'claimed_by']))->toEqual($before)->and($log)->toHaveCount(0);
})->with(['connected', 'completed', 'missed', 'failed']);

// ── Races ───────────────────────────────────────────────────────────────────

test('a reject that loses to another user\'s claim is refused and never overwrites the claim', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make();

    Lc::interleaveAfterQuery('from "lead_sources"', fn () => app(ClaimLeadCall::class)->handle($x->other, $x->call->id));

    $result = rejectAs($x);

    expect($result->ok)->toBeFalse()->and($result->status)->toBe(403)->and($result->errorCode)->toBe('not_claimant');
    expect($x->fresh()->status)->toBe(CallStatus::Answering)->and($x->fresh()->claimed_by)->toBe($x->other->id)->and($log)->toHaveCount(0);
});

test('a reject that loses a race to the user\'s own claim rejects the answering call', function () {
    Lc::fakeMeta();
    $x = Lc::make();

    Lc::interleaveAfterQuery('from "lead_sources"', fn () => app(ClaimLeadCall::class)->handle($x->agent, $x->call->id));

    $result = rejectAs($x);

    expect($result->ok)->toBeTrue();
    expect($x->fresh()->status)->toBe(CallStatus::Rejected)->and($x->fresh()->end_reason)->toBe(CallEndReason::AgentCancelledDuringAnswer);
});

test('a reject racing a terminate webhook does not overwrite the terminal state', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make();

    Lc::interleaveAfterQuery('from "lead_sources"', fn () => DB::table('lead_calls')->where('id', $x->call->id)->update([
        'status' => CallStatus::Missed->value,
        'end_reason' => CallEndReason::CallerEndedOrNotAnswered->value,
        'ended_at' => now(),
    ]));

    $result = rejectAs($x);

    expect($result->ok)->toBeFalse()->and($result->errorCode)->toBe('call_not_rejectable');
    expect($x->fresh()->status)->toBe(CallStatus::Missed)->and($log)->toHaveCount(0);
});

// ── Leakage ─────────────────────────────────────────────────────────────────

test('rejecting never logs the token, the SDP or Meta\'s raw error text', function () {
    $logs = Lc::captureLogs();
    Lc::fakeMeta(Http::response(['error' => ['code' => 100, 'message' => 'RAW META WORDING '.Lc::TOKEN, 'fbtrace_id' => 'TRACE-R']], 400));
    $x = Lc::make();

    rejectAs($x);

    $text = Lc::logText($logs);

    expect($text)->toContain('WA CALL ACTION')->toContain('rejected_meta_unconfirmed')->toContain('TRACE-R')
        ->not->toContain(Lc::TOKEN)
        ->not->toContain('RAW META WORDING')
        ->not->toContain(Wa::SDP_SENTINEL)
        ->not->toContain('Bearer');
});
