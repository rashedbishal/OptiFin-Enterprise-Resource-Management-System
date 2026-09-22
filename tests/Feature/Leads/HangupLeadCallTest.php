<?php

use App\Actions\Tenant\FinalizeStaleLeadCalls;
use App\Actions\Tenant\HangupLeadCall;
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
 * HangupLeadCall (Step 7a, additive to the closed Step 4-6 scope): the
 * claimant of a Connected call ends it. Local-first compare-and-set to
 * Completed (end_reason agent_hangup), then exactly one Meta `terminate`,
 * never retried and never rolled back on failure. Ringing/Answering keep their
 * existing Reject/Decline behaviour untouched; this action only ever acts on
 * Connected (or an already-terminal) call.
 */

function hangupAs(Lc $x, ?TenantUser $user = null, ?string $callId = null): LeadCallActionResult
{
    return app(HangupLeadCall::class)->handle($user ?? $x->agent, $callId ?? $x->call->id);
}

// ── Happy path ──────────────────────────────────────────────────────────────

test('the claimant hangs up a connected call', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make()->connected(connectedAt: now()->subSeconds(90));

    $result = hangupAs($x);

    expect($result->ok)->toBeTrue()->and($result->metaConfirmed)->toBeTrue()->and($result->call->status)->toBe(CallStatus::Completed);

    $row = $x->fresh();
    expect($row->status)->toBe(CallStatus::Completed)
        ->and($row->end_reason)->toBe(CallEndReason::AgentHangup)
        ->and($row->ended_at)->not->toBeNull()
        ->and($row->duration_seconds)->toBeInt()->toBeGreaterThanOrEqual(89)->toBeLessThanOrEqual(120)
        ->and($row->claimed_by)->toBe($x->agent->id);
    expect($log)->toHaveCount(1);
});

test('hangup sends exactly the documented Meta terminate request with the source\'s own credentials', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make()->connected();

    hangupAs($x);

    expect($log[0])->toMatchArray([
        'method' => 'POST',
        'url' => 'https://graph.facebook.com/v25.0/'.Wa::PHONE_NUMBER_ID.'/calls',
        'body' => ['messaging_product' => 'whatsapp', 'call_id' => Lc::CALL_ID, 'action' => 'terminate'],
        'authorization' => 'Bearer '.Lc::TOKEN,
    ]);
});

test('duration_seconds is written as a genuine integer even when connected_at and now carry a sub-second remainder', function () {
    // Reproduces the real-world failure exactly: real Postgres timestamps carry
    // microsecond precision, so a locally computed diffInSeconds() between
    // connected_at and now() can be a float (e.g. 15.441112). The compare-and-set
    // write goes through Builder::update(), which bypasses the model's
    // `duration_seconds => integer` cast entirely, so a float sent as-is is
    // rejected outright by Postgres' strictly typed integer column — SQLite
    // accepts it silently, so only inspecting the RAW (un-cast) column, not the
    // Eloquent-cast one, can catch a regression here.
    Lc::fakeMeta();
    $x = Lc::make()->connected(connectedAt: now()->subSeconds(15)->addMicroseconds(441112));

    $result = hangupAs($x);

    expect($result->ok)->toBeTrue();
    $raw = DB::table('lead_calls')->where('id', $x->call->id)->value('duration_seconds');
    expect($raw)->toBeInt();
});

test('hanging up drops any lingering cached SDP', function () {
    Lc::fakeMeta();
    $x = Lc::make()->connected();

    hangupAs($x);

    expect($x->offerCached())->toBeFalse()->and($x->answerCached())->toBeFalse();
});

// ── Claimant-only authorization ─────────────────────────────────────────────

test('another routed user cannot hang up a call someone else is on', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make()->connected();

    $result = hangupAs($x, $x->other);

    expect($result->ok)->toBeFalse()->and($result->status)->toBe(403)->and($result->errorCode)->toBe('not_claimant');
    expect($x->fresh()->status)->toBe(CallStatus::Connected)->and($log)->toHaveCount(0);
});

test('a user outside the routing snapshot entirely cannot hang up', function (string $who) {
    $log = Lc::fakeMeta();
    $x = Lc::make(['routed_user_ids' => []])->connected();

    expect(fn () => hangupAs($x, $who === 'admin' ? $x->admin : $x->agent))->toThrow(AuthorizationException::class);

    expect($x->fresh()->status)->toBe(CallStatus::Connected)->and($log)->toHaveCount(0);
})->with(['client admin' => ['admin'], 'client user' => ['agent']]);

// ── Cross-tenant isolation ──────────────────────────────────────────────────

test('a forged id and another tenant\'s call are not found and nothing is sent', function () {
    $log = Lc::fakeMeta();
    $mine = Lc::make()->connected();
    $theirs = Lc::make(['external_call_id' => 'wacid.THEIRS'])->connected();

    expect(hangupAs($mine, callId: '01ARZ3NDEKTSV4RRFFQ69G5FAV')->status)->toBe(404);
    expect(hangupAs($theirs, $mine->agent)->status)->toBe(404);

    expect($theirs->fresh()->status)->toBe(CallStatus::Connected)->and($log)->toHaveCount(0);
});

// ── Local first, Meta failure, no retry ─────────────────────────────────────

test('the call is already Completed locally when Meta is asked', function () {
    $x = Lc::make()->connected();
    $statusAtRequest = null;

    Lc::fakeMeta(function () use ($x, &$statusAtRequest) {
        $statusAtRequest = $x->fresh()->status;

        return null;
    });

    hangupAs($x);

    expect($statusAtRequest)->toBe(CallStatus::Completed);
});

test('a Meta failure never rolls the local hangup back and is never retried', function (mixed $failure) {
    $log = Lc::fakeMeta($failure);
    $x = Lc::make()->connected();

    $result = hangupAs($x);

    expect($result->ok)->toBeTrue()->and($result->metaConfirmed)->toBeFalse();
    expect($x->fresh()->status)->toBe(CallStatus::Completed)->and($x->fresh()->end_reason)->toBe(CallEndReason::AgentHangup);
    expect($log)->toHaveCount(1);
})->with([
    'server error' => [fn () => Http::response(['error' => ['code' => 138004, 'message' => 'raw']], 500)],
    'rejected' => [fn () => Http::response(['error' => ['code' => 100, 'message' => 'raw']], 400)],
    'timeout' => [new ConnectionException('cURL error 28')],
    'malformed success' => [fn () => Http::response('<html>ok</html>', 200)],
]);

test('a source without credentials still hangs up locally and sends nothing', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make()->connected();
    $x->source->update(['config' => ['phone_number_id' => Wa::PHONE_NUMBER_ID, 'app_secret' => Wa::APP_SECRET, 'verify_token' => 'v']]);

    $result = hangupAs($x);

    expect($result->ok)->toBeTrue()->and($result->metaConfirmed)->toBeFalse();
    expect($x->fresh()->status)->toBe(CallStatus::Completed)->and($log)->toHaveCount(0);
});

// ── Duplicates and idempotency ──────────────────────────────────────────────

test('a duplicate hangup by the same claimant is an idempotent success that does not call Meta again', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make()->connected();
    hangupAs($x);
    $endedAt = $x->fresh()->ended_at;

    $again = hangupAs($x);

    expect($again->ok)->toBeTrue()->and($again->call->status)->toBe(CallStatus::Completed);
    expect($log)->toHaveCount(1)->and($x->fresh()->ended_at->equalTo($endedAt))->toBeTrue();
});

test('a hangup after the call already ended for a different reason is still a success for the claimant', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make()->connected();
    $x->call->update(['status' => CallStatus::Completed, 'end_reason' => CallEndReason::RemoteTerminated, 'ended_at' => now()]);

    $result = hangupAs($x);

    expect($result->ok)->toBeTrue()->and($result->call->status)->toBe(CallStatus::Completed);
    expect($x->fresh()->end_reason)->toBe(CallEndReason::RemoteTerminated)->and($log)->toHaveCount(0);
});

test('a non-claimant still cannot "hang up" an already-ended call', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make()->connected();
    $x->call->update(['status' => CallStatus::Completed, 'end_reason' => CallEndReason::AgentHangup, 'ended_at' => now()]);

    $result = hangupAs($x, $x->other);

    expect($result->ok)->toBeFalse()->and($result->status)->toBe(403)->and($result->errorCode)->toBe('not_claimant');
    expect($log)->toHaveCount(0);
});

// ── Wrong states: Ringing / Answering are untouched ─────────────────────────

test('a ringing call cannot be hung up — Reject/Decline still covers it', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make();
    $before = $x->fresh()->only(['status', 'end_reason', 'ended_at']);

    $result = hangupAs($x);

    expect($result->ok)->toBeFalse()->and($result->status)->toBe(409)->and($result->errorCode)->toBe('call_not_connected');
    expect($x->fresh()->only(['status', 'end_reason', 'ended_at']))->toEqual($before)->and($log)->toHaveCount(0);
});

test('an answering call cannot be hung up by its claimant — Reject/Decline still covers it', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make()->claimed();
    $before = $x->fresh()->only(['status', 'end_reason', 'ended_at']);

    $result = hangupAs($x);

    expect($result->ok)->toBeFalse()->and($result->status)->toBe(409)->and($result->errorCode)->toBe('call_not_connected');
    expect($x->fresh()->only(['status', 'end_reason', 'ended_at']))->toEqual($before)->and($log)->toHaveCount(0);
});

test('a finished call in a terminal, non-Connected state for another reason is left exactly as it is for a non-claimant', function (string $state) {
    $log = Lc::fakeMeta();
    $x = Lc::make(['status' => $state, 'claimed_by' => null]);
    $before = $x->fresh()->only(['status', 'end_reason', 'ended_at', 'claimed_by']);

    $result = hangupAs($x);

    expect($result->ok)->toBeFalse()->and($result->status)->toBe(403)->and($result->errorCode)->toBe('not_claimant');
    expect($x->fresh()->only(['status', 'end_reason', 'ended_at', 'claimed_by']))->toEqual($before)->and($log)->toHaveCount(0);
})->with(['missed', 'rejected', 'failed']);

// ── Races ───────────────────────────────────────────────────────────────────

test('a hangup racing a terminate webhook does not overwrite the terminal state it lost to', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make()->connected();

    Lc::interleaveAfterQuery('from "lead_sources"', fn () => DB::table('lead_calls')->where('id', $x->call->id)->update([
        'status' => CallStatus::Completed->value,
        'end_reason' => CallEndReason::RemoteTerminated->value,
        'ended_at' => now(),
        'duration_seconds' => 42,
    ]));

    $result = hangupAs($x);

    expect($result->ok)->toBeTrue();
    expect($x->fresh()->end_reason)->toBe(CallEndReason::RemoteTerminated)->and($x->fresh()->duration_seconds)->toBe(42)->and($log)->toHaveCount(0);
});

test('a hangup racing the Step 6 stale-call sweep does not overwrite the sweep\'s outcome', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make()->connected(connectedAt: now()->subMinutes(200));

    Lc::interleaveAfterQuery('from "lead_sources"', fn () => app(FinalizeStaleLeadCalls::class)->handle());

    $result = hangupAs($x);

    expect($result->ok)->toBeTrue();
    $row = $x->fresh();
    expect($row->status)->toBe(CallStatus::Completed)
        ->and($row->end_reason)->toBe(CallEndReason::StaleNoTerminate)
        ->and($row->duration_seconds)->toBeNull()
        ->and($log)->toHaveCount(0);
});

// ── Leakage ─────────────────────────────────────────────────────────────────

test('hanging up never logs the token, the SDP or Meta\'s raw error text', function () {
    $logs = Lc::captureLogs();
    Lc::fakeMeta(Http::response(['error' => ['code' => 100, 'message' => 'RAW META WORDING '.Lc::TOKEN, 'fbtrace_id' => 'TRACE-H']], 400));
    $x = Lc::make()->connected();

    hangupAs($x);

    $text = Lc::logText($logs);

    expect($text)->toContain('WA CALL ACTION')->toContain('hung_up_meta_unconfirmed')->toContain('TRACE-H')
        ->not->toContain(Lc::TOKEN)
        ->not->toContain('RAW META WORDING')
        ->not->toContain(Wa::SDP_SENTINEL)
        ->not->toContain('Bearer');
});
