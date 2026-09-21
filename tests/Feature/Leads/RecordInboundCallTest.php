<?php

use App\Actions\Tenant\RecordInboundCall;
use App\Enums\CallEndReason;
use App\Enums\CallRoutingMode;
use App\Enums\CallStatus;
use App\Enums\LeadSourceType;
use App\Enums\MessageChannel;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Models\LeadSource;
use App\Models\Message;
use App\Services\LeadDeletionEligibilityService;
use App\Support\WhatsAppCallEvent;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\WhatsAppCallFixtures as Wa;

/*
 * WhatsApp inbound calling, Step 2 — RecordInboundCall is the single writer of
 * lead_calls for the inbound `connect` and `terminate` webhooks. These tests
 * drive the action directly (the controller's gates are covered in
 * WhatsappCallWebhookTest); the "create an unknown caller's Lead" closure is
 * injected, exactly as the controller injects its own createLead().
 */

/**
 * @return array{tenant: mixed, admin: mixed, source: LeadSource}
 */
function ricScenario(): array
{
    ['tenant' => $tenant, 'clientAdmin' => $admin] = tenantWithLeadManagement();

    return ['tenant' => $tenant, 'admin' => $admin, 'source' => Wa::source($tenant)];
}

/**
 * @param  array<string, mixed>  $call
 * @param  array<string, mixed>  $valueExtra
 */
function ricConnect(array $call = [], array $valueExtra = []): WhatsAppCallEvent
{
    $item = Wa::connect($call);
    $event = WhatsAppCallEvent::fromWebhook(Wa::change([$item], $valueExtra), $item);

    expect($event)->toBeInstanceOf(WhatsAppCallEvent::class);

    return $event;
}

/**
 * @param  array<string, mixed>  $call
 * @param  array<string, mixed>  $valueExtra
 */
function ricTerminate(array $call = [], array $valueExtra = []): WhatsAppCallEvent
{
    $item = Wa::terminate($call);
    $event = WhatsAppCallEvent::fromWebhook(Wa::change([$item], $valueExtra), $item);

    expect($event)->toBeInstanceOf(WhatsAppCallEvent::class);

    return $event;
}

/**
 * The closure the controller injects for an unknown caller — here a factory
 * lead, counting how many times it was asked to create one.
 *
 * @param  array<string, mixed>  $x
 */
function ricCreator(array $x, ArrayObject $counter, ?Closure $afterCreate = null): Closure
{
    return function () use ($x, $counter, $afterCreate): Lead {
        $counter['n'] = $counter['n'] + 1;

        $lead = Lead::factory()->create([
            'tenant_id' => $x['tenant']->id,
            'source_id' => $x['source']->id,
            'phone' => Wa::CALLER,
        ]);

        return $afterCreate !== null ? $afterCreate($lead) : $lead;
    };
}

/**
 * @param  array<string, mixed>  $x
 */
function ricHandle(array $x, WhatsAppCallEvent $event, ?ArrayObject $counter = null, ?Closure $creator = null, bool $acceptNewCalls = true): string
{
    $counter ??= new ArrayObject(['n' => 0]);

    return app(RecordInboundCall::class)->handle($event, $x['source'], $creator ?? ricCreator($x, $counter), $acceptNewCalls);
}

/**
 * @param  array<string, mixed>  $x
 * @param  array<string, mixed>  $attributes
 */
function ricKnownLead(array $x, array $attributes = []): Lead
{
    return Lead::factory()->create(array_merge([
        'tenant_id' => $x['tenant']->id,
        'source_id' => $x['source']->id,
        'phone' => Wa::CALLER,
    ], $attributes));
}

function ricOfferKey(LeadSource $source, string $callId = 'wacid.CALL-1'): string
{
    return RecordInboundCall::offerCacheKey($source, $callId);
}

/**
 * Swaps the default cache for an array store whose put() fails (returns false,
 * or throws) for the first $failures writes to keys starting with $prefix —
 * every other write, and every read, behaves normally. attemptsFor() counts the
 * writes made to a given key prefix.
 */
function ricFailingCache(int $failures, bool $throws = false, string $prefix = 'wa-call-offer:'): object
{
    $store = new class($failures, $throws, $prefix) extends ArrayStore
    {
        /** @var array<int, string> */
        public array $attemptedKeys = [];

        public function __construct(private int $remaining, private bool $throws, private string $prefix)
        {
            parent::__construct();
        }

        public function attemptsFor(string $prefix): int
        {
            return count(array_filter($this->attemptedKeys, fn (string $key): bool => str_starts_with($key, $prefix)));
        }

        public function put($key, $value, $seconds)
        {
            $this->attemptedKeys[] = (string) $key;

            if ($this->remaining > 0 && str_starts_with((string) $key, $this->prefix)) {
                $this->remaining--;

                if ($this->throws) {
                    throw new RuntimeException('cache is down');
                }

                return false;
            }

            return parent::put($key, $value, $seconds);
        }
    };

    Cache::extend('ric-failing', fn () => Cache::repository($store));
    config(['cache.stores.ric-failing' => ['driver' => 'ric-failing']]);
    Cache::setDefaultDriver('ric-failing');

    return $store;
}

/**
 * Swaps the default cache for an array store whose reads throw, to prove the
 * seen-call-id marker (and every other cache read) fails open.
 */
function ricUnreadableCache(): void
{
    $store = new class extends ArrayStore
    {
        public function get($key)
        {
            throw new RuntimeException('cache is unreadable');
        }
    };

    Cache::extend('ric-unreadable', fn () => Cache::repository($store));
    config(['cache.stores.ric-unreadable' => ['driver' => 'ric-unreadable']]);
    Cache::setDefaultDriver('ric-unreadable');
}

// ── CONNECT: lead resolution ────────────────────────────────────────────────

test('a known lead is reused: no lead is created and the call attaches to it', function () {
    $x = ricScenario();
    $lead = ricKnownLead($x);
    $counter = new ArrayObject(['n' => 0]);

    $outcome = ricHandle($x, ricConnect(), $counter);

    expect($outcome)->toBe(RecordInboundCall::OUTCOME_RECORDED);
    expect($counter['n'])->toBe(0);
    expect(Lead::count())->toBe(1);
    expect(LeadCall::sole()->lead_id)->toBe($lead->id);
});

test('an unknown caller creates exactly one lead through the injected creator', function () {
    $x = ricScenario();
    $counter = new ArrayObject(['n' => 0]);

    $outcome = ricHandle($x, ricConnect(), $counter);

    expect($outcome)->toBe(RecordInboundCall::OUTCOME_RECORDED);
    expect($counter['n'])->toBe(1);
    expect(Lead::count())->toBe(1);
    expect(LeadCall::sole()->lead_id)->toBe(Lead::sole()->id);
});

test('a lead reachable only through its conversation (a merge survivor) is found and reused', function () {
    $x = ricScenario();
    $survivor = Lead::factory()->create(['tenant_id' => $x['tenant']->id, 'source_id' => $x['source']->id, 'phone' => null]);
    Conversation::factory()->create([
        'tenant_id' => $x['tenant']->id,
        'lead_id' => $survivor->id,
        'lead_source_id' => $x['source']->id,
        'channel' => MessageChannel::Whatsapp,
        'external_thread_id' => Wa::CALLER,
    ]);
    $counter = new ArrayObject(['n' => 0]);

    ricHandle($x, ricConnect(), $counter);

    expect($counter['n'])->toBe(0);
    expect(LeadCall::sole()->lead_id)->toBe($survivor->id);
    expect(Conversation::count())->toBe(1);
});

test('a merged-away lead with the same phone is never matched by the phone lookup', function () {
    $x = ricScenario();
    $survivor = Lead::factory()->create(['tenant_id' => $x['tenant']->id, 'source_id' => $x['source']->id, 'phone' => '15557770000']);
    ricKnownLead($x, ['merged_into_lead_id' => $survivor->id]);
    $counter = new ArrayObject(['n' => 0]);

    ricHandle($x, ricConnect(), $counter);

    expect($counter['n'])->toBe(1);
});

// ── CONNECT: the recorded row ───────────────────────────────────────────────

test('a valid connect records a Ringing row with every Step 1 field set as approved', function () {
    $this->freezeSecond();
    $x = ricScenario();
    $lead = ricKnownLead($x);

    ricHandle($x, ricConnect());

    $call = LeadCall::sole();
    $conversation = Conversation::sole();

    expect($call->tenant_id)->toBe($x['tenant']->id);
    expect($call->lead_id)->toBe($lead->id);
    expect($call->lead_source_id)->toBe($x['source']->id);
    expect($call->conversation_id)->toBe($conversation->id);
    expect($call->external_call_id)->toBe('wacid.CALL-1');
    expect($call->status)->toBe(CallStatus::Ringing);
    expect($call->end_reason)->toBeNull();
    expect($call->meta_stage)->toBeNull();
    expect($call->meta_status)->toBeNull();
    expect($call->meta_terminated_at)->toBeNull();
    expect($call->claimed_by)->toBeNull();
    expect($call->claimed_at)->toBeNull();
    expect($call->connected_at)->toBeNull();
    expect($call->ended_at)->toBeNull();
    expect($call->duration_seconds)->toBeNull();
    expect($call->ringing_expires_at->getTimestamp())->toBe(Carbon::now()->getTimestamp() + 60);
});

test('the routing snapshot for an unassigned known lead is every active Client Admin', function () {
    $x = ricScenario();
    ricKnownLead($x);

    ricHandle($x, ricConnect());

    $call = LeadCall::sole();

    expect($call->routing_mode)->toBe(CallRoutingMode::AdminFallback);
    expect($call->routed_user_ids)->toBe([$x['admin']->id]);
    expect($call->assignee_id_at_arrival)->toBeNull();
});

test('the routing snapshot for an assigned known lead is that assignee alone', function () {
    $x = ricScenario();
    $assignee = clientUserWithLeadPermission($x['tenant'], ['view', 'update']);
    ricKnownLead($x, ['assigned_to' => $assignee->id]);

    ricHandle($x, ricConnect());

    $call = LeadCall::sole();

    expect($call->routing_mode)->toBe(CallRoutingMode::Assignee);
    expect($call->routed_user_ids)->toBe([$assignee->id]);
    expect($call->assignee_id_at_arrival)->toBe($assignee->id);
});

test('a lead created by this call routes to the admins even if the queue already assigned it', function () {
    $x = ricScenario();
    $assignee = clientUserWithLeadPermission($x['tenant'], ['view', 'update']);
    $creator = ricCreator($x, new ArrayObject(['n' => 0]), fn (Lead $lead): Lead => tap($lead)->update(['assigned_to' => $assignee->id]));

    ricHandle($x, ricConnect(), creator: $creator);

    $call = LeadCall::sole();

    expect($call->routing_mode)->toBe(CallRoutingMode::AdminFallback);
    expect($call->routed_user_ids)->toBe([$x['admin']->id]);
});

test('the routing snapshot stays frozen when the lead is reassigned later, and only a later call sees the change', function () {
    $x = ricScenario();
    $lead = ricKnownLead($x);
    ricHandle($x, ricConnect());
    $frozen = LeadCall::sole()->only(['routing_mode', 'routed_user_ids', 'assignee_id_at_arrival']);

    $assignee = clientUserWithLeadPermission($x['tenant'], ['view', 'update']);
    $lead->update(['assigned_to' => $assignee->id]);
    ricHandle($x, ricConnect(['id' => 'wacid.CALL-2']));

    expect(LeadCall::where('external_call_id', 'wacid.CALL-1')->sole()->only(['routing_mode', 'routed_user_ids', 'assignee_id_at_arrival']))->toEqual($frozen);
    expect(LeadCall::where('external_call_id', 'wacid.CALL-2')->sole()->routing_mode)->toBe(CallRoutingMode::Assignee);
});

test('with nobody able to take the call it is recorded as Missed (no_recipient), never rings, and caches no offer', function () {
    $x = ricScenario();
    ricKnownLead($x);
    DB::table('tenant_users')->where('id', $x['admin']->id)->update(['is_active' => false]);

    $outcome = ricHandle($x, ricConnect());

    $call = LeadCall::sole();

    expect($outcome)->toBe(RecordInboundCall::OUTCOME_RECORDED_NO_RECIPIENT);
    expect($call->status)->toBe(CallStatus::Missed);
    expect($call->end_reason)->toBe(CallEndReason::NoRecipient);
    expect($call->routing_mode)->toBe(CallRoutingMode::None);
    expect($call->routed_user_ids)->toBe([]);
    expect($call->ringing_expires_at)->toBeNull();
    expect($call->ended_at)->not->toBeNull();
    expect(Cache::has(ricOfferKey($x['source'])))->toBeFalse();
});

// ── CONNECT: the conversation ───────────────────────────────────────────────

test('a connect creates the whatsapp conversation keyed by the caller and never a message', function () {
    $x = ricScenario();
    $lead = ricKnownLead($x);

    ricHandle($x, ricConnect());

    $conversation = Conversation::sole();

    expect($conversation->tenant_id)->toBe($x['tenant']->id);
    expect($conversation->lead_id)->toBe($lead->id);
    expect($conversation->lead_source_id)->toBe($x['source']->id);
    expect($conversation->channel)->toBe(MessageChannel::Whatsapp);
    expect($conversation->external_thread_id)->toBe(Wa::CALLER);
    expect($conversation->last_inbound_at)->not->toBeNull();
    expect(Message::count())->toBe(0);
});

test('an existing conversation is reused and its last_inbound_at only moves forward', function (int $existingOffsetSeconds, bool $expectMoved) {
    $this->freezeSecond();
    $x = ricScenario();
    $lead = ricKnownLead($x);
    $existing = Carbon::now()->addSeconds($existingOffsetSeconds);
    $conversation = Conversation::factory()->create([
        'tenant_id' => $x['tenant']->id,
        'lead_id' => $lead->id,
        'lead_source_id' => $x['source']->id,
        'channel' => MessageChannel::Whatsapp,
        'external_thread_id' => Wa::CALLER,
        'last_inbound_at' => $existing,
    ]);

    ricHandle($x, ricConnect());

    expect(Conversation::count())->toBe(1);
    expect($conversation->fresh()->last_inbound_at->getTimestamp())
        ->toBe($expectMoved ? Carbon::now()->getTimestamp() : $existing->getTimestamp());
})->with([
    'older inbound moves forward to the call' => [-7200, true],
    'a newer inbound is never rewound' => [3600, false],
]);

test('a call-only conversation leaves the lead message timeline empty', function () {
    $x = ricScenario();
    $lead = ricKnownLead($x);

    ricHandle($x, ricConnect());

    expect(Conversation::count())->toBe(1);
    expect($lead->fresh()->messageTimeline())->toBe([]);
});

// ── CONNECT: the encrypted offer cache ──────────────────────────────────────

test('the SDP offer is cached encrypted under the tenant- and source-scoped key', function () {
    $x = ricScenario();
    ricKnownLead($x);

    ricHandle($x, ricConnect());

    $key = ricOfferKey($x['source']);
    $stored = Cache::get($key);

    expect($key)->toBe("wa-call-offer:{$x['tenant']->id}:{$x['source']->id}:wacid.CALL-1");
    expect($stored)->toBeString()->not->toContain(Wa::SDP_SENTINEL);
    expect(Crypt::decryptString($stored))->toBe(Wa::sdp());
});

test('the cached offer expires after offer_ttl_seconds', function () {
    $x = ricScenario();
    ricKnownLead($x);

    ricHandle($x, ricConnect());

    $this->travel(config('calling.offer_ttl_seconds') - 1)->seconds();
    expect(Cache::has(ricOfferKey($x['source'])))->toBeTrue();

    $this->travel(2)->seconds();
    expect(Cache::has(ricOfferKey($x['source'])))->toBeFalse();
});

test('the same call id under a different source or tenant gets its own cache entry', function () {
    $x = ricScenario();
    $y = ricScenario();

    expect(ricOfferKey($x['source']))->not->toBe(ricOfferKey($y['source']));
});

test('a cache write that fails twice fails the call as sdp_cache_failure without creating anything extra', function (bool $throws) {
    $store = ricFailingCache(PHP_INT_MAX, $throws);
    $x = ricScenario();
    ricKnownLead($x);

    $outcome = ricHandle($x, ricConnect());

    $call = LeadCall::sole();

    expect($outcome)->toBe(RecordInboundCall::OUTCOME_OFFER_CACHE_FAILED);
    expect($store->attemptsFor('wa-call-offer:'))->toBe(2);
    expect($call->status)->toBe(CallStatus::Failed);
    expect($call->end_reason)->toBe(CallEndReason::SdpCacheFailure);
    expect($call->ended_at)->not->toBeNull();
    expect(Lead::count())->toBe(1);
    expect(LeadCall::count())->toBe(1);
})->with(['put returns false' => [false], 'put throws' => [true]]);

test('a cache write that fails once is retried and the call still rings', function () {
    $store = ricFailingCache(1);
    $x = ricScenario();
    ricKnownLead($x);

    $outcome = ricHandle($x, ricConnect());

    expect($outcome)->toBe(RecordInboundCall::OUTCOME_RECORDED);
    expect($store->attemptsFor('wa-call-offer:'))->toBe(2);
    expect(LeadCall::sole()->status)->toBe(CallStatus::Ringing);
    expect(Cache::has(ricOfferKey($x['source'])))->toBeTrue();
});

test('the SDP is never persisted on any row', function () {
    $x = ricScenario();

    ricHandle($x, ricConnect());

    foreach (['lead_calls', 'leads', 'conversations', 'messages', 'lead_sources'] as $table) {
        expect(json_encode(DB::table($table)->get()))->not->toContain(Wa::SDP_SENTINEL);
    }

    expect(Lead::sole()->source_data)->toBeNull();
    expect(array_keys(LeadCall::sole()->getAttributes()))->not->toContain('sdp');
});

// ── CONNECT: idempotency ────────────────────────────────────────────────────

test('a duplicate connect is a no-op that never modifies the existing row', function () {
    $x = ricScenario();
    ricKnownLead($x);
    ricHandle($x, ricConnect());
    $before = LeadCall::sole()->getAttributes();

    $outcome = ricHandle($x, ricConnect());

    expect($outcome)->toBe(RecordInboundCall::OUTCOME_DUPLICATE);
    expect(LeadCall::count())->toBe(1);
    expect(Conversation::count())->toBe(1);
    expect(LeadCall::sole()->getAttributes())->toEqual($before);
});

test('a duplicate connect for a new caller does not create a second lead', function () {
    $x = ricScenario();
    $counter = new ArrayObject(['n' => 0]);

    ricHandle($x, ricConnect(), $counter);
    ricHandle($x, ricConnect(), $counter);
    ricHandle($x, ricConnect(), $counter);

    expect($counter['n'])->toBe(1);
    expect(Lead::count())->toBe(1);
    expect(LeadCall::count())->toBe(1);
});

test('a duplicate connect repairs a missing offer while the call is still ringing', function () {
    $x = ricScenario();
    ricKnownLead($x);
    ricHandle($x, ricConnect());
    Cache::forget(ricOfferKey($x['source']));

    $outcome = ricHandle($x, ricConnect());

    expect($outcome)->toBe(RecordInboundCall::OUTCOME_DUPLICATE);
    expect(Cache::has(ricOfferKey($x['source'])))->toBeTrue();
    expect(LeadCall::sole()->status)->toBe(CallStatus::Ringing);
});

test('a duplicate connect does not resurrect an offer for a call whose ring window has passed', function () {
    $x = ricScenario();
    ricKnownLead($x);
    ricHandle($x, ricConnect());
    Cache::forget(ricOfferKey($x['source']));

    $this->travel(config('calling.ring_ttl_seconds') + 1)->seconds();
    ricHandle($x, ricConnect());

    expect(Cache::has(ricOfferKey($x['source'])))->toBeFalse();
});

test('a duplicate call id naming a different caller is reported as a conflict and changes nothing', function () {
    $x = ricScenario();
    ricKnownLead($x);
    $counter = new ArrayObject(['n' => 0]);
    ricHandle($x, ricConnect(), $counter);
    $before = LeadCall::sole()->getAttributes();

    $outcome = ricHandle($x, ricConnect(['from' => '15559990000'], ['contacts' => []]), $counter);

    expect($outcome)->toBe(RecordInboundCall::OUTCOME_CONFLICT);
    expect($counter['n'])->toBe(0);
    expect(Lead::count())->toBe(1);
    expect(LeadCall::count())->toBe(1);
    expect(LeadCall::sole()->getAttributes())->toEqual($before);
});

test('losing a race on the unique call identity is treated as a duplicate, never a second row', function () {
    $x = ricScenario();

    // A competing delivery commits its own row for the same call id in the
    // window between our duplicate re-check and our insert — simulated here in
    // the lead creator, which runs exactly in that window for a new caller.
    $creator = ricCreator($x, new ArrayObject(['n' => 0]), function (Lead $lead) use ($x): Lead {
        DB::table('lead_calls')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $x['tenant']->id,
            'lead_id' => $lead->id,
            'lead_source_id' => $x['source']->id,
            'external_call_id' => 'wacid.CALL-1',
            'status' => CallStatus::Ringing->value,
            'routing_mode' => CallRoutingMode::AdminFallback->value,
            'routed_user_ids' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $lead;
    });

    $outcome = ricHandle($x, ricConnect(), creator: $creator);

    expect($outcome)->toBe(RecordInboundCall::OUTCOME_DUPLICATE);
    expect(LeadCall::count())->toBe(1);
    expect(Lead::count())->toBe(1);
    expect(Conversation::count())->toBe(0);
});

test('the call id is unique per source, so the same id under two sources is two calls', function () {
    $x = ricScenario();
    $otherSource = Wa::source($x['tenant'], ['name' => 'Second WhatsApp number']);
    ricKnownLead($x);
    ricKnownLead(['tenant' => $x['tenant'], 'source' => $otherSource]);

    ricHandle($x, ricConnect());
    app(RecordInboundCall::class)->handle(ricConnect(), $otherSource, fn (): Lead => throw new LogicException('no lead should be created'));

    expect(LeadCall::count())->toBe(2);
    expect(LeadCall::where('external_call_id', 'wacid.CALL-1')->distinct()->count('lead_source_id'))->toBe(2);
});

test('the action serializes on the very lock the whatsapp message path uses for a phone number', function () {
    $x = ricScenario();
    ricKnownLead($x);
    $held = Cache::lock("lead-webhook-dedupe:{$x['source']->id}:".Wa::CALLER, 30);
    expect($held->get())->toBeTrue();

    // Blocks for the message path's own 5 second wait, then gives up.
    expect(fn () => ricHandle($x, ricConnect()))->toThrow(LockTimeoutException::class);

    expect(LeadCall::count())->toBe(0);

    $held->release();
    expect(ricHandle($x, ricConnect()))->toBe(RecordInboundCall::OUTCOME_RECORDED);
});

// ── CONNECT: stale delivery ─────────────────────────────────────────────────

test('a stale connect for a known caller is recorded as Missed (terminate_before_connect) without ringing', function () {
    $this->freezeSecond();
    $x = ricScenario();
    ricKnownLead($x);
    $eventTime = Carbon::now()->getTimestamp() - 300;

    $outcome = ricHandle($x, ricConnect(['timestamp' => (string) $eventTime]));

    $call = LeadCall::sole();

    expect($outcome)->toBe(RecordInboundCall::OUTCOME_RECORDED_STALE);
    expect($call->status)->toBe(CallStatus::Missed);
    expect($call->end_reason)->toBe(CallEndReason::TerminateBeforeConnect);
    expect($call->ringing_expires_at)->toBeNull();
    expect($call->ended_at->getTimestamp())->toBe($eventTime + 60);
    expect(Cache::has(ricOfferKey($x['source'])))->toBeFalse();
    expect(Conversation::sole()->last_inbound_at->getTimestamp())->toBe($eventTime);
});

test('a stale connect for an unknown caller creates no lead, no call and no conversation', function () {
    $x = ricScenario();
    $counter = new ArrayObject(['n' => 0]);

    $outcome = ricHandle($x, ricConnect(['timestamp' => (string) (Carbon::now()->getTimestamp() - 300)]), $counter);

    expect($outcome)->toBe(RecordInboundCall::OUTCOME_IGNORED_STALE);
    expect($counter['n'])->toBe(0);
    expect(Lead::count())->toBe(0);
    expect(LeadCall::count())->toBe(0);
    expect(Conversation::count())->toBe(0);
});

test('the stale boundary is exactly the ring window', function (int $ageSeconds, string $expected) {
    $this->freezeSecond();
    $x = ricScenario();
    ricKnownLead($x);

    $outcome = ricHandle($x, ricConnect(['timestamp' => (string) (Carbon::now()->getTimestamp() - $ageSeconds)]));

    expect($outcome)->toBe($expected);
})->with([
    'one second inside the window still rings' => [59, RecordInboundCall::OUTCOME_RECORDED],
    'exactly at the window is stale' => [60, RecordInboundCall::OUTCOME_RECORDED_STALE],
]);

test('a future event timestamp cannot extend the ring window or push the conversation into the future', function () {
    $this->freezeSecond();
    $x = ricScenario();
    ricKnownLead($x);

    ricHandle($x, ricConnect(['timestamp' => (string) (Carbon::now()->getTimestamp() + 7200)]));

    expect(LeadCall::sole()->ringing_expires_at->getTimestamp())->toBe(Carbon::now()->getTimestamp() + 60);
    expect(Conversation::sole()->last_inbound_at->getTimestamp())->toBe(Carbon::now()->getTimestamp());
});

// ── CONNECT: races with merge and deletion ──────────────────────────────────

test('a lead merged away while the connect was being processed is followed to its survivor', function () {
    $x = ricScenario();
    $survivor = Lead::factory()->create(['tenant_id' => $x['tenant']->id, 'source_id' => $x['source']->id, 'phone' => '15557770000']);
    // The creator hands back a lead instance that was merged away right after
    // it was resolved: its in-memory copy is stale, the database row is not.
    $creator = ricCreator($x, new ArrayObject(['n' => 0]), function (Lead $lead) use ($survivor): Lead {
        DB::table('leads')->where('id', $lead->id)->update(['merged_into_lead_id' => $survivor->id]);

        return $lead;
    });

    ricHandle($x, ricConnect(), creator: $creator);

    expect(LeadCall::sole()->lead_id)->toBe($survivor->id);
    expect(Conversation::sole()->lead_id)->toBe($survivor->id);
});

test('a merge chain is followed all the way to the final survivor', function () {
    $x = ricScenario();
    $final = Lead::factory()->create(['tenant_id' => $x['tenant']->id, 'source_id' => $x['source']->id, 'phone' => '15557770000']);
    $middle = Lead::factory()->create(['tenant_id' => $x['tenant']->id, 'source_id' => $x['source']->id, 'phone' => '15557771111', 'merged_into_lead_id' => $final->id]);
    $creator = ricCreator($x, new ArrayObject(['n' => 0]), function (Lead $lead) use ($middle): Lead {
        DB::table('leads')->where('id', $lead->id)->update(['merged_into_lead_id' => $middle->id]);

        return $lead;
    });

    ricHandle($x, ricConnect(), creator: $creator);

    expect(LeadCall::sole()->lead_id)->toBe($final->id);
});

test('a lead deleted while the connect was being processed drops the call and is never recreated', function () {
    $x = ricScenario();
    $creator = ricCreator($x, new ArrayObject(['n' => 0]), function (Lead $lead): Lead {
        $lead->delete();

        return $lead;
    });

    $outcome = ricHandle($x, ricConnect(), creator: $creator);

    expect($outcome)->toBe(RecordInboundCall::OUTCOME_IGNORED_LEAD_MISSING);
    expect(Lead::count())->toBe(0);
    expect(LeadCall::count())->toBe(0);
    expect(Conversation::count())->toBe(0);
});

test('a recorded active call blocks deleting its lead, so a call that just rang cannot lose its lead', function () {
    $x = ricScenario();
    $lead = ricKnownLead($x);
    ricHandle($x, ricConnect());

    $verdict = app(LeadDeletionEligibilityService::class)->evaluate($lead);

    expect($verdict->eligible)->toBeFalse();
});

// ── CONNECT: tenant isolation ───────────────────────────────────────────────

test('a caller number known only to another tenant is a new caller, and the other tenant is untouched', function () {
    $x = ricScenario();
    $y = ricScenario();
    $otherTenantLead = ricKnownLead($y);
    $counter = new ArrayObject(['n' => 0]);

    ricHandle($x, ricConnect(), $counter);

    expect($counter['n'])->toBe(1);
    expect(LeadCall::where('lead_id', $otherTenantLead->id)->count())->toBe(0);
    expect(LeadCall::sole()->tenant_id)->toBe($x['tenant']->id);
    expect(Conversation::where('tenant_id', $y['tenant']->id)->count())->toBe(0);
});

test('two tenants may receive calls with the same external call id independently', function () {
    $x = ricScenario();
    $y = ricScenario();

    ricHandle($x, ricConnect());
    ricHandle($y, ricConnect());

    expect(LeadCall::count())->toBe(2);
    expect(LeadCall::where('tenant_id', $x['tenant']->id)->count())->toBe(1);
    expect(LeadCall::where('tenant_id', $y['tenant']->id)->count())->toBe(1);
});

test('a call recorded for another tenant is invisible to a duplicate check', function () {
    $x = ricScenario();
    $y = ricScenario();
    ricHandle($y, ricConnect());

    $outcome = ricHandle($x, ricConnect());

    expect($outcome)->toBe(RecordInboundCall::OUTCOME_RECORDED);
    expect(LeadCall::count())->toBe(2);
});

// ── TERMINATE ───────────────────────────────────────────────────────────────

test('a terminate moves a Ringing call to Missed and records the raw Meta status, timestamp and end', function () {
    $this->freezeSecond();
    $x = ricScenario();
    ricKnownLead($x);
    ricHandle($x, ricConnect());

    $outcome = ricHandle($x, ricTerminate(['status' => 'Failed']));

    $call = LeadCall::sole();

    expect($outcome)->toBe(RecordInboundCall::OUTCOME_TERMINATE_APPLIED);
    expect($call->status)->toBe(CallStatus::Missed);
    expect($call->end_reason)->toBe(CallEndReason::CallerEndedOrNotAnswered);
    expect($call->meta_status)->toBe('FAILED');
    expect($call->meta_terminated_at->getTimestamp())->toBe(Carbon::now()->getTimestamp());
    expect($call->ended_at)->not->toBeNull();
    expect($call->claimed_by)->toBeNull();
});

test('a Ringing call stays Missed whatever unverified status token Meta sends', function (mixed $status) {
    $x = ricScenario();
    ricKnownLead($x);
    ricHandle($x, ricConnect());

    ricHandle($x, ricTerminate(['status' => $status]));

    expect(LeadCall::sole()->status)->toBe(CallStatus::Missed);
})->with([
    'COMPLETED (never invent a Completed the agent never had)' => ['COMPLETED'],
    'FAILED' => ['FAILED'],
    'an unknown token' => ['SOMETHING_NEW'],
    'absent' => [null],
]);

test('a terminate carrying errors fails a Ringing call and stores the Meta error code verbatim', function () {
    $x = ricScenario();
    ricKnownLead($x);
    ricHandle($x, ricConnect());

    ricHandle($x, ricTerminate([], ['errors' => [['code' => 138021, 'message' => 'Media receive timeout']]]));

    $call = LeadCall::sole();

    expect($call->status)->toBe(CallStatus::Failed);
    expect($call->end_reason)->toBe(CallEndReason::MetaError);
    expect($call->meta_status)->toBe('ERROR:138021');
});

test('a terminate applies the approved matrix to calls the answering flow will later move on', function (string $state, array $terminate, array $valueExtra, CallStatus $status, CallEndReason $reason) {
    $x = ricScenario();
    $lead = ricKnownLead($x);
    LeadCall::factory()->{$state}()->create([
        'tenant_id' => $x['tenant']->id,
        'lead_id' => $lead->id,
        'lead_source_id' => $x['source']->id,
        'external_call_id' => 'wacid.CALL-1',
    ]);

    $outcome = ricHandle($x, ricTerminate($terminate, $valueExtra));

    $call = LeadCall::sole();

    expect($outcome)->toBe(RecordInboundCall::OUTCOME_TERMINATE_APPLIED);
    expect($call->status)->toBe($status);
    expect($call->end_reason)->toBe($reason);
    expect($call->meta_terminated_at)->not->toBeNull();
})->with([
    'answering, completed and picked up' => ['answering', ['status' => 'COMPLETED', 'start_time' => '1750000000', 'end_time' => '1750000030', 'duration' => 30], [], CallStatus::Completed, CallEndReason::CompletedUnconfirmedLocally],
    'answering, failed and not picked up' => ['answering', ['status' => 'FAILED'], [], CallStatus::Missed, CallEndReason::EndedBeforeConnect],
    'answering, COMPLETED but never picked up' => ['answering', ['status' => 'COMPLETED'], [], CallStatus::Missed, CallEndReason::EndedBeforeConnect],
    'answering, with errors' => ['answering', ['status' => 'FAILED'], ['errors' => [['code' => 138019]]], CallStatus::Failed, CallEndReason::MetaError],
    'connected, completed' => ['connected', ['status' => 'COMPLETED', 'start_time' => '1750000000', 'end_time' => '1750000120', 'duration' => 120], [], CallStatus::Completed, CallEndReason::RemoteTerminated],
    'connected, no status token' => ['connected', ['status' => null], [], CallStatus::Completed, CallEndReason::RemoteTerminated],
    'connected, failed' => ['connected', ['status' => 'FAILED'], [], CallStatus::Failed, CallEndReason::MetaFailedAfterConnect],
    'connected, with errors' => ['connected', ['status' => 'COMPLETED'], ['errors' => [['code' => 138021]]], CallStatus::Failed, CallEndReason::MetaFailedAfterConnect],
]);

test('an answering call that Meta reports as picked up records when it connected and how long it lasted', function () {
    $x = ricScenario();
    $lead = ricKnownLead($x);
    LeadCall::factory()->answering()->create(['tenant_id' => $x['tenant']->id, 'lead_id' => $lead->id, 'lead_source_id' => $x['source']->id, 'external_call_id' => 'wacid.CALL-1']);

    ricHandle($x, ricTerminate(['status' => 'COMPLETED', 'start_time' => '1750000000', 'end_time' => '1750000030', 'duration' => 30]));

    $call = LeadCall::sole();

    expect($call->connected_at->getTimestamp())->toBe(1750000000);
    expect($call->ended_at->getTimestamp())->toBe(1750000030);
    expect($call->duration_seconds)->toBe(30);
});

test('a terminate never moves a terminal call to another status and only fills what is still empty', function (string $state) {
    $x = ricScenario();
    $lead = ricKnownLead($x);
    $call = LeadCall::factory()->{$state}()->create([
        'tenant_id' => $x['tenant']->id,
        'lead_id' => $lead->id,
        'lead_source_id' => $x['source']->id,
        'external_call_id' => 'wacid.CALL-1',
        'duration_seconds' => null,
    ]);
    $original = $call->fresh();

    $outcome = ricHandle($x, ricTerminate(['status' => 'FAILED', 'duration' => 9]));

    $after = LeadCall::sole();

    expect($outcome)->toBe(RecordInboundCall::OUTCOME_TERMINATE_FILLED);
    expect($after->status)->toBe($original->status);
    expect($after->end_reason)->toBe($original->end_reason);
    expect($after->ended_at->getTimestamp())->toBe($original->ended_at->getTimestamp());
    expect($after->duration_seconds)->toBe(9);
    expect($after->meta_status)->toBe('FAILED');
    expect($after->meta_terminated_at)->not->toBeNull();
})->with(['completed', 'missed', 'rejected', 'failed']);

test('a duplicate terminate is a no-op and overwrites nothing', function () {
    $x = ricScenario();
    ricKnownLead($x);
    ricHandle($x, ricConnect());
    ricHandle($x, ricTerminate(['status' => 'FAILED']));
    $before = LeadCall::sole()->getAttributes();

    $outcome = ricHandle($x, ricTerminate(['status' => 'COMPLETED', 'duration' => 999]));

    expect($outcome)->toBe(RecordInboundCall::OUTCOME_TERMINATE_NOOP);
    expect(LeadCall::sole()->getAttributes())->toEqual($before);
});

test('a terminate for a call of another tenant is not applied to it', function () {
    $x = ricScenario();
    $y = ricScenario();
    ricKnownLead($y);
    ricHandle($y, ricConnect());

    ricHandle($x, ricTerminate());

    expect(LeadCall::sole()->status)->toBe(CallStatus::Ringing);
    expect(LeadCall::sole()->meta_terminated_at)->toBeNull();
});

// ── TERMINATE before CONNECT ────────────────────────────────────────────────

test('a terminate that arrives first for a known caller records the terminal row, and the delayed connect is then a duplicate that never rings', function () {
    $x = ricScenario();
    ricKnownLead($x);

    $outcome = ricHandle($x, ricTerminate(['status' => 'FAILED']));

    $call = LeadCall::sole();

    expect($outcome)->toBe(RecordInboundCall::OUTCOME_TERMINATE_RECORDED);
    expect($call->status)->toBe(CallStatus::Missed);
    expect($call->end_reason)->toBe(CallEndReason::TerminateBeforeConnect);
    expect($call->meta_status)->toBe('FAILED');
    expect($call->meta_terminated_at)->not->toBeNull();
    expect($call->ringing_expires_at)->toBeNull();
    expect(Conversation::count())->toBe(1);

    expect(ricHandle($x, ricConnect()))->toBe(RecordInboundCall::OUTCOME_DUPLICATE);
    expect(LeadCall::count())->toBe(1);
    expect(Cache::has(ricOfferKey($x['source'])))->toBeFalse();
});

test('a terminate-before-connect with errors is recorded as Failed with the Meta code', function () {
    $x = ricScenario();
    ricKnownLead($x);

    ricHandle($x, ricTerminate([], ['errors' => [['code' => 138020]]]));

    $call = LeadCall::sole();

    expect($call->status)->toBe(CallStatus::Failed);
    expect($call->end_reason)->toBe(CallEndReason::MetaError);
    expect($call->meta_status)->toBe('ERROR:138020');
});

test('a terminate for an unknown caller creates no lead and leaves a tombstone', function () {
    $x = ricScenario();
    $counter = new ArrayObject(['n' => 0]);

    $outcome = ricHandle($x, ricTerminate(), $counter);

    expect($outcome)->toBe(RecordInboundCall::OUTCOME_TERMINATE_TOMBSTONED);
    expect($counter['n'])->toBe(0);
    expect(Lead::count())->toBe(0);
    expect(LeadCall::count())->toBe(0);
    expect(Cache::has(RecordInboundCall::tombstoneCacheKey($x['source'], 'wacid.CALL-1')))->toBeTrue();
});

test('the tombstone holds no phone number and no SDP, and lives for tombstone_ttl_seconds', function () {
    $x = ricScenario();

    ricHandle($x, ricTerminate());
    $key = RecordInboundCall::tombstoneCacheKey($x['source'], 'wacid.CALL-1');

    expect($key)->toBe("wa-call-tombstone:{$x['tenant']->id}:{$x['source']->id}:wacid.CALL-1");
    expect(json_encode(Cache::get($key)))->not->toContain(Wa::CALLER)->not->toContain(Wa::SDP_SENTINEL);

    $this->travel(config('calling.tombstone_ttl_seconds') + 1)->seconds();
    expect(Cache::has($key))->toBeFalse();
});

test('a delayed connect that meets a tombstone for an unknown caller never creates a lead', function () {
    $x = ricScenario();
    $counter = new ArrayObject(['n' => 0]);
    ricHandle($x, ricTerminate(), $counter);

    $outcome = ricHandle($x, ricConnect(), $counter);

    expect($outcome)->toBe(RecordInboundCall::OUTCOME_IGNORED_TERMINATED);
    expect($counter['n'])->toBe(0);
    expect(Lead::count())->toBe(0);
    expect(LeadCall::count())->toBe(0);
    expect(Conversation::count())->toBe(0);
});

test('a delayed connect that meets a tombstone for a caller who is a lead by then is recorded as terminated, not ringing', function () {
    $x = ricScenario();
    ricHandle($x, ricTerminate(['status' => 'FAILED']));
    ricKnownLead($x);

    $outcome = ricHandle($x, ricConnect());

    $call = LeadCall::sole();

    expect($outcome)->toBe(RecordInboundCall::OUTCOME_RECORDED_AFTER_TERMINATE);
    expect($call->status)->toBe(CallStatus::Missed);
    expect($call->end_reason)->toBe(CallEndReason::TerminateBeforeConnect);
    expect($call->meta_status)->toBe('FAILED');
    expect($call->ringing_expires_at)->toBeNull();
    expect(Cache::has(ricOfferKey($x['source'])))->toBeFalse();
});

test('a terminate with no usable caller is tombstoned without a lookup', function () {
    $x = ricScenario();
    ricKnownLead($x);

    $outcome = ricHandle($x, ricTerminate(['from' => null], ['contacts' => []]));

    expect($outcome)->toBe(RecordInboundCall::OUTCOME_TERMINATE_TOMBSTONED);
    expect(LeadCall::count())->toBe(0);
});

test('once the tombstone has expired a fresh connect for the same id is handled normally', function () {
    $x = ricScenario();
    ricHandle($x, ricTerminate());

    $this->travel(config('calling.tombstone_ttl_seconds') + 1)->seconds();
    $counter = new ArrayObject(['n' => 0]);
    $outcome = ricHandle($x, ricConnect(), $counter);

    expect($outcome)->toBe(RecordInboundCall::OUTCOME_RECORDED);
    expect($counter['n'])->toBe(1);
});

// ── Mixed inputs ────────────────────────────────────────────────────────────

test('an exception while handling one call leaves no partial state and does not block the next call', function () {
    $x = ricScenario();
    ricKnownLead($x);

    ricHandle($x, ricConnect(['id' => 'wacid.OK-1']));
    expect(fn () => app(RecordInboundCall::class)->handle(
        ricConnect(['id' => 'wacid.BROKEN', 'from' => '15550003333'], ['contacts' => []]),
        $x['source'],
        fn (): Lead => throw new RuntimeException('creator exploded'),
    ))->toThrow(RuntimeException::class);
    ricHandle($x, ricConnect(['id' => 'wacid.OK-2']));

    expect(LeadCall::pluck('external_call_id')->sort()->values()->all())->toBe(['wacid.OK-1', 'wacid.OK-2']);
    expect(Lead::where('phone', '15550003333')->count())->toBe(0);
});

test('a lead on a different source that shares the caller number is not matched', function () {
    $x = ricScenario();
    $email = LeadSource::factory()->create(['tenant_id' => $x['tenant']->id, 'type' => LeadSourceType::Email]);
    Lead::factory()->create(['tenant_id' => $x['tenant']->id, 'source_id' => $email->id, 'phone' => Wa::CALLER]);
    $counter = new ArrayObject(['n' => 0]);

    ricHandle($x, ricConnect(), $counter);

    // The email lead shares the number but not the source, so it is not matched.
    expect($counter['n'])->toBe(1);
});

// ── The seen-call-id marker (post-delete redelivery) ────────────────────────

function ricSeenKey(LeadSource $source, string $callId = 'wacid.CALL-1'): string
{
    return RecordInboundCall::seenCacheKey($source, $callId);
}

test('a recorded call leaves a seen marker scoped by tenant, source and call id that lasts Meta\'s 7-day retry window', function () {
    $x = ricScenario();
    ricKnownLead($x);

    ricHandle($x, ricConnect());

    $key = ricSeenKey($x['source']);
    $ttl = config('calling.seen_ttl_seconds');

    expect($ttl)->toBeGreaterThanOrEqual(7 * 86400);
    expect($key)->toBe("wa-call-seen:{$x['tenant']->id}:{$x['source']->id}:wacid.CALL-1");
    expect(Cache::has($key))->toBeTrue();

    $this->travel($ttl - 1)->seconds();
    expect(Cache::has($key))->toBeTrue();

    $this->travel(2)->seconds();
    expect(Cache::has($key))->toBeFalse();
});

test('every kind of row a call creates is remembered', function (Closure $scenario) {
    $x = ricScenario();
    ricKnownLead($x);

    $scenario($x);

    expect(LeadCall::count())->toBe(1);
    expect(Cache::has(ricSeenKey($x['source'])))->toBeTrue();
})->with([
    'a ringing call' => [fn (array $x) => ricHandle($x, ricConnect())],
    'a no-recipient call' => [function (array $x): void {
        DB::table('tenant_users')->where('id', $x['admin']->id)->update(['is_active' => false]);
        ricHandle($x, ricConnect());
    }],
    'a stale connect for a known caller' => [fn (array $x) => ricHandle($x, ricConnect(['timestamp' => (string) (Carbon::now()->getTimestamp() - 300)]))],
    'a terminate that arrived first' => [fn (array $x) => ricHandle($x, ricTerminate())],
]);

test('nothing is remembered for a delivery that recorded no call', function (Closure $scenario) {
    $x = ricScenario();

    $scenario($x);

    expect(LeadCall::count())->toBe(0);
    expect(Cache::has(ricSeenKey($x['source'])))->toBeFalse();
})->with([
    'a stale connect for an unknown caller' => [fn (array $x) => ricHandle($x, ricConnect(['timestamp' => (string) (Carbon::now()->getTimestamp() - 300)]))],
    'a terminate for an unknown caller, then its delayed connect' => [function (array $x): void {
        ricHandle($x, ricTerminate());
        ricHandle($x, ricConnect());
    }],
    'a lead that vanished mid-processing' => [fn (array $x) => ricHandle($x, ricConnect(), creator: ricCreator($x, new ArrayObject(['n' => 0]), function (Lead $lead): Lead {
        $lead->delete();

        return $lead;
    }))],
    'a connect while new calls are not accepted' => [fn (array $x) => ricHandle($x, ricConnect(), acceptNewCalls: false)],
]);

test('a redelivered connect after the lead and its calls were deleted is ignored and recreates nothing', function () {
    $x = ricScenario();
    $counter = new ArrayObject(['n' => 0]);
    ricHandle($x, ricConnect(), $counter);
    ricHandle($x, ricTerminate());
    Lead::sole()->delete(); // cascades the terminal call and its conversation
    expect(LeadCall::count())->toBe(0);

    $outcome = ricHandle($x, ricConnect(), $counter);

    expect($outcome)->toBe(RecordInboundCall::OUTCOME_IGNORED_SEEN);
    expect($counter['n'])->toBe(1);
    expect(Lead::count())->toBe(0);
    expect(LeadCall::count())->toBe(0);
    expect(Conversation::count())->toBe(0);
});

test('the redelivery stays ignored even if a lead with the same number exists again by then', function () {
    $x = ricScenario();
    ricKnownLead($x);
    ricHandle($x, ricConnect());
    ricHandle($x, ricTerminate());
    Lead::sole()->delete();
    $newLead = ricKnownLead($x);

    $outcome = ricHandle($x, ricConnect());

    expect($outcome)->toBe(RecordInboundCall::OUTCOME_IGNORED_SEEN);
    expect(LeadCall::count())->toBe(0);
    expect(Conversation::where('lead_id', $newLead->id)->count())->toBe(0);
});

test('a redelivered terminate after the call was deleted is ignored and leaves no row and no tombstone', function () {
    $x = ricScenario();
    ricKnownLead($x);
    ricHandle($x, ricConnect());
    ricHandle($x, ricTerminate());
    Lead::sole()->delete();
    ricKnownLead($x);

    $outcome = ricHandle($x, ricTerminate());

    expect($outcome)->toBe(RecordInboundCall::OUTCOME_IGNORED_SEEN);
    expect(LeadCall::count())->toBe(0);
    expect(Cache::has(RecordInboundCall::tombstoneCacheKey($x['source'], 'wacid.CALL-1')))->toBeFalse();
});

test('the marker never overrides a live row: while the row exists a redelivery is a plain duplicate', function () {
    $x = ricScenario();
    ricKnownLead($x);
    ricHandle($x, ricConnect());
    $before = LeadCall::sole()->getAttributes();

    $outcome = ricHandle($x, ricConnect());

    expect(Cache::has(ricSeenKey($x['source'])))->toBeTrue();
    expect($outcome)->toBe(RecordInboundCall::OUTCOME_DUPLICATE);
    expect(LeadCall::sole()->getAttributes())->toEqual($before);
});

test('a marker only ever blocks its own tenant, source and call id', function () {
    $x = ricScenario();
    $y = ricScenario();
    $secondSource = Wa::source($x['tenant'], ['name' => 'Second WhatsApp number']);
    ricHandle($x, ricConnect());
    Lead::query()->delete();

    $otherTenant = ricHandle($y, ricConnect());
    $otherSource = app(RecordInboundCall::class)->handle(ricConnect(), $secondSource, fn (): Lead => Lead::factory()->create(['tenant_id' => $x['tenant']->id, 'source_id' => $secondSource->id, 'phone' => Wa::CALLER]));
    $otherCallId = ricHandle($x, ricConnect(['id' => 'wacid.CALL-2']));

    expect($otherTenant)->toBe(RecordInboundCall::OUTCOME_RECORDED);
    expect($otherSource)->toBe(RecordInboundCall::OUTCOME_RECORDED);
    expect($otherCallId)->toBe(RecordInboundCall::OUTCOME_RECORDED);
});

test('a marker write that fails never fails the call, is logged by ids only, and the next duplicate delivery heals it', function () {
    $store = ricFailingCache(2, prefix: 'wa-call-seen:');
    $x = ricScenario();
    ricKnownLead($x);
    Log::shouldReceive('channel')->with('whatsapp')->andReturnSelf();
    Log::shouldReceive('warning')->once()->with('WA CALL SEEN MARKER FAILED', [
        'tenant_id' => $x['tenant']->id,
        'lead_source_id' => $x['source']->id,
        'call_id' => 'wacid.CALL-1',
    ]);

    $outcome = ricHandle($x, ricConnect());

    expect($outcome)->toBe(RecordInboundCall::OUTCOME_RECORDED);
    expect(LeadCall::sole()->status)->toBe(CallStatus::Ringing);
    expect(Cache::has(ricOfferKey($x['source'])))->toBeTrue();
    expect($store->attemptsFor('wa-call-seen:'))->toBe(2);
    expect(Cache::has(ricSeenKey($x['source'])))->toBeFalse();

    expect(ricHandle($x, ricConnect()))->toBe(RecordInboundCall::OUTCOME_DUPLICATE);
    expect(Cache::has(ricSeenKey($x['source'])))->toBeTrue();
});

test('a terminate for an existing row also heals a missing marker', function () {
    ricFailingCache(2, prefix: 'wa-call-seen:');
    $x = ricScenario();
    ricKnownLead($x);
    Log::shouldReceive('channel')->with('whatsapp')->andReturnSelf();
    Log::shouldReceive('warning')->once();
    ricHandle($x, ricConnect());
    expect(Cache::has(ricSeenKey($x['source'])))->toBeFalse();

    ricHandle($x, ricTerminate());

    expect(Cache::has(ricSeenKey($x['source'])))->toBeTrue();
});

test('an unreadable cache fails open: the call is still recorded and a redelivery is still recognised by its row', function () {
    ricUnreadableCache();
    $x = ricScenario();
    ricKnownLead($x);

    expect(ricHandle($x, ricConnect()))->toBe(RecordInboundCall::OUTCOME_RECORDED);
    expect(ricHandle($x, ricConnect()))->toBe(RecordInboundCall::OUTCOME_DUPLICATE);
    expect(LeadCall::count())->toBe(1);
});

// ── Calling switched off: new calls refused, existing calls still closed ────

test('while new calls are not accepted a connect creates no lead, no call and no conversation', function () {
    $x = ricScenario();
    $counter = new ArrayObject(['n' => 0]);

    $outcome = ricHandle($x, ricConnect(), $counter, acceptNewCalls: false);

    expect($outcome)->toBe(RecordInboundCall::OUTCOME_IGNORED_CALLING_DISABLED);
    expect($counter['n'])->toBe(0);
    expect(Lead::count())->toBe(0);
    expect(LeadCall::count())->toBe(0);
    expect(Conversation::count())->toBe(0);
});

test('a terminate for an already-recorded call is still applied while new calls are not accepted', function () {
    $x = ricScenario();
    ricKnownLead($x);
    ricHandle($x, ricConnect());

    $outcome = ricHandle($x, ricTerminate(['status' => 'FAILED']), acceptNewCalls: false);

    $call = LeadCall::sole();

    expect($outcome)->toBe(RecordInboundCall::OUTCOME_TERMINATE_APPLIED);
    expect($call->status)->toBe(CallStatus::Missed);
    expect($call->end_reason)->toBe(CallEndReason::CallerEndedOrNotAnswered);
    expect($call->meta_terminated_at)->not->toBeNull();
});

test('a duplicate terminate stays idempotent while new calls are not accepted', function () {
    $x = ricScenario();
    ricKnownLead($x);
    ricHandle($x, ricConnect());
    ricHandle($x, ricTerminate(['status' => 'FAILED']), acceptNewCalls: false);
    $before = LeadCall::sole()->getAttributes();

    $outcome = ricHandle($x, ricTerminate(['status' => 'COMPLETED', 'duration' => 999]), acceptNewCalls: false);

    expect($outcome)->toBe(RecordInboundCall::OUTCOME_TERMINATE_NOOP);
    expect(LeadCall::sole()->getAttributes())->toEqual($before);
});

test('a terminate for an unrecorded call is ignored while new calls are not accepted: no row, no lead, no tombstone', function () {
    $x = ricScenario();
    ricKnownLead($x);

    $outcome = ricHandle($x, ricTerminate(), acceptNewCalls: false);

    expect($outcome)->toBe(RecordInboundCall::OUTCOME_IGNORED_CALLING_DISABLED);
    expect(Lead::count())->toBe(1);
    expect(LeadCall::count())->toBe(0);
    expect(Conversation::count())->toBe(0);
    expect(Cache::has(RecordInboundCall::tombstoneCacheKey($x['source'], 'wacid.CALL-1')))->toBeFalse();
});

test('terminate-before-connect stays deterministic: refused while disabled, recorded as before once calling is accepted again', function () {
    $x = ricScenario();
    ricKnownLead($x);

    expect(ricHandle($x, ricTerminate(), acceptNewCalls: false))->toBe(RecordInboundCall::OUTCOME_IGNORED_CALLING_DISABLED);
    expect(LeadCall::count())->toBe(0);

    expect(ricHandle($x, ricTerminate()))->toBe(RecordInboundCall::OUTCOME_TERMINATE_RECORDED);
    expect(LeadCall::sole()->end_reason)->toBe(CallEndReason::TerminateBeforeConnect);
});
