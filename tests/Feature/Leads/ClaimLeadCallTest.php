<?php

use App\Actions\Tenant\ClaimLeadCall;
use App\Actions\Tenant\RecordInboundCall;
use App\Actions\Tenant\RejectLeadCall;
use App\Enums\CallEndReason;
use App\Enums\CallStatus;
use App\Models\TenantUser;
use App\Policies\LeadCallPolicy;
use App\Support\LeadCallActionResult;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\LeadCallScenario as Lc;
use Tests\Support\WhatsAppCallFixtures as Wa;

/*
 * ClaimLeadCall: a routed user atomically takes a Ringing call and is the only
 * one handed its SDP offer. Every refusal leaves the row exactly as it was.
 */

function claimAs(Lc $x, ?TenantUser $user = null, ?string $callId = null): LeadCallActionResult
{
    return app(ClaimLeadCall::class)->handle($user ?? $x->agent, $callId ?? $x->call->id);
}

// ── The winning claim ───────────────────────────────────────────────────────

test('a routed user claims a ringing call and receives the stored offer', function () {
    $x = Lc::make();

    $result = claimAs($x);

    expect($result->ok)->toBeTrue()
        ->and($result->session)->toBe(['sdp_type' => 'offer', 'sdp' => Wa::sdp()])
        ->and($result->call->status)->toBe(CallStatus::Answering);

    $row = $x->fresh();
    expect($row->status)->toBe(CallStatus::Answering)
        ->and($row->claimed_by)->toBe($x->agent->id)
        ->and($row->claimed_at)->not->toBeNull()
        ->and($row->end_reason)->toBeNull()
        ->and($row->meta_stage)->toBeNull();
});

test('claiming makes no Meta request', function () {
    $log = Lc::fakeMeta();
    $x = Lc::make();

    claimAs($x);

    expect($log)->toHaveCount(0);
});

test('the claimant repeating the claim is idempotent and gets the offer again', function () {
    $x = Lc::make();
    claimAs($x);
    $claimedAt = $x->fresh()->claimed_at;

    $again = claimAs($x);

    expect($again->ok)->toBeTrue()
        ->and($again->session['sdp'])->toBe(Wa::sdp())
        ->and($x->fresh()->claimed_by)->toBe($x->agent->id)
        ->and($x->fresh()->claimed_at->equalTo($claimedAt))->toBeTrue();
});

// ── Who may claim ───────────────────────────────────────────────────────────

test('a user outside the routing snapshot cannot claim, admin or not', function (string $who) {
    $x = Lc::make(['routed_user_ids' => []]);
    $user = $who === 'admin' ? $x->admin : $x->agent;

    expect(fn () => claimAs($x, $user))->toThrow(AuthorizationException::class);

    expect($x->fresh()->status)->toBe(CallStatus::Ringing)->and($x->fresh()->claimed_by)->toBeNull();
})->with(['client admin' => ['admin'], 'client user' => ['agent']]);

test('an inactive routed user cannot claim', function () {
    $x = Lc::make();
    DB::table('tenant_users')->where('id', $x->agent->id)->update(['is_active' => false]);

    expect(fn () => claimAs($x, $x->agent->fresh()))->toThrow(AuthorizationException::class);

    expect($x->fresh()->status)->toBe(CallStatus::Ringing);
});

test('a routed user who lost the lead-management update permission cannot claim', function () {
    $x = Lc::make();
    $viewOnly = clientUserWithLeadPermission($x->tenant, ['view']);
    $x->call->update(['routed_user_ids' => [$viewOnly->id]]);

    expect(fn () => claimAs($x, $viewOnly))->toThrow(AuthorizationException::class);

    expect($x->fresh()->status)->toBe(CallStatus::Ringing);
});

test('a user of another tenant cannot see the call at all', function () {
    $x = Lc::make();
    $intruder = Lc::make();
    // Even listed in the routing snapshot, a foreign tenant's user resolves nothing.
    $x->call->update(['routed_user_ids' => [$intruder->agent->id]]);

    $result = claimAs($x, $intruder->agent);

    expect($result->ok)->toBeFalse()->and($result->status)->toBe(404)->and($result->errorCode)->toBe('call_not_found');
    expect($x->fresh()->status)->toBe(CallStatus::Ringing);
});

test('a forged or unknown call id is not found', function () {
    $x = Lc::make();

    $result = claimAs($x, callId: '01ARZ3NDEKTSV4RRFFQ69G5FAV');

    expect($result->status)->toBe(404);
});

test('the policy is exactly: same tenant, active, lead-management update, in the snapshot', function () {
    $x = Lc::make();
    $policy = new LeadCallPolicy;
    $viewOnly = clientUserWithLeadPermission($x->tenant, ['view']);
    $x->call->update(['routed_user_ids' => [$x->agent->id, $viewOnly->id]]);
    $call = $x->fresh();

    expect($policy->answer($x->agent, $call))->toBeTrue()
        ->and($policy->answer($x->other, $call))->toBeFalse()
        ->and($policy->answer($x->admin, $call))->toBeFalse()
        ->and($policy->answer($viewOnly, $call))->toBeFalse();
});

// ── Conflicts ───────────────────────────────────────────────────────────────

test('a second claimant gets a conflict and the first claim stands', function () {
    $x = Lc::make();
    claimAs($x);

    $result = claimAs($x, $x->other);

    expect($result->ok)->toBeFalse()
        ->and($result->status)->toBe(409)
        ->and($result->errorCode)->toBe('already_claimed')
        ->and($result->session)->toBeNull()
        ->and($x->fresh()->claimed_by)->toBe($x->agent->id);
});

test('only a call that is still ringing can be claimed, and a refusal changes nothing', function (string $state) {
    $x = Lc::make(['status' => $state]);
    $before = $x->fresh()->only(['status', 'end_reason', 'claimed_by', 'ended_at']);

    $result = claimAs($x);

    expect($result->status)->toBe(409)->and($result->errorCode)->toBe('call_not_active')->and($result->session)->toBeNull();
    expect($x->fresh()->only(['status', 'end_reason', 'claimed_by', 'ended_at']))->toEqual($before);
})->with(['completed', 'missed', 'rejected', 'failed', 'connected']);

test('an expired ringing call is refused without changing its row', function () {
    $x = Lc::make(['ringing_expires_at' => now()->subSecond()]);

    $result = claimAs($x);

    expect($result->status)->toBe(409)->and($result->errorCode)->toBe('call_expired');
    expect($x->fresh()->status)->toBe(CallStatus::Ringing)->and($x->fresh()->claimed_by)->toBeNull()->and($x->fresh()->ended_at)->toBeNull();
});

test('a call with no expiry time is treated as expired', function () {
    $x = Lc::make(['ringing_expires_at' => null]);

    expect(claimAs($x)->errorCode)->toBe('call_expired');
    expect($x->fresh()->status)->toBe(CallStatus::Ringing);
});

test('the claimant repeating a claim on an expired call is refused unchanged', function () {
    $x = Lc::make()->claimed();
    $x->call->update(['ringing_expires_at' => now()->subSecond()]);

    $result = claimAs($x);

    expect($result->errorCode)->toBe('call_expired')->and($result->session)->toBeNull();
    expect($x->fresh()->status)->toBe(CallStatus::Answering);
});

// ── Source gating (D3) ──────────────────────────────────────────────────────

test('a new claim needs the source to be active and calling enabled', function (array $source) {
    $x = Lc::make(source: $source);

    $result = claimAs($x);

    expect($result->status)->toBe(409)->and($result->errorCode)->toBe('calling_unavailable')->and($result->session)->toBeNull();
    expect($x->fresh()->status)->toBe(CallStatus::Ringing)->and($x->fresh()->claimed_by)->toBeNull();
})->with([
    'calling disabled' => [['calling_enabled' => false]],
    'source inactive' => [['is_active' => false]],
]);

test('switching calling off after a claim does not interrupt the claimant', function () {
    $x = Lc::make();
    claimAs($x);
    $x->source->update(['calling_enabled' => false, 'is_active' => false]);

    $again = claimAs($x);

    expect($again->ok)->toBeTrue()->and($again->session['sdp'])->toBe(Wa::sdp());
});

// ── Missing offer ───────────────────────────────────────────────────────────

test('a missing offer fails the call with sdp_cache_failure and returns no SDP', function () {
    $x = Lc::make(offer: false);

    $result = claimAs($x);

    expect($result->ok)->toBeFalse()->and($result->status)->toBe(409)->and($result->errorCode)->toBe('offer_unavailable')->and($result->session)->toBeNull();

    $row = $x->fresh();
    expect($row->status)->toBe(CallStatus::Failed)
        ->and($row->end_reason)->toBe(CallEndReason::SdpCacheFailure)
        ->and($row->ended_at)->not->toBeNull()
        ->and($row->claimed_by)->toBeNull();
});

test('an offer that is lost after the claim fails the call on the claimant\'s repeat', function () {
    $x = Lc::make();
    claimAs($x);
    Cache::forget(RecordInboundCall::offerCacheKey($x->source, Lc::CALL_ID));

    $result = claimAs($x);

    expect($result->errorCode)->toBe('offer_unavailable')->and($result->session)->toBeNull();
    expect($x->fresh()->status)->toBe(CallStatus::Failed)->and($x->fresh()->end_reason)->toBe(CallEndReason::SdpCacheFailure);
});

test('an undecryptable offer is treated as missing', function () {
    $x = Lc::make(offer: false);
    Cache::put(RecordInboundCall::offerCacheKey($x->source, Lc::CALL_ID), 'not-an-encrypted-payload', 120);

    $result = claimAs($x);

    expect($result->errorCode)->toBe('offer_unavailable');
    expect($x->fresh()->end_reason)->toBe(CallEndReason::SdpCacheFailure);
});

// ── Races (compare-and-set) ─────────────────────────────────────────────────

test('a claim that loses to another claimant between its checks gets a conflict and never overwrites', function () {
    $x = Lc::make();

    // Right after this claim resolves the source, the other agent's claim lands.
    Lc::interleaveAfterQuery('from "lead_sources"', fn () => claimAs($x, $x->other));

    $result = claimAs($x);

    expect($result->ok)->toBeFalse()->and($result->errorCode)->toBe('already_claimed')->and($result->session)->toBeNull();
    expect($x->fresh()->claimed_by)->toBe($x->other->id)->and($x->fresh()->status)->toBe(CallStatus::Answering);
});

test('a claim racing the same claimant\'s own claim is idempotent, not an error', function () {
    $x = Lc::make();

    Lc::interleaveAfterQuery('from "lead_sources"', fn () => claimAs($x));

    $result = claimAs($x);

    expect($result->ok)->toBeTrue()->and($result->session['sdp'])->toBe(Wa::sdp());
    expect($x->fresh()->claimed_by)->toBe($x->agent->id);
});

test('a claim racing a terminate webhook loses to it and does not resurrect the call', function () {
    $x = Lc::make();

    Lc::interleaveAfterQuery('from "lead_sources"', fn () => DB::table('lead_calls')->where('id', $x->call->id)->update([
        'status' => CallStatus::Missed->value,
        'end_reason' => CallEndReason::CallerEndedOrNotAnswered->value,
        'ended_at' => now(),
    ]));

    $result = claimAs($x);

    expect($result->ok)->toBeFalse()->and($result->status)->toBe(409)->and($result->errorCode)->toBe('call_not_active')->and($result->session)->toBeNull();
    expect($x->fresh()->status)->toBe(CallStatus::Missed)->and($x->fresh()->claimed_by)->toBeNull();
});

test('a claim racing a reject loses to it', function () {
    $x = Lc::make();
    Lc::fakeMeta();

    Lc::interleaveAfterQuery('from "lead_sources"', fn () => app(RejectLeadCall::class)->handle($x->other, $x->call->id));

    $result = claimAs($x);

    expect($result->errorCode)->toBe('call_not_active')->and($result->session)->toBeNull();
    expect($x->fresh()->status)->toBe(CallStatus::Rejected)->and($x->fresh()->claimed_by)->toBeNull();
});

test('a missing offer racing another claimant\'s win does not fail their call', function () {
    $x = Lc::make(offer: false);

    Lc::interleaveAfterQuery('from "lead_sources"', fn () => DB::table('lead_calls')->where('id', $x->call->id)->update([
        'status' => CallStatus::Answering->value,
        'claimed_by' => $x->other->id,
    ]));

    $result = claimAs($x);

    expect($result->errorCode)->toBe('already_claimed');
    expect($x->fresh()->status)->toBe(CallStatus::Answering)->and($x->fresh()->claimed_by)->toBe($x->other->id);
});

// ── Isolation and leakage ───────────────────────────────────────────────────

test('claiming another tenant\'s source call cannot reach that tenant\'s credentials or offer', function () {
    $mine = Lc::make();
    $theirs = Lc::make();

    $result = claimAs($theirs, $mine->agent);

    expect($result->status)->toBe(404)->and($result->session)->toBeNull();
    expect($theirs->fresh()->status)->toBe(CallStatus::Ringing);
});

test('a call whose source belongs to another tenant is not claimable', function () {
    $x = Lc::make();
    $foreignSource = Lc::make(['external_call_id' => 'wacid.FOREIGN'])->source;
    DB::table('lead_calls')->where('id', $x->call->id)->update(['lead_source_id' => $foreignSource->id]);

    $result = claimAs($x);

    expect($result->status)->toBe(404);
    expect($x->fresh()->status)->toBe(CallStatus::Ringing);
});

test('claiming logs ids and outcomes but never the SDP, the token or the app secret', function () {
    $logs = Lc::captureLogs();
    $x = Lc::make();

    claimAs($x);
    claimAs($x, $x->other);

    $text = Lc::logText($logs);

    expect($text)->toContain('WA CALL ACTION')->toContain('claimed')->toContain('already_claimed')
        ->not->toContain(Wa::SDP_SENTINEL)
        ->not->toContain(Lc::TOKEN)
        ->not->toContain(Wa::APP_SECRET)
        ->not->toContain('v=0');
});
