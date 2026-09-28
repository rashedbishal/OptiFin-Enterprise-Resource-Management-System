<?php

use App\Actions\Tenant\RecordOutboundCallEvent;
use App\Actions\Tenant\RecordOutboundCallStatusEvent;
use App\Enums\CallDirection;
use App\Enums\CallEndReason;
use App\Enums\CallStatus;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Support\WhatsAppCallStatusEvent;
use App\Support\WhatsAppOutboundCallEvent;
use Illuminate\Support\Facades\Cache;
use Tests\Support\WhatsAppCallFixtures as Wa;

/*
 * WhatsApp Outbound Calling — RecordOutboundCallStatusEvent applies Meta's
 * mid-call RINGING/ACCEPTED/REJECTED status (a `statuses[]` item whose
 * `type` is `call` — a completely different array/shape from the `calls[]`
 * connect/terminate items RecordOutboundCallEvent handles). ACCEPTED is the
 * one authoritative "the consumer answered" signal this app previously had
 * no source for; it only ever records consumer_accepted_at — it never sets
 * Connected/connected_at itself and never broadcasts.
 */

function rocseScenario(array $callOverrides = []): array
{
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $agent = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $source = Wa::source($tenant);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'phone' => Wa::CALLER]);

    $call = LeadCall::factory()->outbound()->create(array_merge([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'lead_source_id' => $source->id,
        'external_call_id' => 'wacid.STATUS-1',
        'status' => CallStatus::Answering,
        'claimed_by' => $agent->id,
        'routed_user_ids' => [$agent->id],
    ], $callOverrides));

    return ['tenant' => $tenant, 'agent' => $agent, 'source' => $source, 'lead' => $lead, 'call' => $call];
}

function rocseStatus(string $status, string $callId = 'wacid.STATUS-1'): WhatsAppCallStatusEvent
{
    $event = WhatsAppCallStatusEvent::fromWebhook(['id' => $callId, 'type' => 'call', 'status' => $status, 'timestamp' => (string) now()->timestamp, 'recipient_id' => Wa::CALLER]);

    expect($event)->toBeInstanceOf(WhatsAppCallStatusEvent::class);

    return $event;
}

// ── WhatsAppCallStatusEvent (the parser) ────────────────────────────────────

test('a well-formed RINGING/ACCEPTED/REJECTED item parses with its own isX() helper true', function (string $status, string $method) {
    $event = rocseStatus($status);

    expect($event->status)->toBe($status);
    expect($event->{$method}())->toBeTrue();
})->with([
    ['RINGING', 'isRinging'],
    ['ACCEPTED', 'isAccepted'],
    ['REJECTED', 'isRejected'],
]);

test('status is normalized (case, surrounding whitespace)', function () {
    $event = WhatsAppCallStatusEvent::fromWebhook(['id' => 'wacid.X', 'type' => 'call', 'status' => ' accepted ']);

    expect($event)->toBeInstanceOf(WhatsAppCallStatusEvent::class);
    expect($event->isAccepted())->toBeTrue();
});

test('a non-array item is malformed', function () {
    expect(WhatsAppCallStatusEvent::fromWebhook('not-an-array'))->toBe(WhatsAppCallStatusEvent::REASON_MALFORMED);
});

test('a `type` other than `call` (e.g. an ordinary message-delivery status item) is never parsed here', function () {
    expect(WhatsAppCallStatusEvent::fromWebhook(['id' => 'wamid.X', 'status' => 'delivered']))->toBe(WhatsAppCallStatusEvent::REASON_NOT_A_CALL_STATUS);
});

test('a missing or oversized call id is rejected', function (mixed $id) {
    expect(WhatsAppCallStatusEvent::fromWebhook(['id' => $id, 'type' => 'call', 'status' => 'ACCEPTED']))->toBe(WhatsAppCallStatusEvent::REASON_MISSING_ID);
})->with([
    'missing' => [null],
    'empty' => [''],
    'oversized' => [str_repeat('x', 192)],
]);

test('an unsupported status value is rejected', function () {
    expect(WhatsAppCallStatusEvent::fromWebhook(['id' => 'wacid.X', 'type' => 'call', 'status' => 'IN_PROGRESS']))->toBe(WhatsAppCallStatusEvent::REASON_UNSUPPORTED_STATUS);
});

// ── RecordOutboundCallStatusEvent (the writer) ──────────────────────────────

test('RINGING is observed but never changes status, connected_at or consumer_accepted_at', function () {
    $x = rocseScenario();

    $outcome = app(RecordOutboundCallStatusEvent::class)->handle(rocseStatus('RINGING'), $x['source']);

    expect($outcome)->toBe(RecordOutboundCallStatusEvent::OUTCOME_RINGING_OBSERVED);
    $row = $x['call']->fresh();
    expect($row->status)->toBe(CallStatus::Answering);
    expect($row->connected_at)->toBeNull();
    expect($row->consumer_accepted_at)->toBeNull();
});

test('ACCEPTED sets consumer_accepted_at exactly once, and never touches status or connected_at', function () {
    $x = rocseScenario();

    $outcome = app(RecordOutboundCallStatusEvent::class)->handle(rocseStatus('ACCEPTED'), $x['source']);

    expect($outcome)->toBe(RecordOutboundCallStatusEvent::OUTCOME_ACCEPTED);
    $row = $x['call']->fresh();
    expect($row->consumer_accepted_at)->not->toBeNull();
    expect($row->status)->toBe(CallStatus::Answering);
    expect($row->connected_at)->toBeNull();
});

test('a duplicate ACCEPTED delivery is idempotent — the timestamp never moves', function () {
    $x = rocseScenario();

    app(RecordOutboundCallStatusEvent::class)->handle(rocseStatus('ACCEPTED'), $x['source']);
    $firstAcceptedAt = $x['call']->fresh()->consumer_accepted_at;

    $outcome = app(RecordOutboundCallStatusEvent::class)->handle(rocseStatus('ACCEPTED'), $x['source']);

    expect($outcome)->toBe(RecordOutboundCallStatusEvent::OUTCOME_ACCEPTED_NOOP);
    expect($x['call']->fresh()->consumer_accepted_at->eq($firstAcceptedAt))->toBeTrue();
});

test('ACCEPTED for a call that already moved on (Connected) is a no-op, never overwritten', function () {
    $x = rocseScenario(['status' => CallStatus::Connected, 'connected_at' => now()->subSeconds(10)]);

    $outcome = app(RecordOutboundCallStatusEvent::class)->handle(rocseStatus('ACCEPTED'), $x['source']);

    expect($outcome)->toBe(RecordOutboundCallStatusEvent::OUTCOME_ACCEPTED_NOOP);
    expect($x['call']->fresh()->consumer_accepted_at)->toBeNull();
});

test('REJECTED never changes status, end_reason or connected_at — the terminate webhook remains the sole terminal-transition authority', function () {
    $x = rocseScenario();

    $outcome = app(RecordOutboundCallStatusEvent::class)->handle(rocseStatus('REJECTED'), $x['source']);

    expect($outcome)->toBe(RecordOutboundCallStatusEvent::OUTCOME_REJECTED_OBSERVED);
    $row = $x['call']->fresh();
    expect($row->status)->toBe(CallStatus::Answering);
    expect($row->end_reason)->toBeNull();
    expect($row->connected_at)->toBeNull();
});

test('an unknown wacid is a no-op — no row is created or modified', function () {
    $x = rocseScenario();

    $outcome = app(RecordOutboundCallStatusEvent::class)->handle(rocseStatus('ACCEPTED', 'wacid.NEVER-RECORDED'), $x['source']);

    expect($outcome)->toBe(RecordOutboundCallStatusEvent::OUTCOME_UNKNOWN_CALL);
    expect(LeadCall::count())->toBe(1);
    expect($x['call']->fresh()->consumer_accepted_at)->toBeNull();
});

test('a wacid belonging to a different tenant/source is never touched', function () {
    $a = rocseScenario();
    $b = rocseScenario();
    // Same external_call_id, different tenant/source entirely.
    $b['call']->forceFill(['external_call_id' => $a['call']->external_call_id])->save();

    app(RecordOutboundCallStatusEvent::class)->handle(rocseStatus('ACCEPTED', $a['call']->external_call_id), $a['source']);

    expect($a['call']->fresh()->consumer_accepted_at)->not->toBeNull();
    expect($b['call']->fresh()->consumer_accepted_at)->toBeNull();
});

test('an inbound call sharing the same external_call_id (on a different source) is never touched — the lookup is strictly outbound-only', function () {
    $x = rocseScenario(['external_call_id' => 'wacid.SHARED-DIRECTION']);
    $otherSource = Wa::source($x['tenant']);
    $inbound = LeadCall::factory()->create([
        'tenant_id' => $x['tenant']->id,
        'lead_id' => $x['lead']->id,
        'lead_source_id' => $otherSource->id,
        'direction' => CallDirection::Inbound,
        'external_call_id' => 'wacid.SHARED-DIRECTION',
        'status' => CallStatus::Answering,
    ]);

    app(RecordOutboundCallStatusEvent::class)->handle(rocseStatus('ACCEPTED', 'wacid.SHARED-DIRECTION'), $x['source']);

    expect($x['call']->fresh()->consumer_accepted_at)->not->toBeNull();
    expect($inbound->fresh()->consumer_accepted_at)->toBeNull();
});

// ── REJECTED → terminate's CallEndReason refinement ─────────────────────────

test('REJECTED followed by a terminate uses ConsumerDeclinedOutbound instead of the generic OutboundConnectFailed', function () {
    $x = rocseScenario();

    app(RecordOutboundCallStatusEvent::class)->handle(rocseStatus('REJECTED'), $x['source']);

    $item = Wa::terminate(['id' => 'wacid.STATUS-1', 'direction' => 'BUSINESS_INITIATED', 'timestamp' => (string) now()->timestamp, 'status' => 'REJECTED']);
    $event = WhatsAppOutboundCallEvent::fromWebhook(['value' => []], $item);
    expect($event)->toBeInstanceOf(WhatsAppOutboundCallEvent::class);

    $outcome = app(RecordOutboundCallEvent::class)->handle($event, $x['source']);

    expect($outcome)->toBe(RecordOutboundCallEvent::OUTCOME_TERMINATE_APPLIED);
    $row = $x['call']->fresh();
    expect($row->status)->toBe(CallStatus::Failed);
    expect($row->end_reason)->toBe(CallEndReason::ConsumerDeclinedOutbound);
});

test('without a prior REJECTED, the same terminate falls back to the generic OutboundConnectFailed exactly as before', function () {
    $x = rocseScenario();

    $item = Wa::terminate(['id' => 'wacid.STATUS-1', 'direction' => 'BUSINESS_INITIATED', 'timestamp' => (string) now()->timestamp, 'status' => 'COMPLETED']);
    $event = WhatsAppOutboundCallEvent::fromWebhook(['value' => []], $item);

    app(RecordOutboundCallEvent::class)->handle($event, $x['source']);

    expect($x['call']->fresh()->end_reason)->toBe(CallEndReason::OutboundConnectFailed);
});

test('the REJECTED marker is consumed exactly once — a second terminate-eligible read never reuses it', function () {
    $x = rocseScenario();
    app(RecordOutboundCallStatusEvent::class)->handle(rocseStatus('REJECTED'), $x['source']);

    expect(Cache::has(RecordOutboundCallStatusEvent::declineCacheKey($x['source'], 'wacid.STATUS-1')))->toBeTrue();

    $item = Wa::terminate(['id' => 'wacid.STATUS-1', 'direction' => 'BUSINESS_INITIATED', 'timestamp' => (string) now()->timestamp, 'status' => 'REJECTED']);
    $event = WhatsAppOutboundCallEvent::fromWebhook(['value' => []], $item);
    app(RecordOutboundCallEvent::class)->handle($event, $x['source']);

    expect(Cache::has(RecordOutboundCallStatusEvent::declineCacheKey($x['source'], 'wacid.STATUS-1')))->toBeFalse();
});
