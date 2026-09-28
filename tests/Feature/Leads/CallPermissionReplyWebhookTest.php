<?php

use App\Enums\LeadCallPermissionStatus;
use App\Models\Lead;
use App\Models\LeadCallPermission;
use App\Models\LeadSource;
use App\Models\Message;
use Illuminate\Support\Carbon;
use Tests\Support\WhatsAppCallFixtures as Wa;

/*
 * WhatsApp Outbound Calling — the call_permission_reply webhook branch,
 * additive inside the EXISTING messages[] handling of
 * LeadWebhookController::whatsapp() (never calls[] — see the verified
 * permission contract). Every existing messages[]/statuses[]/calls[]
 * behavior for a normal item must remain byte-for-byte unaffected; these
 * tests only exercise the new branch.
 */

/**
 * @return array{tenant: mixed, source: LeadSource, lead: Lead}
 */
function cprwScenario(): array
{
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = Wa::source($tenant);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'phone' => Wa::CALLER]);

    return ['tenant' => $tenant, 'source' => $source, 'lead' => $lead];
}

/**
 * @param  array<string, mixed>  $reply
 * @return array<string, mixed>
 */
function cprwReplyMessage(array $reply, ?int $timestamp = null): array
{
    return [
        'from' => Wa::CALLER,
        'id' => 'wamid.PERMREPLY-1',
        'timestamp' => (string) ($timestamp ?? Carbon::now()->getTimestamp()),
        'type' => 'interactive',
        'interactive' => [
            'type' => 'call_permission_reply',
            'call_permission_reply' => $reply,
        ],
    ];
}

/**
 * @param  array<string, mixed>  $x
 * @param  array<string, mixed>  $message
 */
function cprwSend($test, array $x, array $message)
{
    $payload = Wa::payload([], ['messages' => [$message]]);

    return $test->postJson(route('api.webhook.whatsapp', $x['tenant']->tenant_code), $payload, Wa::signed($payload));
}

test('an accepted, permanent reply upserts the lead permission to Permanent', function () {
    $x = cprwScenario();

    cprwSend($this, $x, cprwReplyMessage(['response' => 'accept', 'is_permanent' => true, 'response_source' => 'user_action']))
        ->assertOk()->assertExactJson(['status' => 'ok']);

    $row = LeadCallPermission::where('lead_id', $x['lead']->id)->sole();
    expect($row->tenant_id)->toBe($x['tenant']->id);
    expect($row->status)->toBe(LeadCallPermissionStatus::Permanent);
    expect($row->expires_at)->toBeNull();
});

test('an accepted, temporary reply upserts to Temporary with the given expiration', function () {
    $x = cprwScenario();

    cprwSend($this, $x, cprwReplyMessage([
        'response' => 'accept',
        'is_permanent' => false,
        'expiration_timestamp' => 1900000000,
        'response_source' => 'user_action',
    ]))->assertOk();

    $row = LeadCallPermission::where('lead_id', $x['lead']->id)->sole();
    expect($row->status)->toBe(LeadCallPermissionStatus::Temporary);
    expect($row->expires_at->getTimestamp())->toBe(1900000000);
});

test('a rejected reply upserts to Rejected', function () {
    $x = cprwScenario();

    cprwSend($this, $x, cprwReplyMessage(['response' => 'reject', 'response_source' => 'user_action']))->assertOk();

    expect(LeadCallPermission::where('lead_id', $x['lead']->id)->sole()->status)->toBe(LeadCallPermissionStatus::Rejected);
});

test('a duplicate webhook (same reply delivered twice) is idempotent, not a second row', function () {
    $x = cprwScenario();
    $message = cprwReplyMessage(['response' => 'accept', 'is_permanent' => true, 'response_source' => 'user_action']);

    cprwSend($this, $x, $message)->assertOk();
    cprwSend($this, $x, $message)->assertOk();

    expect(LeadCallPermission::where('lead_id', $x['lead']->id)->count())->toBe(1);
    expect(LeadCallPermission::where('lead_id', $x['lead']->id)->sole()->status)->toBe(LeadCallPermissionStatus::Permanent);
});

test('a delayed/out-of-order reply never overwrites a newer state', function () {
    $x = cprwScenario();
    $now = Carbon::now()->getTimestamp();

    // The newer reply (accept) arrives first...
    cprwSend($this, $x, cprwReplyMessage(['response' => 'accept', 'is_permanent' => true, 'response_source' => 'user_action'], $now))->assertOk();
    // ...then a delayed redelivery of an OLDER reject arrives after it.
    cprwSend($this, $x, cprwReplyMessage(['response' => 'reject', 'response_source' => 'user_action'], $now - 3600))->assertOk();

    expect(LeadCallPermission::where('lead_id', $x['lead']->id)->sole()->status)->toBe(LeadCallPermissionStatus::Permanent);
});

test('a genuinely later reply does correctly update an earlier one', function () {
    $x = cprwScenario();
    $now = Carbon::now()->getTimestamp();

    cprwSend($this, $x, cprwReplyMessage(['response' => 'accept', 'is_permanent' => false, 'expiration_timestamp' => $now + 604800, 'response_source' => 'user_action'], $now - 3600))->assertOk();
    cprwSend($this, $x, cprwReplyMessage(['response' => 'accept', 'is_permanent' => true, 'response_source' => 'user_action'], $now))->assertOk();

    expect(LeadCallPermission::where('lead_id', $x['lead']->id)->sole()->status)->toBe(LeadCallPermissionStatus::Permanent);
});

test('a reply from an unknown consumer is logged and dropped — never creates a Lead', function () {
    $x = cprwScenario();
    $before = Lead::count();

    cprwSend($this, $x, [
        'from' => '19995550000',
        'id' => 'wamid.UNKNOWN-1',
        'timestamp' => (string) Carbon::now()->getTimestamp(),
        'type' => 'interactive',
        'interactive' => ['type' => 'call_permission_reply', 'call_permission_reply' => ['response' => 'accept', 'is_permanent' => true]],
    ])->assertOk();

    expect(Lead::count())->toBe($before);
    expect(LeadCallPermission::count())->toBe(0);
});

test('two tenants never cross-contaminate even with the same consumer phone number', function () {
    $a = cprwScenario();
    $b = cprwScenario();

    cprwSend($this, $a, cprwReplyMessage(['response' => 'accept', 'is_permanent' => true, 'response_source' => 'user_action']))->assertOk();

    expect(LeadCallPermission::where('lead_id', $a['lead']->id)->count())->toBe(1);
    expect(LeadCallPermission::where('lead_id', $b['lead']->id)->count())->toBe(0);
});

test('a malformed reply (missing response) is logged and dropped without throwing', function () {
    $x = cprwScenario();

    cprwSend($this, $x, [
        'from' => Wa::CALLER,
        'id' => 'wamid.MALFORMED-1',
        'type' => 'interactive',
        'interactive' => ['type' => 'call_permission_reply', 'call_permission_reply' => ['is_permanent' => true]],
    ])->assertOk()->assertExactJson(['status' => 'ok']);

    expect(LeadCallPermission::count())->toBe(0);
});

test('a normal text message in the same payload is completely unaffected by the new branch', function () {
    $x = cprwScenario();

    $payload = Wa::payload([], ['messages' => [
        cprwReplyMessage(['response' => 'accept', 'is_permanent' => true, 'response_source' => 'user_action']),
        ['from' => Wa::CALLER, 'id' => 'wamid.NORMAL-1', 'timestamp' => (string) Carbon::now()->getTimestamp(), 'type' => 'text', 'text' => ['body' => 'Hi there']],
    ]]);

    $this->postJson(route('api.webhook.whatsapp', $x['tenant']->tenant_code), $payload, Wa::signed($payload))->assertOk();

    expect(LeadCallPermission::where('lead_id', $x['lead']->id)->sole()->status)->toBe(LeadCallPermissionStatus::Permanent);
    // The normal text item alongside it was still processed exactly as
    // before this branch existed.
    expect(Message::where('body', 'Hi there')->exists())->toBeTrue();
});
