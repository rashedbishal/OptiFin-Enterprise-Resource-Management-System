<?php

use App\Enums\CallStatus;
use App\Http\Controllers\LeadCallController;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Support\LeadCallScenario as Lc;
use Tests\Support\WhatsAppCallFixtures as Wa;

/*
 * The four JSON routes over the claim / answer / accept / reject actions: route
 * shape, response and error structure, the browser-supplied id being the only
 * identifier honoured, and the fail-open throttle.
 */

function lceUrl(string $action, string $callId): string
{
    return route("tenant-lead-calls.{$action}", $callId);
}

// ── Routes ──────────────────────────────────────────────────────────────────

test('the four call routes exist with the approved names, paths and POST-only methods', function () {
    foreach (['claim', 'answer', 'accept', 'reject'] as $action) {
        $route = Route::getRoutes()->getByName("tenant-lead-calls.{$action}");

        expect($route)->not->toBeNull()
            ->and($route->uri())->toBe("lead-management/calls/{leadCall}/{$action}")
            ->and($route->methods())->toBe(['POST'])
            ->and($route->getActionName())->toBe(LeadCallController::class.'@'.$action)
            ->and($route->gatherMiddleware())->toContain('auth')->toContain('module:lead-management')->toContain('throttle.fail-open:lead-call-actions');
    }
});

test('a GET is not allowed', function () {
    $x = Lc::make();

    $this->actingAs($x->agent)->get(lceUrl('claim', $x->call->id))->assertStatus(405);
});

test('a guest is turned away', function () {
    $x = Lc::make();

    $this->postJson(lceUrl('claim', $x->call->id))->assertUnauthorized();
    expect($x->fresh()->status)->toBe(CallStatus::Ringing);
});

// ── The happy path through HTTP ─────────────────────────────────────────────

test('claim returns the call and the offer, uncacheable, to the claimant only', function () {
    $x = Lc::make();

    $response = $this->actingAs($x->agent)->postJson(lceUrl('claim', $x->call->id));

    $response->assertOk()
        ->assertExactJson(['data' => [
            'call' => ['id' => $x->call->id, 'status' => 'answering', 'claimed_by_me' => true],
            'session' => ['sdp_type' => 'offer', 'sdp' => Wa::sdp()],
        ]]);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    $second = $this->actingAs($x->other)->postJson(lceUrl('claim', $x->call->id));

    $second->assertStatus(409)->assertExactJson(['error' => [
        'code' => 'already_claimed',
        'message' => 'Another agent is already answering this call.',
        'retryable' => false,
    ]]);
    expect($second->getContent())->not->toContain(Wa::SDP_SENTINEL);
});

test('claim, answer, accept take a call all the way to connected and never return an SDP after the claim', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make();

    $this->actingAs($x->agent)->postJson(lceUrl('claim', $x->call->id))->assertOk();

    $answer = $this->actingAs($x->agent)->postJson(lceUrl('answer', $x->call->id), ['sdp_type' => 'answer', 'sdp' => Lc::answerSdp()]);
    $answer->assertOk()->assertExactJson(['data' => ['call' => ['id' => $x->call->id, 'status' => 'answering', 'claimed_by_me' => true]]]);

    $accept = $this->actingAs($x->agent)->postJson(lceUrl('accept', $x->call->id));
    $accept->assertOk()->assertExactJson(['data' => ['call' => ['id' => $x->call->id, 'status' => 'connected', 'claimed_by_me' => true]]]);

    expect($answer->getContent().$accept->getContent())->not->toContain(Lc::ANSWER_SENTINEL)->not->toContain('v=0');
    expect($log)->toHaveCount(2)->and($x->fresh()->status)->toBe(CallStatus::Connected);
});

test('reject returns the rejected call and whether Meta confirmed it', function () {
    Lc::fakeMeta();
    $x = Lc::make();

    $this->actingAs($x->agent)->postJson(lceUrl('reject', $x->call->id))
        ->assertOk()
        ->assertExactJson(['data' => [
            'call' => ['id' => $x->call->id, 'status' => 'rejected', 'claimed_by_me' => false],
            'meta_confirmed' => true,
        ]]);

    Lc::fakeMeta(Http::response([], 500));
    $y = Lc::make(['external_call_id' => 'wacid.SECOND']);

    $this->actingAs($y->agent)->postJson(lceUrl('reject', $y->call->id))->assertOk()->assertJsonPath('data.meta_confirmed', false);
});

// ── Only the call id is honoured ────────────────────────────────────────────

test('answer ignores any phone number id, token, tenant, lead or source in the body', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make()->claimed();
    $foreign = Lc::make(['external_call_id' => 'wacid.FOREIGN']);

    $this->actingAs($x->agent)->postJson(lceUrl('answer', $x->call->id), [
        'sdp_type' => 'answer',
        'sdp' => Lc::answerSdp(),
        'phone_number_id' => '999000111',
        'access_token' => 'attacker-token',
        'app_secret' => 'attacker-secret',
        'tenant_id' => $foreign->tenant->id,
        'lead_id' => $foreign->lead->id,
        'lead_source_id' => $foreign->source->id,
        'call_id' => 'wacid.FOREIGN',
        'action' => 'terminate',
    ])->assertOk();

    expect($log)->toHaveCount(1);
    expect($log[0]['url'])->toBe('https://graph.facebook.com/v25.0/'.Wa::PHONE_NUMBER_ID.'/calls')
        ->and($log[0]['authorization'])->toBe('Bearer '.Lc::TOKEN)
        ->and($log[0]['body']['call_id'])->toBe(Lc::CALL_ID)
        ->and($log[0]['body']['action'])->toBe('pre_accept')
        ->and(json_encode($log[0]['body']))->not->toContain('attacker');
});

test('accept and reject ignore any body', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make()->preAccepted();

    $this->actingAs($x->agent)->postJson(lceUrl('accept', $x->call->id), ['sdp' => 'v=0 attacker', 'sdp_type' => 'answer', 'access_token' => 'attacker-token'])->assertOk();

    expect($log[0]['body']['session']['sdp'])->toBe(Lc::answerSdp())->and($log[0]['authorization'])->toBe('Bearer '.Lc::TOKEN);
});

test('an answer SDP sent over HTTP keeps its interior line breaks and gets exactly one final CRLF back', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make()->claimed();

    // A JSON body ending in CRLF is trimmed by Laravel before the action sees it.
    $this->actingAs($x->agent)->postJson(lceUrl('answer', $x->call->id), ['sdp_type' => 'answer', 'sdp' => Lc::answerSdp()])->assertOk();

    expect($log[0]['body']['session']['sdp'])->toBe(Lc::answerSdp())->toEndWith("a=setup:active\r\n")->not->toEndWith("\r\n\r\n");
});

// ── Errors ──────────────────────────────────────────────────────────────────

test('every refusal uses the error structure and the approved status', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make(['routed_user_ids' => []]);
    $mine = Lc::make(['external_call_id' => 'wacid.MINE']);

    // 404: forged id, and another tenant's call
    $this->actingAs($mine->agent)->postJson(lceUrl('claim', '01ARZ3NDEKTSV4RRFFQ69G5FAV'))
        ->assertNotFound()->assertJsonPath('error.code', 'call_not_found')->assertJsonPath('error.retryable', false);
    $this->actingAs($mine->agent)->postJson(lceUrl('claim', $x->call->id))->assertNotFound();

    // 403: in the tenant, but not routed
    $this->actingAs($x->admin)->postJson(lceUrl('claim', $x->call->id))
        ->assertForbidden()->assertJsonPath('error.code', 'forbidden');

    // 409: not claimed yet
    $this->actingAs($mine->agent)->postJson(lceUrl('answer', $mine->call->id), ['sdp_type' => 'answer', 'sdp' => Lc::answerSdp()])
        ->assertStatus(409)->assertJsonPath('error.code', 'call_not_claimed');

    // 422: bad SDP from the claimant
    $mine->claimed();
    $this->actingAs($mine->agent)->postJson(lceUrl('answer', $mine->call->id), ['sdp_type' => 'answer', 'sdp' => 'nonsense'])
        ->assertStatus(422)->assertJsonPath('error.code', 'invalid_sdp');

    expect($log)->toHaveCount(0);
    expect($x->fresh()->status)->toBe(CallStatus::Ringing);
});

test('a Meta failure reaches the browser as a safe error with a retryable flag and no Meta text or token', function (mixed $failure, int $status, bool $retryable) {
    Lc::fakeMeta($failure);
    $x = Lc::make()->claimed();

    $response = $this->actingAs($x->agent)->postJson(lceUrl('answer', $x->call->id), ['sdp_type' => 'answer', 'sdp' => Lc::answerSdp()]);

    $response->assertStatus($status)->assertJsonPath('error.retryable', $retryable);
    expect($response->getContent())
        ->not->toContain('RAW META WORDING')
        ->not->toContain(Lc::TOKEN)
        ->not->toContain(Wa::APP_SECRET)
        ->not->toContain(Lc::ANSWER_SENTINEL);
})->with([
    'timeout' => [new ConnectionException('cURL error 28'), 503, true],
    'transient' => [fn (Request $r) => Http::response(['error' => ['code' => 138004, 'message' => 'RAW META WORDING '.Lc::TOKEN]], 500), 503, true],
    'permanent' => [fn (Request $r) => Http::response(['error' => ['code' => 100, 'message' => 'RAW META WORDING '.Lc::TOKEN]], 400), 502, false],
]);

// ── Throttle ────────────────────────────────────────────────────────────────

test('a runaway client is throttled per user at 60 requests a minute', function () {
    $x = Lc::make();
    $other = Lc::make(['external_call_id' => 'wacid.OTHER-USER']);

    for ($i = 0; $i < 60; $i++) {
        $this->actingAs($x->agent)->postJson(lceUrl('claim', '01ARZ3NDEKTSV4RRFFQ69G5FAV'))->assertNotFound();
    }

    $this->actingAs($x->agent)->postJson(lceUrl('claim', '01ARZ3NDEKTSV4RRFFQ69G5FAV'))->assertStatus(429);

    // Another user is not affected.
    $this->actingAs($other->agent)->postJson(lceUrl('claim', $other->call->id))->assertOk();
});
