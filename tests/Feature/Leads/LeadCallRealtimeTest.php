<?php

use App\Actions\Tenant\AnswerLeadCall;
use App\Actions\Tenant\ClaimLeadCall;
use App\Actions\Tenant\HangupLeadCall;
use App\Actions\Tenant\RecordInboundCall;
use App\Actions\Tenant\RejectLeadCall;
use App\Enums\CallEndReason;
use App\Enums\CallStatus;
use App\Events\LeadCallUpdated;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Models\LeadSource;
use App\Support\LeadCallBroadcaster;
use App\Support\WhatsAppCallEvent;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\LeadCallScenario as Lc;
use Tests\Support\WhatsAppCallFixtures as Wa;

/*
 * Realtime for the softphone: one generic `call.updated` event, id + status
 * only, sent to each routed user's own private channel — and only ever AFTER
 * the transition it announces has succeeded. A broadcast failure can never fail
 * the webhook or the agent action that caused it.
 */

/**
 * @param  array<string, mixed>  $call
 */
function lcrEvent(string $kind, array $call = [], array $valueExtra = []): WhatsAppCallEvent
{
    $item = $kind === 'connect' ? Wa::connect($call) : Wa::terminate($call);
    $event = WhatsAppCallEvent::fromWebhook(Wa::change([$item], $valueExtra), $item);

    expect($event)->toBeInstanceOf(WhatsAppCallEvent::class);

    return $event;
}

/**
 * @return array{tenant: mixed, admin: mixed, source: LeadSource}
 */
function lcrWebhookScenario(): array
{
    ['tenant' => $tenant, 'clientAdmin' => $admin] = tenantWithLeadManagement();

    return ['tenant' => $tenant, 'admin' => $admin, 'source' => Wa::source($tenant)];
}

/**
 * @param  array<string, mixed>  $x
 */
function lcrHandle(array $x, WhatsAppCallEvent $event): string
{
    return app(RecordInboundCall::class)->handle($event, $x['source'], fn (): Lead => Lead::factory()->create([
        'tenant_id' => $x['tenant']->id,
        'source_id' => $x['source']->id,
        'phone' => Wa::CALLER,
    ]), true);
}

function lcrFailOfferCache(): void
{
    $store = new class extends ArrayStore
    {
        public function put($key, $value, $seconds)
        {
            return str_starts_with((string) $key, 'wa-call-offer:') ? false : parent::put($key, $value, $seconds);
        }
    };

    Cache::extend('lcr-failing', fn () => Cache::repository($store));
    config(['cache.stores.lcr-failing' => ['driver' => 'lcr-failing']]);
    Cache::setDefaultDriver('lcr-failing');
}

// ── The event itself ────────────────────────────────────────────────────────

test('the event is a queued broadcast named call.updated whose payload is exactly the id and status', function () {
    $event = new LeadCallUpdated('01CALL', 'ringing', ['user-a', 'user-b']);

    expect($event)->toBeInstanceOf(ShouldBroadcast::class)
        ->and($event->broadcastAs())->toBe('call.updated')
        ->and($event->broadcastWith())->toBe(['id' => '01CALL', 'status' => 'ringing']);
});

test('the event goes to each recipient\'s own private tenant-user channel and nowhere else', function () {
    $channels = (new LeadCallUpdated('01CALL', 'answering', ['user-a', 'user-b', 'user-a']))->broadcastOn();

    expect($channels)->toHaveCount(2)->each->toBeInstanceOf(PrivateChannel::class);
    expect(array_map(fn ($channel): string => $channel->name, $channels))->toBe(['private-tenant-user.user-a', 'private-tenant-user.user-b']);
});

test('a real dispatched event carries no SDP, credential, phone number or Meta data', function () {
    $x = Lc::make();
    Event::fake([LeadCallUpdated::class]);

    app(ClaimLeadCall::class)->handle($x->agent, $x->call->id);

    Event::assertDispatched(LeadCallUpdated::class, function (LeadCallUpdated $event) use ($x): bool {
        $everything = json_encode([$event->broadcastWith(), $event->broadcastAs(), array_map(fn ($c) => $c->name, $event->broadcastOn())]);

        expect($everything)
            ->not->toContain(Wa::SDP_SENTINEL)
            ->not->toContain('v=0')
            ->not->toContain(Lc::TOKEN)
            ->not->toContain(Wa::APP_SECRET)
            ->not->toContain(Wa::PHONE_NUMBER_ID)
            ->not->toContain(Wa::CALLER)
            ->not->toContain(Lc::CALL_ID);

        return array_keys($event->broadcastWith()) === ['id', 'status'] && $event->callId === $x->call->id;
    });
});

test('a call with nobody routed to it announces nothing', function () {
    Event::fake([LeadCallUpdated::class]);
    $x = Lc::make(['routed_user_ids' => []]);

    LeadCallBroadcaster::updated($x->call, CallStatus::Missed);

    Event::assertNotDispatched(LeadCallUpdated::class);
});

// ── Channel authorization (the existing per-user channel) ───────────────────

test('a user may listen only on their own tenant-user channel', function () {
    config(['broadcasting.default' => 'reverb']);
    require base_path('routes/channels.php');
    $x = Lc::make();
    $foreign = Lc::make(['external_call_id' => 'wacid.FOREIGN']);

    $auth = fn ($user, $channelUser) => $this->actingAs($user)->post('/broadcasting/auth', [
        'channel_name' => 'private-tenant-user.'.$channelUser->id,
        'socket_id' => '1234.1234',
    ]);

    $auth($x->agent, $x->agent)->assertOk();
    $auth($x->agent, $x->other)->assertForbidden();
    $auth($x->agent, $x->admin)->assertForbidden();
    $auth($x->agent, $foreign->agent)->assertForbidden();
});

// ── Ringing: only after the offer is cached ─────────────────────────────────

test('a recorded call announces Ringing to its routed users, after its offer is cached', function () {
    $x = lcrWebhookScenario();
    $offerWasCached = null;

    Event::listen(LeadCallUpdated::class, function () use (&$offerWasCached, $x): void {
        $offerWasCached = Cache::has(RecordInboundCall::offerCacheKey($x['source'], 'wacid.CALL-1'));
    });

    $outcome = lcrHandle($x, lcrEvent('connect'));

    expect($outcome)->toBe(RecordInboundCall::OUTCOME_RECORDED)->and($offerWasCached)->toBeTrue();
});

test('the Ringing announcement names the call, its status and exactly its routed users', function () {
    Event::fake([LeadCallUpdated::class]);
    $x = lcrWebhookScenario();

    lcrHandle($x, lcrEvent('connect'));

    $call = LeadCall::firstOrFail();
    Event::assertDispatchedTimes(LeadCallUpdated::class, 1);
    Event::assertDispatched(LeadCallUpdated::class, fn (LeadCallUpdated $e): bool => $e->callId === $call->id
        && $e->status === 'ringing'
        && $e->recipientIds === $call->routed_user_ids
        && $e->recipientIds === [$x['admin']->id]);
});

test('a call whose offer could not be cached never rings anyone', function () {
    Event::fake([LeadCallUpdated::class]);
    lcrFailOfferCache();
    $x = lcrWebhookScenario();

    $outcome = lcrHandle($x, lcrEvent('connect'));

    expect($outcome)->toBe(RecordInboundCall::OUTCOME_OFFER_CACHE_FAILED);
    expect(LeadCall::firstOrFail()->status)->toBe(CallStatus::Failed);
    Event::assertNotDispatched(LeadCallUpdated::class);
});

test('a duplicate connect, a stale connect and a no-recipient call announce nothing', function () {
    Event::fake([LeadCallUpdated::class]);
    $x = lcrWebhookScenario();

    lcrHandle($x, lcrEvent('connect'));
    Event::assertDispatchedTimes(LeadCallUpdated::class, 1);

    lcrHandle($x, lcrEvent('connect'));
    Event::assertDispatchedTimes(LeadCallUpdated::class, 1);

    lcrHandle($x, lcrEvent('connect', ['id' => 'wacid.STALE', 'timestamp' => (string) now()->subMinutes(10)->getTimestamp()]));
    Event::assertDispatchedTimes(LeadCallUpdated::class, 1);

    DB::table('tenant_users')->where('id', $x['admin']->id)->update(['is_active' => false]);
    lcrHandle($x, lcrEvent('connect', ['id' => 'wacid.NOBODY']));
    Event::assertDispatchedTimes(LeadCallUpdated::class, 1);
});

// ── Terminate ───────────────────────────────────────────────────────────────

test('the terminate announcement is sent after the terminal state is committed', function () {
    $x = lcrWebhookScenario();
    lcrHandle($x, lcrEvent('connect'));
    $statusSeen = null;
    $sent = 0;
    $baselineTransactionLevel = DB::transactionLevel();

    Event::listen(LeadCallUpdated::class, function (LeadCallUpdated $event) use (&$statusSeen, &$sent): void {
        $sent++;
        $statusSeen = [$event->status, LeadCall::firstOrFail()->status->value, DB::transactionLevel()];
    });

    lcrHandle($x, lcrEvent('terminate'));

    expect($sent)->toBe(1)->and($statusSeen)->toBe(['missed', 'missed', $baselineTransactionLevel]);
});

test('terminate outcomes that change no status announce nothing', function () {
    $x = lcrWebhookScenario();
    lcrHandle($x, lcrEvent('connect'));
    lcrHandle($x, lcrEvent('terminate'));
    Event::fake([LeadCallUpdated::class]);

    // Redelivered terminate (no-op) and a fill-only terminate for a finished call.
    lcrHandle($x, lcrEvent('terminate'));
    $finished = LeadCall::factory()->completed()->create([
        'tenant_id' => $x['tenant']->id,
        'lead_id' => LeadCall::firstOrFail()->lead_id,
        'lead_source_id' => $x['source']->id,
        'external_call_id' => 'wacid.FINISHED',
        'routed_user_ids' => [$x['admin']->id],
    ]);
    lcrHandle($x, lcrEvent('terminate', ['id' => 'wacid.FINISHED', 'duration' => 30]));

    Event::assertNotDispatched(LeadCallUpdated::class);
    expect($finished->fresh()->status)->toBe(CallStatus::Completed);
});

// ── Agent actions ───────────────────────────────────────────────────────────

test('claim, answer and accept each announce their transition, only after it succeeded', function () {
    Lc::fakeMeta();
    $x = Lc::make();
    Event::fake([LeadCallUpdated::class]);

    app(ClaimLeadCall::class)->handle($x->agent, $x->call->id);
    app(AnswerLeadCall::class)->answer($x->agent, $x->call->id, 'answer', Lc::answerSdp());
    app(AnswerLeadCall::class)->accept($x->agent, $x->call->id);

    $statuses = [];
    Event::assertDispatched(LeadCallUpdated::class, function (LeadCallUpdated $event) use (&$statuses, $x): bool {
        $statuses[] = $event->status;

        return $event->callId === $x->call->id && $event->recipientIds === [$x->agent->id, $x->other->id];
    });
    expect($statuses)->toBe(['answering', 'answering', 'connected']);
});

test('refusals and idempotent repeats announce nothing', function () {
    Lc::fakeMeta();
    $x = Lc::make();
    app(ClaimLeadCall::class)->handle($x->agent, $x->call->id);
    app(AnswerLeadCall::class)->answer($x->agent, $x->call->id, 'answer', Lc::answerSdp());
    app(AnswerLeadCall::class)->accept($x->agent, $x->call->id);
    Event::fake([LeadCallUpdated::class]);

    // repeats by the claimant, and refusals for everyone else
    app(ClaimLeadCall::class)->handle($x->agent, $x->call->id);
    app(AnswerLeadCall::class)->answer($x->agent, $x->call->id, 'answer', Lc::answerSdp());
    app(AnswerLeadCall::class)->accept($x->agent, $x->call->id);
    app(ClaimLeadCall::class)->handle($x->other, $x->call->id);
    app(RejectLeadCall::class)->handle($x->agent, $x->call->id);

    Event::assertNotDispatched(LeadCallUpdated::class);
});

test('reject announces Rejected as soon as the local state is committed, before Meta is asked', function () {
    $x = Lc::make();
    $announcedBeforeMeta = null;

    Event::fake([LeadCallUpdated::class]);
    Lc::fakeMeta(function () use (&$announcedBeforeMeta) {
        $announcedBeforeMeta = Event::dispatched(LeadCallUpdated::class)->count();

        return null;
    });

    app(RejectLeadCall::class)->handle($x->agent, $x->call->id);

    expect($announcedBeforeMeta)->toBe(1);
    Event::assertDispatched(LeadCallUpdated::class, fn (LeadCallUpdated $e): bool => $e->status === 'rejected');
});

test('hangup (Step 7a) announces Completed as soon as the local state is committed, before Meta is asked', function () {
    Lc::fakeMeta();
    $x = Lc::make()->connected();
    $announcedBeforeMeta = null;

    Event::fake([LeadCallUpdated::class]);
    Lc::fakeMeta(function () use (&$announcedBeforeMeta) {
        $announcedBeforeMeta = Event::dispatched(LeadCallUpdated::class)->count();

        return null;
    });

    app(HangupLeadCall::class)->handle($x->agent, $x->call->id);

    expect($announcedBeforeMeta)->toBe(1);
    Event::assertDispatched(LeadCallUpdated::class, fn (LeadCallUpdated $e): bool => $e->status === 'completed' && $e->callId === $x->call->id);
});

test('a hangup refusal or an idempotent repeat announces nothing', function () {
    Lc::fakeMeta();
    $x = Lc::make()->connected();
    app(HangupLeadCall::class)->handle($x->agent, $x->call->id);
    Event::fake([LeadCallUpdated::class]);

    app(HangupLeadCall::class)->handle($x->agent, $x->call->id); // idempotent repeat
    app(HangupLeadCall::class)->handle($x->other, $x->call->id); // not the claimant

    Event::assertNotDispatched(LeadCallUpdated::class);
});

test('a permanent Meta failure or a missing offer announces Failed', function (string $how) {
    Event::fake([LeadCallUpdated::class]);

    if ($how === 'missing offer') {
        $x = Lc::make(offer: false);
        app(ClaimLeadCall::class)->handle($x->agent, $x->call->id);
    } elseif ($how === 'pre_accept failed') {
        Lc::fakeMeta(Http::response(['error' => ['code' => 100]], 400));
        $x = Lc::make()->claimed();
        app(AnswerLeadCall::class)->answer($x->agent, $x->call->id, 'answer', Lc::answerSdp());
    } else {
        Lc::fakeMeta(Http::response(['error' => ['code' => 100]], 400));
        $x = Lc::make()->preAccepted();
        app(AnswerLeadCall::class)->accept($x->agent, $x->call->id);
    }

    expect($x->fresh()->status)->toBe(CallStatus::Failed);
    Event::assertDispatched(LeadCallUpdated::class, fn (LeadCallUpdated $e): bool => $e->status === 'failed' && $e->callId === $x->call->id);
})->with(['missing offer', 'pre_accept failed', 'accept failed']);

test('a retryable Meta failure announces nothing (the call did not change)', function () {
    Lc::fakeMeta(Http::response(['error' => ['code' => 138004]], 500));
    $x = Lc::make()->claimed();
    Event::fake([LeadCallUpdated::class]);

    app(AnswerLeadCall::class)->answer($x->agent, $x->call->id, 'answer', Lc::answerSdp());

    expect($x->fresh()->status)->toBe(CallStatus::Answering)->and($x->fresh()->end_reason)->toBeNull();
    Event::assertNotDispatched(LeadCallUpdated::class);
});

// ── Broadcast failure isolation ─────────────────────────────────────────────

test('a broadcasting failure never fails the webhook or an agent action', function () {
    $logs = Lc::captureLogs();
    Event::listen(LeadCallUpdated::class, fn () => throw new RuntimeException('reverb is down '.Lc::TOKEN));

    $x = lcrWebhookScenario();
    expect(lcrHandle($x, lcrEvent('connect')))->toBe(RecordInboundCall::OUTCOME_RECORDED);
    expect(lcrHandle($x, lcrEvent('terminate')))->toBe(RecordInboundCall::OUTCOME_TERMINATE_APPLIED);

    Lc::fakeMeta();
    $y = Lc::make(['external_call_id' => 'wacid.ACTIONS']);
    $claim = app(ClaimLeadCall::class)->handle($y->agent, $y->call->id);
    expect($claim->ok)->toBeTrue()->and($y->fresh()->status)->toBe(CallStatus::Answering);
    expect(app(RejectLeadCall::class)->handle($y->agent, $y->call->id)->ok)->toBeTrue();
    expect($y->fresh()->end_reason)->toBe(CallEndReason::AgentCancelledDuringAnswer);

    $text = Lc::logText($logs);
    expect($text)->toContain('WA CALL BROADCAST FAILED')->not->toContain(Lc::TOKEN)->not->toContain('reverb is down');
});
