<?php

use App\Actions\Tenant\RecordInboundCall;
use App\Actions\Tenant\RecordLeadConsent;
use App\Enums\CallEndReason;
use App\Enums\CallStatus;
use App\Enums\ConsentSource;
use App\Enums\ConsentStatus;
use App\Enums\ConsentType;
use App\Enums\MessageChannel;
use App\Events\LeadReceived;
use App\Jobs\ReconcileOutboundMessageStatus;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Models\LeadSource;
use App\Models\Message;
use App\Notifications\NewLeadNotification;
use App\Services\LeadMergeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Monolog\Handler\TestHandler;
use Tests\Support\WhatsAppCallFixtures as Wa;

/*
 * WhatsApp inbound calling, Step 2 — the `calls[]` branch of the WhatsApp
 * webhook. Every request is signed with the source's app secret (the state in
 * which calls are trusted) unless a test is specifically about the signature or
 * a missing secret. The HTTP response is asserted to be 200 everywhere: a call
 * problem must never surface to Meta.
 */

/**
 * @param  array<string, mixed>  $sourceOverrides
 * @param  array<string, mixed>  $config
 * @return array{tenant: mixed, admin: mixed, source: LeadSource}
 */
function wchScenario(array $sourceOverrides = [], array $config = []): array
{
    ['tenant' => $tenant, 'clientAdmin' => $admin] = tenantWithLeadManagement();

    return ['tenant' => $tenant, 'admin' => $admin, 'source' => Wa::source($tenant, $sourceOverrides, $config)];
}

/**
 * @param  array<string, mixed>  $x
 * @param  array<string, mixed>  $payload
 */
function wchSend($test, array $x, array $payload, bool $signed = true)
{
    return $test->postJson(
        route('api.webhook.whatsapp', $x['tenant']->tenant_code),
        $payload,
        $signed ? Wa::signed($payload) : [],
    );
}

/**
 * Routes every log line (the whatsapp channel and the default channel) into
 * one in-memory handler, so a test can prove what was — and was not — logged.
 */
function wchCaptureLogs(): TestHandler
{
    config([
        'logging.default' => 'wch-capture',
        'logging.channels.wch-capture' => ['driver' => 'monolog', 'handler' => TestHandler::class],
        'logging.channels.whatsapp' => ['driver' => 'stack', 'channels' => ['wch-capture']],
    ]);

    $handler = Log::channel('wch-capture')->getLogger()->getHandlers()[0];
    expect($handler)->toBeInstanceOf(TestHandler::class);

    return $handler;
}

function wchLogText(TestHandler $handler): string
{
    return (string) json_encode(array_map(
        fn ($record): array => [$record->message, $record->context],
        $handler->getRecords(),
    ));
}

/**
 * @return array<string, mixed>
 */
function wchMessage(string $from = '15551234567', string $id = 'wamid.MSG-1', string $body = 'Hello there'): array
{
    return ['from' => $from, 'id' => $id, 'type' => 'text', 'timestamp' => (string) Carbon::now()->getTimestamp(), 'text' => ['body' => $body]];
}

// ── CONNECT: the happy paths ────────────────────────────────────────────────

test('a valid connect for an unknown caller creates one lead, one conversation and one ringing call', function () {
    Event::fake([LeadReceived::class]);
    $x = wchScenario();

    wchSend($this, $x, Wa::payload([Wa::connect()]))->assertOk()->assertExactJson(['status' => 'ok']);

    $lead = Lead::sole();
    $call = LeadCall::sole();

    expect($lead->tenant_id)->toBe($x['tenant']->id);
    expect($lead->source_id)->toBe($x['source']->id);
    expect($lead->name)->toBe('Caller Name');
    expect($lead->phone)->toBe(Wa::CALLER);
    expect($lead->message)->toBeNull();
    expect($lead->source_data)->toBeNull();
    expect($call->lead_id)->toBe($lead->id);
    expect($call->status)->toBe(CallStatus::Ringing);
    expect($call->routed_user_ids)->toBe([$x['admin']->id]);
    expect(Conversation::sole()->external_thread_id)->toBe(Wa::CALLER);
    expect(Message::count())->toBe(0);

    Event::assertDispatchedTimes(LeadReceived::class, 1);
});

test('a new caller without a profile name gets the same fallback name the message path uses', function () {
    $x = wchScenario();

    wchSend($this, $x, Wa::payload([Wa::connect()], ['contacts' => []]))->assertOk();

    expect(Lead::sole()->name)->toBe('WhatsApp User '.Wa::CALLER);
});

test('the existing new-lead behavior still runs for a lead created by a call: the admin is notified', function () {
    $x = wchScenario();

    wchSend($this, $x, Wa::payload([Wa::connect()]))->assertOk();

    expect($x['admin']->fresh()->notifications->where('type', NewLeadNotification::class))->toHaveCount(1);
});

test('a known caller reuses their lead and fires no LeadReceived', function () {
    Event::fake([LeadReceived::class]);
    $x = wchScenario();
    $lead = Lead::factory()->create(['tenant_id' => $x['tenant']->id, 'source_id' => $x['source']->id, 'phone' => Wa::CALLER]);

    wchSend($this, $x, Wa::payload([Wa::connect()]))->assertOk();

    expect(Lead::count())->toBe(1);
    expect(LeadCall::sole()->lead_id)->toBe($lead->id);
    Event::assertNotDispatched(LeadReceived::class);
});

test('redelivering the same connect creates neither a second lead nor a second call', function () {
    $x = wchScenario();
    $payload = Wa::payload([Wa::connect()]);

    wchSend($this, $x, $payload)->assertOk();
    wchSend($this, $x, $payload)->assertOk();
    wchSend($this, $x, $payload)->assertOk();

    expect(Lead::count())->toBe(1);
    expect(LeadCall::count())->toBe(1);
    expect(Conversation::count())->toBe(1);
});

test('a call from the same new number as a message in the same payload shares one lead', function () {
    Event::fake([LeadReceived::class]);
    $x = wchScenario();

    wchSend($this, $x, Wa::payload([Wa::connect()], ['messages' => [wchMessage(Wa::CALLER)]]))->assertOk();

    expect(Lead::count())->toBe(1);
    expect(Conversation::count())->toBe(1);
    expect(Message::count())->toBe(1);
    expect(LeadCall::sole()->lead_id)->toBe(Lead::sole()->id);
    Event::assertDispatchedTimes(LeadReceived::class, 1);
});

test('a terminate after a connect ends the call as Missed', function () {
    $x = wchScenario();
    wchSend($this, $x, Wa::payload([Wa::connect()]))->assertOk();

    wchSend($this, $x, Wa::payload([Wa::terminate(['status' => 'FAILED'])]))->assertOk();

    $call = LeadCall::sole();

    expect($call->status)->toBe(CallStatus::Missed);
    expect($call->end_reason)->toBe(CallEndReason::CallerEndedOrNotAnswered);
    expect($call->meta_status)->toBe('FAILED');
});

test('a terminate that arrives first for an unknown caller creates no lead, and the delayed connect creates none either', function () {
    $x = wchScenario();

    wchSend($this, $x, Wa::payload([Wa::terminate()]))->assertOk();
    wchSend($this, $x, Wa::payload([Wa::connect()]))->assertOk();

    expect(Lead::count())->toBe(0);
    expect(LeadCall::count())->toBe(0);
});

test('a stale connect is recorded for a known caller and ignored for an unknown one', function () {
    $x = wchScenario();
    $stale = (string) (Carbon::now()->getTimestamp() - 600);

    wchSend($this, $x, Wa::payload([Wa::connect(['timestamp' => $stale])]))->assertOk();
    expect(Lead::count())->toBe(0);
    expect(LeadCall::count())->toBe(0);

    Lead::factory()->create(['tenant_id' => $x['tenant']->id, 'source_id' => $x['source']->id, 'phone' => Wa::CALLER]);
    wchSend($this, $x, Wa::payload([Wa::connect(['timestamp' => $stale])]))->assertOk();

    expect(LeadCall::sole()->status)->toBe(CallStatus::Missed);
    expect(LeadCall::sole()->end_reason)->toBe(CallEndReason::TerminateBeforeConnect);
});

// ── The source-level gates ──────────────────────────────────────────────────

test('with calling disabled locally a connect is ignored, and only the observed Meta marker is updated', function () {
    $this->freezeSecond();
    $x = wchScenario(['calling_enabled' => false]);

    wchSend($this, $x, Wa::payload([Wa::connect()]))->assertOk();

    $source = $x['source']->fresh();

    expect(Lead::count())->toBe(0);
    expect(LeadCall::count())->toBe(0);
    expect(Conversation::count())->toBe(0);
    expect($source->calling_enabled)->toBeFalse();
    expect($source->calling_meta_status)->toBe('ENABLED');
    expect($source->calling_meta_checked_at->getTimestamp())->toBe(Carbon::now()->getTimestamp());
});

test('a terminate alone never touches the observed Meta marker', function () {
    $x = wchScenario(['calling_enabled' => false]);

    wchSend($this, $x, Wa::payload([Wa::terminate()]))->assertOk();

    expect($x['source']->fresh()->calling_meta_status)->toBeNull();
    expect(LeadCall::count())->toBe(0);
});

test('the observed marker is not rewritten if it was refreshed within the last minute', function (int $ageSeconds, string $status, bool $expectWrite) {
    $this->freezeSecond();
    $checkedAt = Carbon::now()->subSeconds($ageSeconds);
    $x = wchScenario(['calling_enabled' => false, 'calling_meta_status' => $status, 'calling_meta_checked_at' => $checkedAt]);

    wchSend($this, $x, Wa::payload([Wa::connect()]))->assertOk();

    $source = $x['source']->fresh();

    expect($source->calling_meta_status)->toBe('ENABLED');
    expect($source->calling_meta_checked_at->getTimestamp())->toBe($expectWrite ? Carbon::now()->getTimestamp() : $checkedAt->getTimestamp());
})->with([
    'ENABLED and checked 10s ago: skipped' => [10, 'ENABLED', false],
    'ENABLED but checked 2 minutes ago: refreshed' => [120, 'ENABLED', true],
    'DISABLED checked 10s ago: corrected to ENABLED' => [10, 'DISABLED', true],
]);

test('a valid connect for an enabled source also records the observed marker without changing calling_enabled', function () {
    $x = wchScenario();

    wchSend($this, $x, Wa::payload([Wa::connect()]))->assertOk();

    $source = $x['source']->fresh();

    expect($source->calling_meta_status)->toBe('ENABLED');
    expect($source->calling_enabled)->toBeTrue();
});

test('a source without an app secret ignores calls entirely', function (mixed $config) {
    $x = wchScenario(config: $config);
    $x['source']->update(['config' => ['phone_number_id' => Wa::PHONE_NUMBER_ID, 'verify_token' => 't'] + $config]);
    $handler = wchCaptureLogs();

    wchSend($this, $x, Wa::payload([Wa::connect()]), signed: false)->assertOk();

    expect(Lead::count())->toBe(0);
    expect(LeadCall::count())->toBe(0);
    expect($x['source']->fresh()->calling_meta_status)->toBeNull();
    expect(wchLogText($handler))->toContain('ignored_no_app_secret');
})->with([
    'no app_secret key' => [[]],
    'empty app_secret' => [['app_secret' => '']],
]);

test('a wrong or missing signature is rejected by the unchanged middleware and nothing is recorded', function () {
    $x = wchScenario();
    $payload = Wa::payload([Wa::connect()]);

    $this->postJson(route('api.webhook.whatsapp', $x['tenant']->tenant_code), $payload, Wa::signed($payload, 'a-different-secret'))->assertForbidden();
    $this->postJson(route('api.webhook.whatsapp', $x['tenant']->tenant_code), $payload)->assertForbidden();

    expect(Lead::count())->toBe(0);
    expect(LeadCall::count())->toBe(0);
});

test('an inactive source or an unknown tenant is acknowledged with 200 and records nothing', function () {
    $x = wchScenario(['is_active' => false]);

    wchSend($this, $x, Wa::payload([Wa::connect()]), signed: false)->assertOk()->assertExactJson(['status' => 'ok']);
    $this->postJson(route('api.webhook.whatsapp', 'NO-SUCH-TENANT'), Wa::payload([Wa::connect()]))->assertOk();

    expect(Lead::count())->toBe(0);
    expect(LeadCall::count())->toBe(0);
});

test('a phone_number_id that does not match the source is ignored, but is accepted when the source has none configured', function () {
    $x = wchScenario();
    $mismatch = Wa::payload([Wa::connect()], ['metadata' => ['phone_number_id' => '999', 'display_phone_number' => Wa::BUSINESS_NUMBER]]);

    wchSend($this, $x, $mismatch)->assertOk();
    expect(LeadCall::count())->toBe(0);

    $x['source']->update(['config' => ['verify_token' => 't', 'app_secret' => Wa::APP_SECRET]]);
    wchSend($this, $x, $mismatch)->assertOk();
    expect(LeadCall::count())->toBe(1);
});

// ── Items that are ignored ──────────────────────────────────────────────────

test('malformed call items are ignored with 200 and create nothing', function (mixed $item) {
    $x = wchScenario();

    wchSend($this, $x, Wa::payload([$item]))->assertOk()->assertExactJson(['status' => 'ok']);

    expect(Lead::count())->toBe(0);
    expect(LeadCall::count())->toBe(0);
})->with([
    'a string' => ['nonsense'],
    'no id' => [['event' => 'connect', 'from' => Wa::CALLER]],
    'oversized id' => [['id' => str_repeat('x', 500), 'event' => 'connect']],
    'unsupported event' => [['id' => 'wacid.X', 'event' => 'call_created']],
]);

test('a connect without a usable offer creates no lead and no call', function (array $session) {
    $x = wchScenario();

    wchSend($this, $x, Wa::payload([Wa::connect(['session' => $session])]))->assertOk();

    expect(Lead::count())->toBe(0);
    expect(LeadCall::count())->toBe(0);
})->with([
    'missing sdp' => [['sdp_type' => 'offer']],
    'an answer, not an offer' => [['sdp_type' => 'answer', 'sdp' => "v=0\r\n"]],
    'not sdp' => [['sdp_type' => 'offer', 'sdp' => 'GET / HTTP/1.1']],
    'oversized sdp' => [['sdp_type' => 'offer', 'sdp' => "v=0\r\n".str_repeat('a', 20000)]],
]);

test('a caller with no usable phone number (username or BSUID only) creates neither lead nor call', function () {
    $x = wchScenario();
    $call = Wa::connect();
    unset($call['from']);

    wchSend($this, $x, Wa::payload([$call], ['contacts' => [['profile' => ['name' => 'Someone'], 'user_id' => 'US.BSUID123']]]))->assertOk();

    expect(Lead::count())->toBe(0);
    expect(LeadCall::count())->toBe(0);
});

test('a business-initiated call is not an inbound call and is ignored', function () {
    $x = wchScenario();

    wchSend($this, $x, Wa::payload([Wa::connect(['direction' => 'BUSINESS_INITIATED'])]))->assertOk();

    expect(Lead::count())->toBe(0);
    expect(LeadCall::count())->toBe(0);
});

test('a calls value that is not an array is ignored without disturbing the rest of the payload', function () {
    $x = wchScenario();

    wchSend($this, $x, Wa::payload([], ['calls' => 'oops', 'messages' => [wchMessage()]]))->assertOk();

    expect(Lead::count())->toBe(1);
    expect(Message::count())->toBe(1);
    expect(LeadCall::count())->toBe(0);
});

// ── Isolation ───────────────────────────────────────────────────────────────

test('calls are recorded only for the tenant whose webhook URL received them', function () {
    $a = wchScenario();
    $b = wchScenario();
    $payload = Wa::payload([Wa::connect()]);

    wchSend($this, $a, $payload)->assertOk();

    expect(LeadCall::where('tenant_id', $a['tenant']->id)->count())->toBe(1);
    expect(LeadCall::where('tenant_id', $b['tenant']->id)->count())->toBe(0);
    expect(Lead::where('tenant_id', $b['tenant']->id)->count())->toBe(0);

    // The other tenant may then receive the very same call id independently.
    wchSend($this, $b, $payload)->assertOk();

    expect(LeadCall::count())->toBe(2);
    expect(LeadCall::where('tenant_id', $b['tenant']->id)->sole()->external_call_id)->toBe('wacid.CALL-1');
});

test('one tenant cannot post a call using another tenant signature', function () {
    $a = wchScenario();
    $b = wchScenario(config: ['app_secret' => 'tenant-b-secret']);
    $payload = Wa::payload([Wa::connect()]);

    $this->postJson(route('api.webhook.whatsapp', $b['tenant']->tenant_code), $payload, Wa::signed($payload, Wa::APP_SECRET))->assertForbidden();

    expect(LeadCall::count())->toBe(0);
});

// ── Mixed payloads and per-item isolation ───────────────────────────────────

test('calls, messages and statuses in one payload are each processed independently', function () {
    Queue::fake([ReconcileOutboundMessageStatus::class]);
    $x = wchScenario();

    wchSend($this, $x, Wa::payload([Wa::connect()], [
        'messages' => [wchMessage('15551234567', 'wamid.M1', 'Hi from a texter')],
        'statuses' => [['id' => 'wamid.OUT1', 'status' => 'delivered']],
    ]))->assertOk();

    expect(Lead::count())->toBe(2);
    expect(Message::sole()->body)->toBe('Hi from a texter');
    expect(LeadCall::sole()->lead->phone)->toBe(Wa::CALLER);
    Queue::assertPushed(ReconcileOutboundMessageStatus::class, 1);
});

test('a broken call item does not stop a valid call item in the same payload', function () {
    $x = wchScenario();
    $good = Wa::connect(['id' => 'wacid.GOOD', 'from' => '15550001111']);

    wchSend($this, $x, Wa::payload(['garbage', ['id' => 'wacid.NO-EVENT'], $good]))->assertOk();

    expect(LeadCall::sole()->external_call_id)->toBe('wacid.GOOD');
});

test('a call item that throws does not stop the next call item or a message, and still returns 200', function () {
    $x = wchScenario();
    $handler = wchCaptureLogs();
    LeadCall::creating(function (LeadCall $call): void {
        if ($call->external_call_id === 'wacid.BOOM') {
            throw new RuntimeException('BOOM-EXCEPTION-TEXT '.Wa::CALLER);
        }
    });

    wchSend($this, $x, Wa::payload(
        [Wa::connect(['id' => 'wacid.BOOM']), Wa::connect(['id' => 'wacid.OK', 'from' => '15550002222'])],
        ['messages' => [wchMessage('15550003333', 'wamid.M2', 'still delivered')]],
    ))->assertOk()->assertExactJson(['status' => 'ok']);

    expect(LeadCall::pluck('external_call_id')->all())->toBe(['wacid.OK']);
    expect(Message::sole()->body)->toBe('still delivered');

    $logs = wchLogText($handler);
    expect($logs)->toContain('WA CALL FAILED')->toContain('wacid.BOOM')->toContain(RuntimeException::class);
    expect($logs)->not->toContain('BOOM-EXCEPTION-TEXT')->not->toContain(Wa::CALLER);
});

test('a payload with more call items than the per-request ceiling skips every call but still processes messages', function () {
    config(['webhooks.max_inbound_messages_per_request' => 2]);
    $x = wchScenario();
    $handler = wchCaptureLogs();
    $calls = [
        Wa::connect(['id' => 'wacid.A', 'from' => '15550000001']),
        Wa::connect(['id' => 'wacid.B', 'from' => '15550000002']),
        Wa::connect(['id' => 'wacid.C', 'from' => '15550000003']),
    ];

    wchSend($this, $x, Wa::payload($calls, ['messages' => [wchMessage()]]))->assertOk();

    expect(LeadCall::count())->toBe(0);
    expect(Message::count())->toBe(1);
    expect(wchLogText($handler))->toContain('WA CALL CAP EXCEEDED');
});

test('a payload with exactly the ceiling of call items is processed', function () {
    config(['webhooks.max_inbound_messages_per_request' => 2]);
    $x = wchScenario();

    wchSend($this, $x, Wa::payload([
        Wa::connect(['id' => 'wacid.A', 'from' => '15550000001']),
        Wa::connect(['id' => 'wacid.B', 'from' => '15550000002']),
    ]))->assertOk();

    expect(LeadCall::count())->toBe(2);
});

test('the call cap counts across every entry and change of the request', function () {
    config(['webhooks.max_inbound_messages_per_request' => 2]);
    $x = wchScenario();
    $payload = [
        'object' => 'whatsapp_business_account',
        'entry' => [
            ['id' => 'W1', 'changes' => [Wa::change([Wa::connect(['id' => 'wacid.A', 'from' => '15550000001'])])]],
            ['id' => 'W2', 'changes' => [Wa::change([Wa::connect(['id' => 'wacid.B', 'from' => '15550000002']), Wa::connect(['id' => 'wacid.C', 'from' => '15550000003'])])]],
        ],
    ];

    wchSend($this, $x, $payload)->assertOk();

    expect(LeadCall::count())->toBe(0);
});

test('a message-only payload is untouched: no call rows and no call log lines', function () {
    $x = wchScenario();
    $handler = wchCaptureLogs();

    wchSend($this, $x, ['entry' => [['changes' => [['value' => ['messages' => [wchMessage()]]]]]]])->assertOk();

    expect(Lead::count())->toBe(1);
    expect(Message::count())->toBe(1);
    expect(LeadCall::count())->toBe(0);
    expect(wchLogText($handler))->not->toContain('WA CALL');
});

// ── Privacy ─────────────────────────────────────────────────────────────────

test('the SDP never reaches a database row, the lead, or any log line', function () {
    $x = wchScenario();
    $handler = wchCaptureLogs();
    $payload = Wa::payload([Wa::connect()]);

    wchSend($this, $x, $payload)->assertOk();
    wchSend($this, $x, $payload)->assertOk();
    wchSend($this, $x, Wa::payload([Wa::connect(['id' => 'wacid.BAD', 'session' => ['sdp_type' => 'offer', 'sdp' => 'not '.Wa::SDP_SENTINEL]])]))->assertOk();

    foreach (['lead_calls', 'leads', 'conversations', 'messages', 'lead_sources', 'notifications'] as $table) {
        expect(json_encode(DB::table($table)->get()))->not->toContain(Wa::SDP_SENTINEL);
    }

    expect(Lead::sole()->source_data)->toBeNull();
    expect(wchLogText($handler))->not->toContain(Wa::SDP_SENTINEL);
});

test('no call log line contains a full phone number', function () {
    $x = wchScenario();
    $handler = wchCaptureLogs();

    wchSend($this, $x, Wa::payload([Wa::connect()]))->assertOk();
    wchSend($this, $x, Wa::payload([Wa::connect()]))->assertOk();
    wchSend($this, $x, Wa::payload([Wa::terminate(['id' => 'wacid.OTHER'])]))->assertOk();
    wchSend($this, $x, Wa::payload([Wa::connect(['id' => 'wacid.BAD', 'session' => null])]))->assertOk();

    $logs = wchLogText($handler);

    expect($logs)->toContain('WA CALL');
    expect($logs)->not->toContain(Wa::CALLER)->not->toContain(Wa::BUSINESS_NUMBER);
});

test('every call log line carries only the tenant code, call id, event and outcome', function () {
    $x = wchScenario();
    $handler = wchCaptureLogs();

    wchSend($this, $x, Wa::payload([Wa::connect()]))->assertOk();

    $callRecords = collect($handler->getRecords())->filter(fn ($record): bool => $record->message === 'WA CALL');

    expect($callRecords)->toHaveCount(1);
    expect(array_keys($callRecords->first()->context))->toBe(['tenant_code', 'call_id', 'event', 'outcome']);
    expect($callRecords->first()->context)->toMatchArray([
        'tenant_code' => $x['tenant']->tenant_code,
        'call_id' => 'wacid.CALL-1',
        'event' => 'connect',
        'outcome' => 'recorded',
    ]);
});

test('an ignored call is logged with its reason and call id only', function () {
    $x = wchScenario(['calling_enabled' => false]);
    $handler = wchCaptureLogs();

    wchSend($this, $x, Wa::payload([Wa::connect()]))->assertOk();

    $logs = wchLogText($handler);

    expect($logs)->toContain('ignored_calling_disabled')->toContain('wacid.CALL-1');
    expect($logs)->not->toContain(Wa::SDP_SENTINEL)->not->toContain(Wa::CALLER);
});

// ── Calling switched off: new calls refused, an existing call still closed ──

test('a recorded ringing call is still closed by a terminate after calling is switched off, and nothing else changes', function () {
    $x = wchScenario();
    wchSend($this, $x, Wa::payload([Wa::connect()]))->assertOk();
    $leadsBefore = Lead::count();
    $x['source']->update(['calling_enabled' => false]);
    $observedBefore = $x['source']->fresh()->only(['calling_meta_status', 'calling_meta_checked_at']);

    wchSend($this, $x, Wa::payload([Wa::terminate(['status' => 'FAILED'])]))->assertOk();

    $call = LeadCall::sole();

    expect($call->status)->toBe(CallStatus::Missed);
    expect($call->end_reason)->toBe(CallEndReason::CallerEndedOrNotAnswered);
    expect($call->meta_terminated_at)->not->toBeNull();
    expect(Lead::count())->toBe($leadsBefore);
    expect($x['source']->fresh()->calling_enabled)->toBeFalse();
    expect($x['source']->fresh()->only(['calling_meta_status', 'calling_meta_checked_at']))->toEqual($observedBefore);
});

test('while calling is switched off a new connect is still ignored, and a duplicate terminate stays idempotent', function () {
    $x = wchScenario();
    wchSend($this, $x, Wa::payload([Wa::connect()]))->assertOk();
    $x['source']->update(['calling_enabled' => false]);
    wchSend($this, $x, Wa::payload([Wa::terminate(['status' => 'FAILED'])]))->assertOk();
    $afterFirst = LeadCall::sole()->getAttributes();

    wchSend($this, $x, Wa::payload([Wa::terminate(['status' => 'COMPLETED', 'duration' => 500])]))->assertOk();
    wchSend($this, $x, Wa::payload([Wa::connect(['id' => 'wacid.NEW-WHILE-OFF', 'from' => '15550009999'])]))->assertOk();

    expect(LeadCall::sole()->getAttributes())->toEqual($afterFirst);
    expect(LeadCall::count())->toBe(1);
    expect(Lead::count())->toBe(1);
});

test('a terminate for an unrecorded call while calling is switched off creates no lead, no call and no tombstone', function () {
    $x = wchScenario(['calling_enabled' => false]);

    wchSend($this, $x, Wa::payload([Wa::terminate()]))->assertOk();

    expect(Lead::count())->toBe(0);
    expect(LeadCall::count())->toBe(0);
    expect(Cache::has(RecordInboundCall::tombstoneCacheKey($x['source'], 'wacid.CALL-1')))->toBeFalse();
});

test('a terminate is still refused for a source without an app secret, even for a recorded call', function () {
    $x = wchScenario();
    wchSend($this, $x, Wa::payload([Wa::connect()]))->assertOk();
    $x['source']->update(['calling_enabled' => false, 'config' => ['phone_number_id' => Wa::PHONE_NUMBER_ID, 'verify_token' => 't']]);

    wchSend($this, $x, Wa::payload([Wa::terminate()]), signed: false)->assertOk();

    expect(LeadCall::sole()->status)->toBe(CallStatus::Ringing);
});

// ── An invalid event never marks a call id as seen and never blocks the real one ──

test('an invalid, unsigned or rejected delivery leaves no seen marker and no observed marker, and the real connect with that id is still accepted', function (string $case) {
    $x = wchScenario();
    $seenKey = RecordInboundCall::seenCacheKey($x['source'], 'wacid.PROBE');
    $valid = Wa::connect(['id' => 'wacid.PROBE']);
    $payload = Wa::payload([$valid]);
    $headers = Wa::signed($payload);

    match ($case) {
        'bad signature' => $headers = Wa::signed($payload, 'not-the-secret'),
        'unsigned' => $headers = [],
        'malformed call' => $payload = Wa::payload([Wa::connect(['id' => 'wacid.PROBE', 'timestamp' => 'not-a-timestamp'])]),
        'missing offer' => $payload = Wa::payload([Wa::connect(['id' => 'wacid.PROBE', 'session' => ['sdp_type' => 'offer']])]),
        'wrong direction' => $payload = Wa::payload([Wa::connect(['id' => 'wacid.PROBE', 'direction' => 'SIDEWAYS'])]),
        'business initiated' => $payload = Wa::payload([Wa::connect(['id' => 'wacid.PROBE', 'direction' => 'BUSINESS_INITIATED'])]),
        'no usable caller' => $payload = Wa::payload([array_diff_key($valid, ['from' => 1])], ['contacts' => []]),
        'phone_number_id mismatch' => $payload = Wa::payload([$valid], ['metadata' => ['phone_number_id' => '999', 'display_phone_number' => Wa::BUSINESS_NUMBER]]),
    };

    $this->postJson(route('api.webhook.whatsapp', $x['tenant']->tenant_code), $payload, $case === 'bad signature' || $case === 'unsigned' ? $headers : Wa::signed($payload));

    $source = $x['source']->fresh();

    expect(Cache::has($seenKey))->toBeFalse();
    expect($source->calling_meta_status)->toBeNull();
    expect($source->calling_meta_checked_at)->toBeNull();
    expect(Lead::count())->toBe(0);
    expect(LeadCall::count())->toBe(0);
    expect(Conversation::count())->toBe(0);

    $genuine = Wa::payload([$valid]);
    wchSend($this, $x, $genuine)->assertOk();

    expect(LeadCall::sole()->external_call_id)->toBe('wacid.PROBE');
    expect(LeadCall::sole()->status)->toBe(CallStatus::Ringing);
    expect(Cache::has($seenKey))->toBeTrue();
    expect($x['source']->fresh()->calling_meta_status)->toBe('ENABLED');
})->with([
    'bad signature',
    'unsigned',
    'malformed call',
    'missing offer',
    'wrong direction',
    'business initiated',
    'no usable caller',
    'phone_number_id mismatch',
]);

test('a webhook redelivered after the call and its lead were deleted recreates nothing', function () {
    Event::fake([LeadReceived::class]);
    $x = wchScenario();
    $payload = Wa::payload([Wa::connect()]);
    wchSend($this, $x, $payload)->assertOk();
    wchSend($this, $x, Wa::payload([Wa::terminate(['status' => 'FAILED'])]))->assertOk();
    Lead::sole()->delete();

    wchSend($this, $x, $payload)->assertOk()->assertExactJson(['status' => 'ok']);

    expect(Lead::count())->toBe(0);
    expect(LeadCall::count())->toBe(0);
    Event::assertDispatchedTimes(LeadReceived::class, 1);
});

// ── A redelivered connect for a merged identity ─────────────────────────────

test('redelivering a connect for a merged identity records nothing new and keeps the survivor as the owner', function () {
    $x = wchScenario();
    $loser = Lead::factory()->create(['tenant_id' => $x['tenant']->id, 'source_id' => $x['source']->id, 'phone' => Wa::CALLER, 'email' => 'dup@example.com']);
    Conversation::factory()->create([
        'tenant_id' => $x['tenant']->id,
        'lead_id' => $loser->id,
        'lead_source_id' => $x['source']->id,
        'channel' => MessageChannel::Whatsapp,
        'external_thread_id' => Wa::CALLER,
    ]);
    $survivor = Lead::factory()->create(['tenant_id' => $x['tenant']->id, 'source_id' => $x['source']->id, 'phone' => '15557770000', 'email' => 'dup@example.com']);
    app(LeadMergeService::class)->merge($survivor, $loser, $x['admin'], []);
    $payload = Wa::payload([Wa::connect()]);
    $offerKey = RecordInboundCall::offerCacheKey($x['source'], 'wacid.CALL-1');

    wchSend($this, $x, $payload)->assertOk();

    $call = LeadCall::sole();
    $snapshot = $call->only(['routing_mode', 'routed_user_ids', 'assignee_id_at_arrival', 'ringing_expires_at']);
    $offer = Cache::get($offerKey);
    $leadsBefore = Lead::count();

    wchSend($this, $x, $payload)->assertOk();
    wchSend($this, $x, $payload)->assertOk();

    expect($call->lead_id)->toBe($survivor->id);
    expect(LeadCall::count())->toBe(1);
    expect(Lead::count())->toBe($leadsBefore);
    expect(LeadCall::sole()->lead_id)->toBe($survivor->id);
    expect(LeadCall::sole()->only(['routing_mode', 'routed_user_ids', 'assignee_id_at_arrival', 'ringing_expires_at']))->toEqual($snapshot);
    expect(Conversation::count())->toBe(1);
    expect(Cache::get($offerKey))->toBe($offer);
    expect(Crypt::decryptString(Cache::get($offerKey)))->toBe(Wa::sdp());
});

test('a connect redelivered after its lead was merged away follows the call to the survivor and keeps its frozen routing', function () {
    $x = wchScenario();
    $assignee = clientUserWithLeadPermission($x['tenant'], ['view', 'update']);
    $loser = Lead::factory()->create(['tenant_id' => $x['tenant']->id, 'source_id' => $x['source']->id, 'phone' => Wa::CALLER, 'email' => 'dup@example.com', 'assigned_to' => $assignee->id]);
    $survivor = Lead::factory()->create(['tenant_id' => $x['tenant']->id, 'source_id' => $x['source']->id, 'phone' => '15557770000', 'email' => 'dup@example.com']);
    $payload = Wa::payload([Wa::connect()]);
    wchSend($this, $x, $payload)->assertOk();
    $snapshot = LeadCall::sole()->only(['routing_mode', 'routed_user_ids', 'assignee_id_at_arrival']);
    $offerKey = RecordInboundCall::offerCacheKey($x['source'], 'wacid.CALL-1');
    $offer = Cache::get($offerKey);
    expect(LeadCall::sole()->lead_id)->toBe($loser->id);

    app(LeadMergeService::class)->merge($survivor, $loser, $x['admin'], []);
    $leadsBefore = Lead::count();
    wchSend($this, $x, $payload)->assertOk();

    expect(LeadCall::count())->toBe(1);
    expect(LeadCall::sole()->lead_id)->toBe($survivor->id);
    expect(Lead::count())->toBe($leadsBefore);
    expect(LeadCall::sole()->only(['routing_mode', 'routed_user_ids', 'assignee_id_at_arrival']))->toEqual($snapshot);
    expect(Cache::get($offerKey))->toBe($offer);
});

// ── A call-only lead and the existing composer rule ─────────────────────────

/**
 * The composer's eligibility on the real Show component for a WhatsApp lead
 * whose only activity is a call, `$ageSeconds` old.
 *
 * @return array{lead: Lead, eligibility: mixed}
 */
function wchCallOnlyComposer($test, int $ageSeconds): array
{
    $x = wchScenario(config: ['access_token' => 'meta-access-token']);
    $lead = Lead::factory()->create(['tenant_id' => $x['tenant']->id, 'source_id' => $x['source']->id, 'phone' => Wa::CALLER]);
    $call = Wa::connect(['timestamp' => (string) (Carbon::now()->getTimestamp() - $ageSeconds)]);

    wchSend($test, $x, Wa::payload([$call]))->assertOk();

    $component = Livewire::actingAs($x['admin'])->test(Show::class, ['lead' => $lead->fresh()])->call('completeInitialLoad');

    return ['lead' => $lead->fresh(), 'eligibility' => $component->instance()->messagingEligibility, 'x' => $x];
}

test('a call-only lead has a conversation but no message, and the composer follows the existing 24-hour window', function (int $ageSeconds, bool $eligible, ?string $reason) {
    $this->freezeSecond();

    ['lead' => $lead, 'eligibility' => $eligibility] = wchCallOnlyComposer($this, $ageSeconds);

    expect(Conversation::sole()->channel)->toBe(MessageChannel::Whatsapp);
    expect(Conversation::sole()->lead_id)->toBe($lead->id);
    expect(Message::count())->toBe(0);
    expect($lead->messageTimeline())->toBe([]);
    expect($eligibility->eligible)->toBe($eligible);
    expect($eligibility->reason)->toBe($reason);
})->with([
    'a call that just rang opens the window' => [0, true, null],
    'a stale call from 23h59m ago leaves a minute of window' => [86340, true, null],
    'a stale call from 24h01m ago has an expired window' => [86460, false, 'The reply window has expired.'],
]);

test('a call does not authorize outbound messaging: withdrawn privacy consent still blocks the composer', function () {
    $x = wchScenario(config: ['access_token' => 'meta-access-token']);
    $lead = Lead::factory()->create(['tenant_id' => $x['tenant']->id, 'source_id' => $x['source']->id, 'phone' => Wa::CALLER]);
    app(RecordLeadConsent::class)->handle(lead: $lead, type: ConsentType::Privacy, status: ConsentStatus::Withdrawn, source: ConsentSource::Manual, changedBy: $x['admin']->id);

    wchSend($this, $x, Wa::payload([Wa::connect()]))->assertOk();

    $eligibility = Livewire::actingAs($x['admin'])->test(Show::class, ['lead' => $lead->fresh()])->call('completeInitialLoad')->instance()->messagingEligibility;

    // The inbound call is still recorded and routed...
    expect(LeadCall::sole()->status)->toBe(CallStatus::Ringing);
    // ...but it neither opens the composer nor creates a message.
    expect($eligibility->eligible)->toBeFalse();
    expect($eligibility->reason)->toContain('Privacy consent');
    expect(Message::count())->toBe(0);
});

// ── The cached SDP survives Laravel's TrimStrings middleware intact ─────────

/**
 * Posts a signed connect carrying `$sdp` through the real HTTP stack (so the
 * framework's string trimming runs) and returns the decrypted cached offer.
 */
function wchCachedOfferFor($test, string $sdp): string
{
    $x = wchScenario();
    $item = Wa::connect(['session' => ['sdp_type' => 'offer', 'sdp' => $sdp]]);

    wchSend($test, $x, Wa::payload([$item]))->assertOk();

    $cached = Cache::get(RecordInboundCall::offerCacheKey($x['source'], 'wacid.CALL-1'));

    expect($cached)->toBeString();

    return Crypt::decryptString($cached);
}

test('an SDP ending in CRLF is cached exactly as Meta sent it, even though the request body is trimmed', function () {
    $cached = wchCachedOfferFor($this, Wa::sdp());

    expect($cached)->toBe(Wa::sdp());
    expect($cached)->toEndWith("\r\n");
    expect($cached)->not->toEndWith("\r\n\r\n");
});

test('an SDP with no terminal newline is cached with exactly one CRLF restored', function () {
    $noTerminator = rtrim(Wa::sdp());

    $cached = wchCachedOfferFor($this, $noTerminator);

    expect($cached)->toBe($noTerminator."\r\n");
    expect($cached)->not->toEndWith("\r\n\r\n");
});

// Laravel's TrimStrings middleware strips ALL trailing whitespace from the JSON
// body, so whatever run of terminators Meta sent, the parser receives none and
// the call-specific normalization restores exactly one CRLF. (The parser itself
// never collapses terminators: see the unit test that feeds it a doubled CRLF.)
test('the framework trims every trailing terminator from the JSON body, so exactly one CRLF is restored before caching', function () {
    $cached = wchCachedOfferFor($this, rtrim(Wa::sdp())."\r\n\r\n\r\n");

    expect($cached)->toBe(Wa::sdp());
});

test('the SDP is only ever in the encrypted cache: not in the database, not in any log, not in the raw cache value', function () {
    $x = wchScenario();
    $handler = wchCaptureLogs();

    wchSend($this, $x, Wa::payload([Wa::connect()]))->assertOk();
    wchSend($this, $x, Wa::payload([Wa::connect()]))->assertOk();

    $raw = Cache::get(RecordInboundCall::offerCacheKey($x['source'], 'wacid.CALL-1'));

    expect($raw)->toBeString()->not->toContain(Wa::SDP_SENTINEL);
    expect(Crypt::decryptString($raw))->toContain(Wa::SDP_SENTINEL);

    foreach (['lead_calls', 'leads', 'conversations', 'messages', 'lead_sources', 'notifications'] as $table) {
        expect(json_encode(DB::table($table)->get()))->not->toContain(Wa::SDP_SENTINEL);
    }

    expect(Lead::sole()->source_data)->toBeNull();
    expect(wchLogText($handler))->not->toContain(Wa::SDP_SENTINEL);
});

test('an invalid or oversized SDP is still rejected through the full webhook, whatever its terminator', function (string $sdp) {
    $x = wchScenario();

    wchSend($this, $x, Wa::payload([Wa::connect(['session' => ['sdp_type' => 'offer', 'sdp' => $sdp]])]))->assertOk();

    expect(Lead::count())->toBe(0);
    expect(LeadCall::count())->toBe(0);
})->with([
    'not SDP, unterminated' => ['GET / HTTP/1.1'],
    'not SDP, terminated' => ["GET / HTTP/1.1\r\n"],
    'oversized' => ["v=0\r\n".str_repeat('a', 20000)],
]);
