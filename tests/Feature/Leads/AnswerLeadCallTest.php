<?php

use App\Actions\Tenant\AnswerLeadCall;
use App\Actions\Tenant\RecordInboundCall;
use App\Actions\Tenant\RejectLeadCall;
use App\Enums\CallEndReason;
use App\Enums\CallMetaStage;
use App\Enums\CallStatus;
use App\Models\TenantUser;
use App\Support\LeadCallActionResult;
use App\Support\LeadCallSdp;
use App\Support\WhatsAppCallEvent;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\Support\LeadCallScenario as Lc;
use Tests\Support\WhatsAppCallFixtures as Wa;

/*
 * AnswerLeadCall: `answer` = Meta pre_accept, `accept` = Meta accept. The claimant
 * only, the answer SDP cached once and reused verbatim for accept, Meta failures
 * split into retryable (stay Answering) and permanent (Failed), and every Meta
 * result applied with a compare-and-set so a newer state is never overwritten.
 */

const ALC_CALLS_URL = 'https://graph.facebook.com/v25.0/436666719526789/calls';

function alcAnswer(Lc $x, ?TenantUser $user = null, mixed $sdp = 'default', mixed $type = 'answer'): LeadCallActionResult
{
    return app(AnswerLeadCall::class)->answer($user ?? $x->agent, $x->call->id, $type, $sdp === 'default' ? Lc::answerSdp() : $sdp);
}

function alcAccept(Lc $x, ?TenantUser $user = null): LeadCallActionResult
{
    return app(AnswerLeadCall::class)->accept($user ?? $x->agent, $x->call->id);
}

/**
 * The real Step 2 terminate handling, as a webhook would apply it.
 */
function alcTerminate(Lc $x, array $call = []): void
{
    $item = Wa::terminate(array_merge(['id' => Lc::CALL_ID], $call));
    $event = WhatsAppCallEvent::fromWebhook(Wa::change([$item]), $item);

    app(RecordInboundCall::class)->handle($event, $x->source, fn () => throw new RuntimeException('a terminate must never create a lead'), true);
}

function alcMetaError(int $code, int $status = 400): PromiseInterface
{
    return Http::response(['error' => ['message' => 'RAW META WORDING must never surface', 'type' => 'OAuthException', 'code' => $code, 'fbtrace_id' => 'TRACE-A']], $status);
}

// ── answer: who and when ────────────────────────────────────────────────────

test('answering an unclaimed call is refused and sends nothing', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make();

    $result = alcAnswer($x);

    expect($result->status)->toBe(409)->and($result->errorCode)->toBe('call_not_claimed');
    expect($x->fresh()->status)->toBe(CallStatus::Ringing)->and($log)->toHaveCount(0)->and($x->answerCached())->toBeFalse();
});

test('only the claimant may answer', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make()->claimed();

    $result = alcAnswer($x, $x->other);

    expect($result->status)->toBe(403)->and($result->errorCode)->toBe('not_claimant');
    expect($x->fresh()->meta_stage)->toBeNull()->and($log)->toHaveCount(0);
});

test('a user outside the routing snapshot cannot answer', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make()->claimed();

    expect(fn () => alcAnswer($x, $x->admin))->toThrow(AuthorizationException::class);

    expect($log)->toHaveCount(0);
});

test('another tenant\'s call id is not found', function () {
    $log = Lc::fakeMeta();
    $mine = Lc::make();
    $theirs = Lc::make(['external_call_id' => 'wacid.THEIRS'])->claimed();

    $result = app(AnswerLeadCall::class)->answer($mine->agent, $theirs->call->id, 'answer', Lc::answerSdp());

    expect($result->status)->toBe(404)->and($log)->toHaveCount(0);
});

test('a call that is no longer answering cannot be answered', function (string $state) {
    $log = Lc::fakeMeta();
    $x = Lc::make(['status' => $state, 'claimed_by' => null]);
    $x->call->update(['claimed_by' => $x->agent->id]);

    $result = alcAnswer($x);

    expect($result->status)->toBe(409)->and($result->errorCode)->toBe('call_not_answerable');
    expect($log)->toHaveCount(0);
})->with(['connected', 'completed', 'missed', 'rejected', 'failed']);

test('an expired call is refused without changing its row or calling Meta', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make()->claimed();
    $x->call->update(['ringing_expires_at' => now()->subSecond()]);

    $result = alcAnswer($x);

    expect($result->status)->toBe(409)->and($result->errorCode)->toBe('call_expired');
    expect($x->fresh()->status)->toBe(CallStatus::Answering)->and($x->fresh()->meta_stage)->toBeNull()->and($x->fresh()->ended_at)->toBeNull();
    expect($log)->toHaveCount(0)->and($x->answerCached())->toBeFalse();
});

test('answering does not need calling to still be enabled', function () {
    Lc::fakeMeta();
    $x = Lc::make()->claimed();
    $x->source->update(['calling_enabled' => false, 'is_active' => false]);

    expect(alcAnswer($x)->ok)->toBeTrue();
    expect($x->fresh()->meta_stage)->toBe(CallMetaStage::PreAccepted);
});

// ── answer: SDP validation ──────────────────────────────────────────────────

test('an invalid answer SDP is a 422 that changes nothing and sends nothing', function (mixed $sdp, mixed $type) {
    $log = Lc::fakeMeta();
    $x = Lc::make()->claimed();

    $result = alcAnswer($x, sdp: $sdp, type: $type);

    expect($result->status)->toBe(422)->and($result->errorCode)->toBe('invalid_sdp');
    expect($x->fresh()->meta_stage)->toBeNull()->and($x->answerCached())->toBeFalse()->and($log)->toHaveCount(0);
})->with([
    'missing' => [null, 'answer'],
    'empty' => ['', 'answer'],
    'not an sdp' => ['hello world', 'answer'],
    'array' => [['v=0'], 'answer'],
    'no media line' => ["v=0\r\ns=x\r\nt=0 0\r\n", 'answer'],
    'control character' => ["v=0\r\nm=audio 9 RTP/AVP 0\x00\r\n", 'answer'],
    'oversized' => ["v=0\r\nm=audio 9 RTP/AVP 0\r\na=".str_repeat('a', 20000)."\r\n", 'answer'],
    'sdp_type offer' => [null, 'offer'],
    'no sdp_type' => [null, null],
]);

test('an sdp_type of offer or none is refused even with a valid SDP', function (mixed $type) {
    $log = Lc::fakeMeta();
    $x = Lc::make()->claimed();

    expect(alcAnswer($x, type: $type)->errorCode)->toBe('invalid_sdp');
    expect($log)->toHaveCount(0);
})->with(['offer', null, 'ANSWER']);

test('the final CRLF is restored exactly once and an existing terminator is untouched', function (string $received, string $sent) {
    $log = Lc::fakeMeta();
    $x = Lc::make()->claimed();

    expect(alcAnswer($x, sdp: $received)->ok)->toBeTrue();

    expect($log[0]['body']['session']['sdp'])->toBe($sent);
})->with(function () {
    $sdp = Lc::answerSdp();
    $bare = rtrim($sdp, "\r\n");

    return [
        'trimmed by TrimStrings' => [$bare, $bare."\r\n"],
        'already CRLF' => [$sdp, $sdp],
        'bare LF' => [$bare."\n", $bare."\n"],
        'bare CR' => [$bare."\r", $bare."\r\n"],
    ];
});

test('normalizeAnswer never touches anything but the final terminator', function () {
    $body = "v=0\r\no=- 1 2 IN IP4 0.0.0.0\r\ns=-\r\nt=0 0\r\nm=audio 9 UDP/TLS/RTP/SAVPF 111\r\na=candidate:1 1 udp 1 1.2.3.4 5 typ host\r\na=fmtp:111 minptime=10";

    expect(LeadCallSdp::normalizeAnswer($body))->toBe($body."\r\n");
});

// ── answer: the Meta request and its outcome ────────────────────────────────

test('answer sends exactly the documented pre_accept request', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make()->claimed();

    alcAnswer($x);

    expect($log)->toHaveCount(1);
    expect($log[0])->toEqual([
        'method' => 'POST',
        'url' => ALC_CALLS_URL,
        'body' => [
            'messaging_product' => 'whatsapp',
            'call_id' => Lc::CALL_ID,
            'action' => 'pre_accept',
            'session' => ['sdp_type' => 'answer', 'sdp' => Lc::answerSdp()],
        ],
        'authorization' => 'Bearer '.Lc::TOKEN,
    ]);
});

test('a successful pre_accept records the stage, keeps the call answering and caches the answer encrypted', function () {
    Lc::fakeMeta(Http::response(['success' => true]));
    $x = Lc::make()->claimed();

    $result = alcAnswer($x);

    expect($result->ok)->toBeTrue()->and($result->session)->toBeNull();

    $row = $x->fresh();
    expect($row->status)->toBe(CallStatus::Answering)
        ->and($row->meta_stage)->toBe(CallMetaStage::PreAccepted)
        ->and($row->claimed_by)->toBe($x->agent->id)
        ->and($row->connected_at)->toBeNull();

    $stored = Cache::get(LeadCallSdp::answerKey($x->source, Lc::CALL_ID));
    expect($stored)->toBeString()->not->toContain(Lc::ANSWER_SENTINEL)->not->toContain('v=0');
    expect(Crypt::decryptString($stored))->toBe(Lc::answerSdp());
});

test('a duplicate answer after success is idempotent and does not call Meta again', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make()->claimed();
    alcAnswer($x);

    $again = alcAnswer($x);

    expect($again->ok)->toBeTrue();
    expect($log)->toHaveCount(1)->and($x->fresh()->meta_stage)->toBe(CallMetaStage::PreAccepted);
});

test('a retryable Meta failure keeps the call answering with the cached answer and says so', function (mixed $failure, int $http) {
    $log = Lc::fakeMeta($failure);
    $x = Lc::make()->claimed();

    $result = alcAnswer($x);

    expect($result->ok)->toBeFalse()
        ->and($result->status)->toBe($http)
        ->and($result->errorCode)->toBe('meta_unavailable')
        ->and($result->retryable)->toBeTrue()
        ->and($result->message)->not->toContain('RAW META WORDING');

    $row = $x->fresh();
    expect($row->status)->toBe(CallStatus::Answering)
        ->and($row->claimed_by)->toBe($x->agent->id)
        ->and($row->meta_stage)->toBeNull()
        ->and($row->end_reason)->toBeNull();
    expect($x->answerCached())->toBeTrue()->and($log)->toHaveCount(1);
})->with([
    'timeout (network)' => [new ConnectionException('cURL error 28'), 503],
    'server error (transient)' => [fn () => alcMetaError(138004, 500), 503],
    'rate limited (limit)' => [fn () => alcMetaError(138005, 429), 503],
    '2xx with an unexpected body (invalid response)' => [fn () => Http::response(['unexpected' => true], 200), 502],
]);

test('a permanent Meta failure fails the call with pre_accept_failed and drops the cached SDPs', function (mixed $failure) {
    $log = Lc::fakeMeta($failure);
    $x = Lc::make()->claimed();

    $result = alcAnswer($x);

    expect($result->ok)->toBeFalse()
        ->and($result->status)->toBe(502)
        ->and($result->errorCode)->toBe('meta_rejected')
        ->and($result->retryable)->toBeFalse()
        ->and($result->message)->not->toContain('RAW META WORDING');

    $row = $x->fresh();
    expect($row->status)->toBe(CallStatus::Failed)
        ->and($row->end_reason)->toBe(CallEndReason::PreAcceptFailed)
        ->and($row->ended_at)->not->toBeNull()
        ->and($row->meta_stage)->toBeNull();
    expect($x->answerCached())->toBeFalse()->and($x->offerCached())->toBeFalse()->and($log)->toHaveCount(1);
})->with([
    'invalid parameter' => [fn () => alcMetaError(100, 400)],
    'bad token' => [fn () => alcMetaError(190, 401)],
    'no permission' => [fn () => alcMetaError(200, 403)],
    'calling not enabled' => [fn () => alcMetaError(138000, 401)],
    'not eligible' => [fn () => alcMetaError(138015, 400)],
    'sip number' => [fn () => alcMetaError(131055, 400)],
    'payment' => [fn () => alcMetaError(131044, 400)],
    'unmapped 4xx' => [fn () => Http::response('<html>nope</html>', 404)],
]);

test('a source with no credentials fails the answer permanently without any request', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make()->claimed();
    $x->source->update(['config' => ['phone_number_id' => Wa::PHONE_NUMBER_ID, 'app_secret' => Wa::APP_SECRET, 'verify_token' => 'v']]);

    $result = alcAnswer($x);

    expect($result->errorCode)->toBe('meta_rejected');
    expect($x->fresh()->end_reason)->toBe(CallEndReason::PreAcceptFailed)->and($log)->toHaveCount(0);
});

test('a retry after a retryable failure resends the same cached answer, and a different one is refused', function () {
    $log = Lc::fakeMeta(new ConnectionException('timeout'));
    $x = Lc::make()->claimed();
    alcAnswer($x);

    // A different SDP than the first attempt's: refused, nothing sent.
    $other = str_replace('a=setup:active', 'a=setup:passive', Lc::answerSdp());
    $refused = alcAnswer($x, sdp: $other);
    expect($refused->status)->toBe(409)->and($refused->errorCode)->toBe('sdp_mismatch')->and($log)->toHaveCount(1);

    // The same SDP: sent again, this time successfully.
    $log = Lc::fakeMeta();
    $retry = alcAnswer($x);

    expect($retry->ok)->toBeTrue()->and($log)->toHaveCount(1);
    expect($log[0]['body']['session']['sdp'])->toBe(Lc::answerSdp());
    expect($x->fresh()->meta_stage)->toBe(CallMetaStage::PreAccepted);
});

test('two tabs of the same claimant cannot send duplicate pre_accept requests', function () {
    $x = Lc::make()->claimed();
    $second = null;
    $fired = false;

    $log = Lc::fakeMeta(function () use ($x, &$second, &$fired) {
        if (! $fired) {
            $fired = true;
            $second = alcAnswer($x);
        }

        return null;
    });

    $first = alcAnswer($x);

    expect($first->ok)->toBeTrue();
    expect($second->ok)->toBeFalse()->and($second->status)->toBe(409)->and($second->errorCode)->toBe('action_in_progress')->and($second->retryable)->toBeTrue();
    expect($log)->toHaveCount(1);
});

test('a held action lock refuses the request without calling Meta and is released afterwards', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make()->claimed();

    $lock = Cache::lock("wa-call-action:{$x->call->id}", 30);
    expect($lock->get())->toBeTrue();

    expect(alcAnswer($x)->errorCode)->toBe('action_in_progress');
    expect($log)->toHaveCount(0);

    $lock->release();

    expect(alcAnswer($x)->ok)->toBeTrue();
    // The lock taken by that request was released too.
    $again = Cache::lock("wa-call-action:{$x->call->id}", 30);
    expect($again->get())->toBeTrue();
});

// ── answer: races with terminate / reject ───────────────────────────────────

test('a terminate webhook that lands during pre_accept is never overwritten', function () {
    $x = Lc::make()->claimed();

    Lc::fakeMeta(function () use ($x) {
        alcTerminate($x);

        return null;
    });

    $result = alcAnswer($x);

    expect($result->ok)->toBeFalse()->and($result->status)->toBe(409)->and($result->errorCode)->toBe('call_state_changed');

    $row = $x->fresh();
    expect($row->status)->toBe(CallStatus::Missed)
        ->and($row->end_reason)->toBe(CallEndReason::EndedBeforeConnect)
        ->and($row->meta_stage)->toBeNull();
    expect($x->answerCached())->toBeFalse();
});

test('a reject that lands during pre_accept wins and the answer reports a conflict', function () {
    $x = Lc::make()->claimed();
    $fired = false;

    Lc::fakeMeta(function () use ($x, &$fired) {
        if (! $fired) {
            $fired = true;
            app(RejectLeadCall::class)->handle($x->agent, $x->call->id);
        }

        return null;
    });

    $result = alcAnswer($x);

    expect($result->status)->toBe(409)->and($result->errorCode)->toBe('call_state_changed');
    expect($x->fresh()->status)->toBe(CallStatus::Rejected)->and($x->fresh()->end_reason)->toBe(CallEndReason::AgentCancelledDuringAnswer)->and($x->fresh()->meta_stage)->toBeNull();
});

test('a permanent Meta failure that races a terminate does not overwrite the terminal state', function () {
    $x = Lc::make()->claimed();

    Lc::fakeMeta(function () use ($x) {
        alcTerminate($x);

        return alcMetaError(100, 400);
    });

    $result = alcAnswer($x);

    expect($result->errorCode)->toBe('call_state_changed');
    expect($x->fresh()->status)->toBe(CallStatus::Missed)->and($x->fresh()->end_reason)->toBe(CallEndReason::EndedBeforeConnect);
});

// ── accept ──────────────────────────────────────────────────────────────────

test('accepting an unclaimed call is refused', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make();

    $result = alcAccept($x);

    expect($result->status)->toBe(409)->and($result->errorCode)->toBe('call_not_claimed');
    expect($log)->toHaveCount(0);
});

test('only the claimant may accept, and a stranger cannot', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make()->preAccepted();

    expect(alcAccept($x, $x->other)->errorCode)->toBe('not_claimant');
    expect(fn () => alcAccept($x, $x->admin))->toThrow(AuthorizationException::class);
    expect($x->fresh()->status)->toBe(CallStatus::Answering)->and($log)->toHaveCount(0);
});

test('accept without a prior pre_accept is refused', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make()->claimed();

    $result = alcAccept($x);

    expect($result->status)->toBe(409)->and($result->errorCode)->toBe('pre_accept_required');
    expect($x->fresh()->status)->toBe(CallStatus::Answering)->and($log)->toHaveCount(0);
});

test('an expired call cannot be accepted and is left unchanged', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make()->preAccepted();
    $x->call->update(['ringing_expires_at' => now()->subSecond()]);

    $result = alcAccept($x);

    expect($result->errorCode)->toBe('call_expired');
    expect($x->fresh()->status)->toBe(CallStatus::Answering)->and($x->fresh()->meta_stage)->toBe(CallMetaStage::PreAccepted)->and($log)->toHaveCount(0);
});

test('accept sends the documented request with the exact SDP that was pre-accepted', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make()->claimed();

    alcAnswer($x);
    alcAccept($x);

    expect($log)->toHaveCount(2);
    expect($log[1])->toEqual([
        'method' => 'POST',
        'url' => ALC_CALLS_URL,
        'body' => [
            'messaging_product' => 'whatsapp',
            'call_id' => Lc::CALL_ID,
            'action' => 'accept',
            'session' => ['sdp_type' => 'answer', 'sdp' => Lc::answerSdp()],
        ],
        'authorization' => 'Bearer '.Lc::TOKEN,
    ]);
    expect($log[1]['body']['session']['sdp'])->toBe($log[0]['body']['session']['sdp']);
});

test('a successful accept connects the call and preserves the claim', function () {
    Lc::fakeMeta();
    $x = Lc::make()->claimed();
    alcAnswer($x);
    $claimedAt = $x->fresh()->claimed_at;

    $result = alcAccept($x);

    expect($result->ok)->toBeTrue()->and($result->session)->toBeNull();

    $row = $x->fresh();
    expect($row->status)->toBe(CallStatus::Connected)
        ->and($row->meta_stage)->toBe(CallMetaStage::Accepted)
        ->and($row->connected_at)->not->toBeNull()
        ->and($row->claimed_by)->toBe($x->agent->id)
        ->and($row->claimed_at->equalTo($claimedAt))->toBeTrue()
        ->and($row->end_reason)->toBeNull();
    expect($x->answerCached())->toBeFalse()->and($x->offerCached())->toBeFalse();
});

test('a duplicate accept is idempotent, never calls Meta again and cannot be repeated by someone else', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make()->claimed();
    alcAnswer($x);
    alcAccept($x);
    $connectedAt = $x->fresh()->connected_at;

    $again = alcAccept($x);

    expect($again->ok)->toBeTrue()->and($again->call->status)->toBe(CallStatus::Connected);
    expect($log)->toHaveCount(2)->and($x->fresh()->connected_at->equalTo($connectedAt))->toBeTrue();
    expect(alcAccept($x, $x->other)->errorCode)->toBe('not_claimant');
});

test('accept ignores any SDP the browser might send and uses only the cached answer', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make()->claimed();
    alcAnswer($x);

    // The action's signature takes no SDP at all: there is nothing to submit.
    expect((new ReflectionMethod(AnswerLeadCall::class, 'accept'))->getNumberOfParameters())->toBe(2);

    alcAccept($x);

    expect($log[1]['body']['session']['sdp'])->toBe(Lc::answerSdp());
});

test('accept does not need calling to still be enabled', function () {
    Lc::fakeMeta();
    $x = Lc::make()->preAccepted();
    $x->source->update(['calling_enabled' => false]);

    expect(alcAccept($x)->ok)->toBeTrue();
    expect($x->fresh()->status)->toBe(CallStatus::Connected);
});

test('a retryable accept failure keeps the call answering with its answer, and a retry succeeds with the same SDP', function (mixed $failure) {
    $x = Lc::make()->preAccepted();
    $log = Lc::fakeMeta($failure);

    $result = alcAccept($x);

    expect($result->ok)->toBeFalse()->and($result->errorCode)->toBe('meta_unavailable')->and($result->retryable)->toBeTrue();
    $row = $x->fresh();
    expect($row->status)->toBe(CallStatus::Answering)->and($row->meta_stage)->toBe(CallMetaStage::PreAccepted)->and($row->connected_at)->toBeNull();
    expect($x->answerCached())->toBeTrue();

    $retryLog = Lc::fakeMeta();
    expect(alcAccept($x)->ok)->toBeTrue();
    expect($retryLog[0]['body']['session']['sdp'])->toBe(Lc::answerSdp());
    expect($x->fresh()->status)->toBe(CallStatus::Connected);
})->with([
    'timeout' => [new ConnectionException('cURL error 28')],
    'server error' => [fn () => alcMetaError(138007, 500)],
    'rate limited' => [fn () => alcMetaError(138002, 429)],
    'unexpected 2xx' => [fn () => Http::response(['nope' => 1], 200)],
]);

test('a permanent accept failure fails the call with accept_failed and drops the cached SDPs', function (mixed $failure) {
    $x = Lc::make()->preAccepted();
    $log = Lc::fakeMeta($failure);

    $result = alcAccept($x);

    expect($result->errorCode)->toBe('meta_rejected')->and($result->status)->toBe(502)->and($result->message)->not->toContain('RAW META WORDING');
    $row = $x->fresh();
    expect($row->status)->toBe(CallStatus::Failed)->and($row->end_reason)->toBe(CallEndReason::AcceptFailed)->and($row->ended_at)->not->toBeNull();
    expect($x->answerCached())->toBeFalse()->and($log)->toHaveCount(1);
})->with([
    'invalid parameter' => [fn () => alcMetaError(100, 400)],
    'bad token' => [fn () => alcMetaError(190, 401)],
    'unmapped 400' => [fn () => Http::response(['error' => ['message' => 'RAW META WORDING', 'code' => 999999]], 400)],
]);

test('a lost cached answer fails the accept without asking Meta', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make()->preAccepted();
    Cache::forget(LeadCallSdp::answerKey($x->source, Lc::CALL_ID));

    $result = alcAccept($x);

    expect($result->errorCode)->toBe('answer_unavailable');
    expect($x->fresh()->status)->toBe(CallStatus::Failed)->and($x->fresh()->end_reason)->toBe(CallEndReason::SdpCacheFailure)->and($log)->toHaveCount(0);
});

test('a terminate webhook that lands during accept is never overwritten', function (array $terminate, CallStatus $expected) {
    $x = Lc::make()->preAccepted();

    Lc::fakeMeta(function () use ($x, $terminate) {
        alcTerminate($x, $terminate);

        return null;
    });

    $result = alcAccept($x);

    expect($result->status)->toBe(409)->and($result->errorCode)->toBe('call_state_changed');
    $row = $x->fresh();
    expect($row->status)->toBe($expected)->and($row->meta_stage)->toBe(CallMetaStage::PreAccepted)->and($row->connected_at)->toBeNull();
})->with([
    'caller gave up' => [[], CallStatus::Missed],
    'terminate carrying a FAILED status token but no errors' => [['status' => 'FAILED'], CallStatus::Missed],
]);

test('a reject that lands during accept wins', function () {
    $x = Lc::make()->preAccepted();
    $fired = false;

    Lc::fakeMeta(function () use ($x, &$fired) {
        if (! $fired) {
            $fired = true;
            app(RejectLeadCall::class)->handle($x->agent, $x->call->id);
        }

        return null;
    });

    $result = alcAccept($x);

    expect($result->errorCode)->toBe('call_state_changed');
    expect($x->fresh()->status)->toBe(CallStatus::Rejected)->and($x->fresh()->connected_at)->toBeNull();
});

// ── Leakage ─────────────────────────────────────────────────────────────────

test('answer and accept never log the SDP, the token, the app secret or Meta\'s raw text', function () {
    $logs = Lc::captureLogs();
    $x = Lc::make()->claimed();

    Lc::fakeMeta(alcMetaError(100, 400));
    alcAnswer($x);

    $y = Lc::make(['external_call_id' => 'wacid.OTHER'])->preAccepted();
    Lc::fakeMeta();
    app(AnswerLeadCall::class)->accept($y->agent, $y->call->id);

    $text = Lc::logText($logs);

    expect($text)->toContain('WA CALL ACTION')->toContain('meta_failed')->toContain('TRACE-A')
        ->not->toContain(Lc::ANSWER_SENTINEL)
        ->not->toContain(Wa::SDP_SENTINEL)
        ->not->toContain(Lc::TOKEN)
        ->not->toContain(Wa::APP_SECRET)
        ->not->toContain('RAW META WORDING')
        ->not->toContain('v=0');
});

test('no result of answer or accept carries an SDP, a token or Meta\'s text', function () {
    Lc::fakeMeta(alcMetaError(190, 401));
    $x = Lc::make()->claimed();

    $result = alcAnswer($x);

    expect(print_r($result, true).json_encode($result))
        ->not->toContain(Lc::ANSWER_SENTINEL)
        ->not->toContain(Lc::TOKEN)
        ->not->toContain('RAW META WORDING');
});
