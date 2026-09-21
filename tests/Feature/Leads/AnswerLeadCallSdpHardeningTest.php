<?php

use App\Actions\Tenant\AnswerLeadCall;
use App\Enums\CallMetaStage;
use App\Enums\CallStatus;
use App\Support\LeadCallActionResult;
use App\Support\LeadCallSdp;
use Illuminate\Support\Facades\Cache;
use Tests\Support\LeadCallScenario as Lc;

/*
 * Step 5 hardening of Step 4's `answer`: once a call is pre-accepted, a repeated
 * answer is idempotent ONLY for the very SDP Meta was given. A different SDP —
 * say, a second browser tab of the same claimant — is a 409 that calls nothing,
 * changes nothing and overwrites nothing.
 */

function sdhAnswer(Lc $x, string $sdp): LeadCallActionResult
{
    return app(AnswerLeadCall::class)->answer($x->agent, $x->call->id, 'answer', $sdp);
}

function sdhOtherSdp(): string
{
    return str_replace('a=setup:active', 'a=setup:passive', Lc::answerSdp());
}

test('an identical answer after pre_accept is an idempotent success that never calls Meta', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make()->preAccepted();

    $result = sdhAnswer($x, Lc::answerSdp());

    expect($result->ok)->toBeTrue();
    expect($log)->toHaveCount(0)->and($x->fresh()->meta_stage)->toBe(CallMetaStage::PreAccepted)->and($x->fresh()->status)->toBe(CallStatus::Answering);
});

test('an identical answer whose final CRLF was trimmed in transit is still the same answer', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make()->preAccepted();

    expect(sdhAnswer($x, rtrim(Lc::answerSdp(), "\r\n"))->ok)->toBeTrue();
    expect($log)->toHaveCount(0);
});

test('a different answer after pre_accept is a 409 sdp_mismatch that changes nothing', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make()->preAccepted();
    $before = $x->fresh()->only(['status', 'meta_stage', 'claimed_by', 'end_reason', 'ended_at', 'updated_at']);

    $result = sdhAnswer($x, sdhOtherSdp());

    expect($result->ok)->toBeFalse()->and($result->status)->toBe(409)->and($result->errorCode)->toBe('sdp_mismatch')->and($result->retryable)->toBeFalse();
    expect($log)->toHaveCount(0);
    expect($x->fresh()->only(['status', 'meta_stage', 'claimed_by', 'end_reason', 'ended_at', 'updated_at']))->toEqual($before);
});

test('a mismatched answer never overwrites the cached answer, so accept still sends the original', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make()->preAccepted();
    $encryptedBefore = Cache::get(LeadCallSdp::answerKey($x->source, Lc::CALL_ID));

    sdhAnswer($x, sdhOtherSdp());

    expect(Cache::get(LeadCallSdp::answerKey($x->source, Lc::CALL_ID)))->toBe($encryptedBefore);
    expect(LeadCallSdp::readAnswer($x->source, Lc::CALL_ID))->toBe(Lc::answerSdp());

    expect(app(AnswerLeadCall::class)->accept($x->agent, $x->call->id)->ok)->toBeTrue();
    expect($log)->toHaveCount(1)->and($log[0]['body']['session']['sdp'])->toBe(Lc::answerSdp());
});

test('when the cached answer is gone a repeated answer cannot be verified and is refused', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make()->preAccepted();
    Cache::forget(LeadCallSdp::answerKey($x->source, Lc::CALL_ID));

    $result = sdhAnswer($x, Lc::answerSdp());

    expect($result->status)->toBe(409)->and($result->errorCode)->toBe('sdp_mismatch');
    expect($log)->toHaveCount(0)->and($x->fresh()->meta_stage)->toBe(CallMetaStage::PreAccepted);
});

test('an invalid SDP after pre_accept is still a 422, checked before any comparison', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make()->preAccepted();

    expect(sdhAnswer($x, 'not an sdp')->status)->toBe(422);
    expect($log)->toHaveCount(0);
});

test('the same rules apply through the HTTP endpoint', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make()->preAccepted();
    $url = route('tenant-lead-calls.answer', $x->call->id);

    $this->actingAs($x->agent)->postJson($url, ['sdp_type' => 'answer', 'sdp' => Lc::answerSdp()])->assertOk();
    $this->actingAs($x->agent)->postJson($url, ['sdp_type' => 'answer', 'sdp' => sdhOtherSdp()])
        ->assertStatus(409)->assertJsonPath('error.code', 'sdp_mismatch');

    expect($log)->toHaveCount(0);
});

test('the first answer still goes through unchanged: pre_accept is sent once and the stage is recorded', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make()->claimed();

    expect(sdhAnswer($x, Lc::answerSdp())->ok)->toBeTrue();
    expect(sdhAnswer($x, Lc::answerSdp())->ok)->toBeTrue();

    expect($log)->toHaveCount(1)->and($log[0]['body']['action'])->toBe('pre_accept');
    expect($x->fresh()->meta_stage)->toBe(CallMetaStage::PreAccepted);
});
