<?php

use App\Actions\Tenant\RecordOutboundCallEvent;
use App\Enums\CallDirection;
use App\Enums\CallEndReason;
use App\Enums\CallStatus;
use App\Enums\MessageChannel;
use App\Enums\MessageStatus;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Models\Message;
use App\Support\LeadCallSdp;
use App\Support\WhatsAppOutboundCallEvent;
use Illuminate\Support\Carbon;
use Tests\Support\WhatsAppCallFixtures as Wa;

/*
 * WhatsApp Outbound Calling — the `direction: BUSINESS_INITIATED` branch of
 * the EXISTING calls[] webhook handling. Every existing inbound `connect`/
 * `terminate` (USER_INITIATED) behavior must stay byte-for-byte unaffected —
 * these tests only exercise the new outbound branch.
 */

function owhScenario(array $callOverrides = []): array
{
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = Wa::source($tenant);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'phone' => Wa::CALLER]);

    $call = LeadCall::factory()->create(array_merge([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'lead_source_id' => $source->id,
        'direction' => CallDirection::Outbound,
        'external_call_id' => 'wacid.OUTWH-1',
        'status' => CallStatus::Ringing,
        'ringing_expires_at' => now()->addSeconds(60),
    ], $callOverrides));

    return ['tenant' => $tenant, 'source' => $source, 'lead' => $lead, 'call' => $call];
}

/**
 * @return array<string, mixed>
 */
function owhOutboundConnect(string $callId, string $answer, ?int $timestamp = null): array
{
    return [
        'id' => $callId,
        'to' => Wa::CALLER,
        'from' => Wa::BUSINESS_NUMBER,
        'event' => 'connect',
        'direction' => 'BUSINESS_INITIATED',
        'timestamp' => (string) ($timestamp ?? Carbon::now()->getTimestamp()),
        'session' => ['sdp_type' => 'answer', 'sdp' => $answer],
    ];
}

/**
 * @return array<string, mixed>
 */
function owhOutboundTerminate(string $callId, bool $hasErrors = false, ?int $timestamp = null): array
{
    $item = [
        'id' => $callId,
        'to' => Wa::CALLER,
        'from' => Wa::BUSINESS_NUMBER,
        'event' => 'terminate',
        'direction' => 'BUSINESS_INITIATED',
        'timestamp' => (string) ($timestamp ?? Carbon::now()->getTimestamp()),
        'status' => 'COMPLETED',
    ];

    if ($hasErrors) {
        $item['errors'] = [['code' => 138000, 'message' => 'x']];
    }

    return $item;
}

/**
 * @param  array<string, mixed>  $x
 * @param  array<int, mixed>  $calls
 */
function owhSend($test, array $x, array $calls)
{
    $payload = Wa::payload($calls);

    return $test->postJson(route('api.webhook.whatsapp', $x['tenant']->tenant_code), $payload, Wa::signed($payload));
}

test('a valid outbound answer transitions Ringing to Answering and caches the answer encrypted — never Connected directly', function () {
    $x = owhScenario();

    owhSend($this, $x, [owhOutboundConnect('wacid.OUTWH-1', Wa::sdp())])->assertOk()->assertExactJson(['status' => 'ok']);

    $row = $x['call']->fresh();
    expect($row->status)->toBe(CallStatus::Answering);
    // Meta's SDP answer arriving is not evidence the consumer physically
    // answered — connected_at is written only by ConfirmOutboundCallConnected,
    // driven by the browser's own real WebRTC connection.
    expect($row->connected_at)->toBeNull();
    expect(LeadCallSdp::readAnswer($x['source'], 'wacid.OUTWH-1'))->toContain('SENTINEL-SDP');
});

test('a duplicate answer delivery is idempotent — never re-applied once Answering', function () {
    $x = owhScenario();

    owhSend($this, $x, [owhOutboundConnect('wacid.OUTWH-1', Wa::sdp())])->assertOk();
    $updatedAt = $x['call']->fresh()->updated_at;

    owhSend($this, $x, [owhOutboundConnect('wacid.OUTWH-1', Wa::sdp())])->assertOk();

    $row = $x['call']->fresh();
    expect($row->status)->toBe(CallStatus::Answering);
    expect($row->connected_at)->toBeNull();
    expect($row->updated_at->eq($updatedAt))->toBeTrue();
});

test('an invalid answer SDP does not transition the call and never throws', function () {
    $x = owhScenario();

    owhSend($this, $x, [owhOutboundConnect('wacid.OUTWH-1', 'not an sdp')])->assertOk()->assertExactJson(['status' => 'ok']);

    expect($x['call']->fresh()->status)->toBe(CallStatus::Ringing);
});

test('an unknown wacid is ignored — no row is created or modified', function () {
    $x = owhScenario();

    owhSend($this, $x, [owhOutboundConnect('wacid.NEVER-RECORDED', Wa::sdp())])->assertOk();

    expect(LeadCall::count())->toBe(1);
    expect($x['call']->fresh()->status)->toBe(CallStatus::Ringing);
});

test('a wacid belonging to a different tenant/source is never touched', function () {
    $a = owhScenario(['external_call_id' => 'wacid.SHARED-ID']);
    $b = owhScenario(['external_call_id' => 'wacid.SHARED-ID']);

    owhSend($this, $a, [owhOutboundConnect('wacid.SHARED-ID', Wa::sdp())])->assertOk();

    expect($a['call']->fresh()->status)->toBe(CallStatus::Answering);
    expect($b['call']->fresh()->status)->toBe(CallStatus::Ringing);
});

test('a terminate while still Ringing (never answered) ends the call as Failed / consumer_no_answer_outbound', function () {
    $x = owhScenario();

    owhSend($this, $x, [owhOutboundTerminate('wacid.OUTWH-1')])->assertOk();

    $row = $x['call']->fresh();
    expect($row->status)->toBe(CallStatus::Failed);
    expect($row->end_reason)->toBe(CallEndReason::ConsumerNoAnswerOutbound);
});

test('a terminate with errors while Ringing ends the call as Failed / outbound_connect_failed', function () {
    $x = owhScenario();

    owhSend($this, $x, [owhOutboundTerminate('wacid.OUTWH-1', hasErrors: true)])->assertOk();

    expect($x['call']->fresh()->end_reason)->toBe(CallEndReason::OutboundConnectFailed);
});

test('a terminate while Answering (browser never confirmed the WebRTC connection) ends the call as Failed / outbound_connect_failed', function () {
    $x = owhScenario(['status' => CallStatus::Answering]);

    owhSend($this, $x, [owhOutboundTerminate('wacid.OUTWH-1')])->assertOk();

    $row = $x['call']->fresh();
    expect($row->status)->toBe(CallStatus::Failed);
    expect($row->end_reason)->toBe(CallEndReason::OutboundConnectFailed);
    expect($row->connected_at)->toBeNull();
});

test('a terminate after Connected ends the call as Completed / remote_terminated', function () {
    $x = owhScenario(['status' => CallStatus::Connected, 'connected_at' => now()->subSeconds(30)]);

    owhSend($this, $x, [owhOutboundTerminate('wacid.OUTWH-1')])->assertOk();

    $row = $x['call']->fresh();
    expect($row->status)->toBe(CallStatus::Completed);
    expect($row->end_reason)->toBe(CallEndReason::RemoteTerminated);
});

test('a repeated terminate on an already-terminal call only fills missing fields, never changes status again', function () {
    $x = owhScenario(['status' => CallStatus::Failed, 'end_reason' => CallEndReason::AgentCancelledOutbound, 'ended_at' => now()->subMinute()]);
    $originalEndedAt = $x['call']->ended_at;

    owhSend($this, $x, [owhOutboundTerminate('wacid.OUTWH-1')])->assertOk();

    $row = $x['call']->fresh();
    expect($row->status)->toBe(CallStatus::Failed);
    expect($row->end_reason)->toBe(CallEndReason::AgentCancelledOutbound);
    expect($row->ended_at->eq($originalEndedAt))->toBeTrue();
});

// ── RecordOutboundCallEvent::fillOnly() — compare-and-set guard ────────────
// A terminate delivery for an already-terminal call whose terminal metadata
// (meta_status/ended_at/duration_seconds) is still missing is filled in by
// fillOnly(). A second, concurrent delivery reading the row before the
// first's write commits must not be able to overwrite it — see
// RecordOutboundCallEvent::fillOnly()'s own docblock.

test('the first terminate on an already-terminal call with no terminal metadata yet fills every missing field', function () {
    $x = owhScenario(['status' => CallStatus::Failed, 'end_reason' => CallEndReason::AgentCancelledOutbound]);
    expect($x['call']->meta_status)->toBeNull();
    expect($x['call']->ended_at)->toBeNull();
    expect($x['call']->duration_seconds)->toBeNull();

    $payload = Wa::payload([[
        'id' => 'wacid.OUTWH-1', 'to' => Wa::CALLER, 'from' => Wa::BUSINESS_NUMBER,
        'event' => 'terminate', 'direction' => 'BUSINESS_INITIATED',
        'timestamp' => (string) now()->getTimestamp(), 'status' => 'COMPLETED', 'duration' => 45,
    ]]);
    $this->postJson(route('api.webhook.whatsapp', $x['tenant']->tenant_code), $payload, Wa::signed($payload))->assertOk();

    $row = $x['call']->fresh();
    expect($row->status)->toBe(CallStatus::Failed);
    expect($row->end_reason)->toBe(CallEndReason::AgentCancelledOutbound);
    expect($row->meta_status)->toBe('COMPLETED');
    expect($row->duration_seconds)->toBe(45);
    expect($row->ended_at)->not->toBeNull();
});

test('a stale read racing a concurrent winner cannot clobber the winner\'s terminal metadata — the SQL-level CAS guard, not just the in-memory null-check', function () {
    $x = owhScenario(['status' => CallStatus::Failed, 'end_reason' => CallEndReason::AgentCancelledOutbound]);

    // This writer's own snapshot is read BEFORE a concurrent winner commits
    // its own fillOnly() write — the exact race window the fix closes.
    $staleCall = $x['call']->fresh();

    // The concurrent winner commits first, with its own values.
    LeadCall::query()->whereKey($x['call']->id)->update([
        'meta_status' => 'WINNER_STATUS',
        'ended_at' => now()->subSeconds(5),
        'duration_seconds' => 111,
    ]);
    $winnerEndedAt = $x['call']->fresh()->ended_at;

    $change = ['value' => []];
    $item = [
        'id' => 'wacid.OUTWH-1', 'event' => 'terminate', 'direction' => 'BUSINESS_INITIATED',
        'timestamp' => (string) now()->getTimestamp(), 'status' => 'CONFLICTING_LOSER_STATUS', 'duration' => 999,
    ];
    $event = WhatsAppOutboundCallEvent::fromWebhook($change, $item);
    expect($event)->toBeInstanceOf(WhatsAppOutboundCallEvent::class);

    $method = new ReflectionMethod(RecordOutboundCallEvent::class, 'fillOnly');
    $method->setAccessible(true);
    $outcome = $method->invoke(new RecordOutboundCallEvent, $staleCall, $event, Carbon::now());

    expect($outcome)->toBe(RecordOutboundCallEvent::OUTCOME_TERMINATE_NOOP);

    $row = $x['call']->fresh();
    expect($row->status)->toBe(CallStatus::Failed);
    expect($row->end_reason)->toBe(CallEndReason::AgentCancelledOutbound);
    expect($row->meta_status)->toBe('WINNER_STATUS');
    expect($row->duration_seconds)->toBe(111);
    expect($row->ended_at->eq($winnerEndedAt))->toBeTrue();
});

test('an inbound (USER_INITIATED) call in the very same payload is completely unaffected by the new outbound branch', function () {
    $x = owhScenario();

    owhSend($this, $x, [
        owhOutboundConnect('wacid.OUTWH-1', Wa::sdp()),
        Wa::connect(['id' => 'wacid.INBOUND-1']),
    ])->assertOk();

    expect($x['call']->fresh()->status)->toBe(CallStatus::Answering);
    expect(LeadCall::where('external_call_id', 'wacid.INBOUND-1')->where('direction', CallDirection::Inbound)->exists())->toBeTrue();
});

// ── WhatsApp Outbound Calling — the `statuses[]` `type: "call"` branch ─────
// A completely different webhook array from `calls[]` above, sharing only
// the SAME `statuses[]` key that Feature #15's message-delivery/read
// receipts already use — see LeadWebhookController::whatsapp(), which
// routes a `type: "call"` item here BEFORE it ever reaches
// dispatchWhatsappStatusReconciliation().

test('a `type: "call"` ACCEPTED status item records consumer_accepted_at and never reaches message-status reconciliation', function () {
    $x = owhScenario(['status' => CallStatus::Answering]);

    $payload = Wa::payload([], ['statuses' => [
        ['id' => 'wacid.OUTWH-1', 'type' => 'call', 'status' => 'ACCEPTED', 'timestamp' => (string) now()->timestamp, 'recipient_id' => Wa::CALLER],
    ]]);
    $this->postJson(route('api.webhook.whatsapp', $x['tenant']->tenant_code), $payload, Wa::signed($payload))
        ->assertOk()->assertExactJson(['status' => 'ok']);

    $row = $x['call']->fresh();
    expect($row->consumer_accepted_at)->not->toBeNull();
    expect($row->status)->toBe(CallStatus::Answering);
    expect($row->connected_at)->toBeNull();
});

test('a real message-delivery status item in the SAME payload as a call-status item is completely unaffected — each is routed to its own handler', function () {
    $x = owhScenario(['status' => CallStatus::Answering]);
    $conversation = Conversation::factory()->create(['tenant_id' => $x['tenant']->id, 'lead_id' => $x['lead']->id, 'lead_source_id' => $x['source']->id]);
    $message = Message::factory()->outbound()->withStatus(MessageStatus::Sent)->withExternalId('wamid.PARALLEL')->create([
        'tenant_id' => $x['tenant']->id,
        'conversation_id' => $conversation->id,
        'lead_id' => $x['lead']->id,
        'channel' => MessageChannel::Whatsapp,
    ]);

    $payload = Wa::payload([], ['statuses' => [
        ['id' => 'wamid.PARALLEL', 'status' => 'delivered', 'timestamp' => (string) now()->timestamp],
        ['id' => 'wacid.OUTWH-1', 'type' => 'call', 'status' => 'ACCEPTED', 'timestamp' => (string) now()->timestamp, 'recipient_id' => Wa::CALLER],
    ]]);
    $this->postJson(route('api.webhook.whatsapp', $x['tenant']->tenant_code), $payload, Wa::signed($payload))->assertOk();

    expect($message->fresh()->status)->toBe(MessageStatus::Delivered);
    expect($x['call']->fresh()->consumer_accepted_at)->not->toBeNull();
});

test('RINGING and REJECTED call-status items are safely ignored by message-status reconciliation and change nothing but what RecordOutboundCallStatusEvent itself changes', function () {
    $x = owhScenario(['status' => CallStatus::Answering]);

    $payload = Wa::payload([], ['statuses' => [
        ['id' => 'wacid.OUTWH-1', 'type' => 'call', 'status' => 'RINGING', 'timestamp' => (string) now()->timestamp, 'recipient_id' => Wa::CALLER],
    ]]);
    $this->postJson(route('api.webhook.whatsapp', $x['tenant']->tenant_code), $payload, Wa::signed($payload))->assertOk();

    $row = $x['call']->fresh();
    expect($row->status)->toBe(CallStatus::Answering);
    expect($row->consumer_accepted_at)->toBeNull();
});

test('a malformed call-status item is safely ignored, never throws, and never touches the row', function () {
    $x = owhScenario(['status' => CallStatus::Answering]);

    $payload = Wa::payload([], ['statuses' => [
        ['id' => 'wacid.OUTWH-1', 'type' => 'call', 'status' => 'SOMETHING_UNKNOWN'],
    ]]);
    $this->postJson(route('api.webhook.whatsapp', $x['tenant']->tenant_code), $payload, Wa::signed($payload))
        ->assertOk()->assertExactJson(['status' => 'ok']);

    expect($x['call']->fresh()->consumer_accepted_at)->toBeNull();
});
