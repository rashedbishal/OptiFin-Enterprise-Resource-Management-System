<?php

use App\Actions\Tenant\CheckLeadCallPermission;
use App\Actions\Tenant\RecordCallPermissionReply;
use App\Actions\Tenant\RequestLeadCallPermission;
use App\Enums\LeadCallPermissionStatus;
use App\Models\Lead;
use App\Models\LeadCallPermission;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\WhatsAppCallFixtures as Wa;

/*
 * WhatsApp Outbound Calling — sending a call_permission_request template.
 * Never automatic: this action is only ever invoked by an explicit agent
 * "Request permission" click (enforced at the UI layer, Phase 6) — what this
 * file proves is that the ACTION itself never bypasses Meta's own live
 * actions[].limits[] gate, and never sends anything when it says no.
 */

const RLCP_MESSAGES_URL = 'https://graph.facebook.com/v25.0/436666719526789/messages';

function rlcpScenario(array $leadAttributes = []): array
{
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = Wa::source($tenant, [], ['access_token' => 'RLCP-sentinel-token']);
    $lead = Lead::factory()->create(array_merge([
        'tenant_id' => $tenant->id,
        'source_id' => $source->id,
        'phone' => Wa::CALLER,
        'name' => 'Ada Lovelace',
        'message' => 'I would like a quote for a new website.',
    ], $leadAttributes));

    return ['tenant' => $tenant, 'source' => $source, 'lead' => $lead];
}

function rlcpAllowed(bool $canRequest): array
{
    return ['permission' => ['status' => 'no_permission'], 'actions' => [
        ['action_name' => 'send_call_permission_request', 'can_perform_action' => $canRequest, 'limits' => [
            ['time_period' => 'PT24H', 'max_allowed' => 1, 'current_usage' => $canRequest ? 0 : 1, 'limit_expiration_time' => 1745622600],
        ]],
    ]];
}

test('sends the template and marks the row Requested when Meta live-allows it', function () {
    $x = rlcpScenario();
    Http::fake([
        '*/call_permissions*' => Http::response(rlcpAllowed(true)),
        '*/messages' => Http::response(['messages' => [['id' => 'wamid.PERM-1']]]),
    ]);

    $result = app(RequestLeadCallPermission::class)->handle($x['lead'], $x['source']);

    expect($result->sent)->toBeTrue();
    Http::assertSent(fn (Request $r) => $r->url() === RLCP_MESSAGES_URL
        && $r->method() === 'POST'
        && $r['to'] === Wa::CALLER
        && $r['type'] === 'template'
        && $r['template']['name'] === config('calling.call_permission_request_template')['name']
        && $r['template']['language']['code'] === config('calling.call_permission_request_template')['language']
        && $r['template']['components'][0]['type'] === 'call_permission_request');

    $row = LeadCallPermission::where('lead_id', $x['lead']->id)->sole();
    expect($row->status)->toBe(LeadCallPermissionStatus::Requested);
    expect($row->last_requested_at)->not->toBeNull();
});

test('sends under this source\'s own stored template name, not the global default, once one has been set', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = Wa::source($tenant, [], [
        'access_token' => 'RLCP-sentinel-token',
        'call_permission_template_name' => 'call_permission_request_v4',
    ]);
    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'source_id' => $source->id,
        'phone' => Wa::CALLER,
        'name' => 'Ada Lovelace',
        'message' => 'I would like a quote for a new website.',
    ]);

    Http::fake([
        '*/call_permissions*' => Http::response(rlcpAllowed(true)),
        '*/messages' => Http::response(['messages' => [['id' => 'wamid.PERM-4']]]),
    ]);

    app(RequestLeadCallPermission::class)->handle($lead, $source);

    Http::assertSent(fn (Request $r) => $r->url() === RLCP_MESSAGES_URL
        && $r['template']['name'] === 'call_permission_request_v4');
});

test('the body component carries exactly the lead name and the lead message, never Meta\'s example values', function () {
    $x = rlcpScenario(['name' => 'Grace Hopper', 'message' => 'Interested in a WhatsApp calling demo.']);
    Http::fake([
        '*/call_permissions*' => Http::response(rlcpAllowed(true)),
        '*/messages' => Http::response(['messages' => [['id' => 'wamid.PERM-2']]]),
    ]);

    app(RequestLeadCallPermission::class)->handle($x['lead'], $x['source']);

    Http::assertSent(function (Request $r) {
        if ($r->url() !== RLCP_MESSAGES_URL) {
            return false;
        }

        $components = $r['template']['components'];

        expect($components)->toHaveCount(2);
        expect($components[0])->toBe(['type' => 'call_permission_request']);

        $body = $components[1];
        expect($body['type'])->toBe('body');
        expect($body['parameters'])->toHaveCount(2);
        expect($body['parameters'][0])->toBe(['type' => 'text', 'text' => 'Grace Hopper']);
        expect($body['parameters'][1])->toBe(['type' => 'text', 'text' => 'Interested in a WhatsApp calling demo.']);

        $json = json_encode($body);
        expect($json)->not->toContain('John')->not->toContain('your inquiry');

        return true;
    });
});

test('a multiline inquiry is sanitized for Meta, but Lead::message stays byte-for-byte unchanged in the database', function () {
    $original = "Hello\nHi\n1234\nHello\n0099889\n11223\nHello brother";
    $x = rlcpScenario(['name' => 'Rashed Bishal', 'message' => $original]);
    Http::fake([
        '*/call_permissions*' => Http::response(rlcpAllowed(true)),
        '*/messages' => Http::response(['messages' => [['id' => 'wamid.PERM-3']]]),
    ]);

    $result = app(RequestLeadCallPermission::class)->handle($x['lead'], $x['source']);

    expect($result->sent)->toBeTrue();
    expect($x['lead']->fresh()->message)->toBe($original);

    Http::assertSent(function (Request $r) {
        if ($r->url() !== RLCP_MESSAGES_URL) {
            return false;
        }

        $params = $r['template']['components'][1]['parameters'];

        expect($params[0])->toBe(['type' => 'text', 'text' => 'Rashed Bishal']);
        expect($params[1])->toBe(['type' => 'text', 'text' => 'Hello Hi 1234 Hello 0099889 11223 Hello brother']);

        return true;
    });
});

test('CRLF, tabs and excessive spaces are all sanitized before being sent to Meta', function () {
    $x = rlcpScenario(['message' => "Hi\r\nthere\tfriend    please    call"]);
    Http::fake([
        '*/call_permissions*' => Http::response(rlcpAllowed(true)),
        '*/messages' => Http::response(['messages' => [['id' => 'wamid.PERM-4']]]),
    ]);

    app(RequestLeadCallPermission::class)->handle($x['lead'], $x['source']);

    Http::assertSent(function (Request $r) {
        if ($r->url() !== RLCP_MESSAGES_URL) {
            return false;
        }

        $text = $r['template']['components'][1]['parameters'][1]['text'];

        expect($text)
            ->not->toContain("\r")
            ->not->toContain("\n")
            ->not->toContain("\t")
            ->not->toMatch('/ {2,}/');

        return true;
    });
});

test('a lead with no message is refused before any Meta call, including the live permission check', function (?string $message) {
    $x = rlcpScenario(['message' => $message]);
    Http::fake(['*' => Http::response(['error' => ['message' => 'should never be called']], 500)]);

    $result = app(RequestLeadCallPermission::class)->handle($x['lead'], $x['source']);

    expect($result->sent)->toBeFalse();
    expect($result->refusalReason)->toBe('lead_message_required');
    Http::assertNothingSent();
    expect(LeadCallPermission::where('lead_id', $x['lead']->id)->count())->toBe(0);
})->with([null, '', '   ', "\n\t"]);

test('refuses without sending anything when Meta live-reports can_perform_action false', function () {
    $x = rlcpScenario();
    Http::fake(['*/call_permissions*' => Http::response(rlcpAllowed(false))]);

    $result = app(RequestLeadCallPermission::class)->handle($x['lead'], $x['source']);

    expect($result->sent)->toBeFalse();
    expect($result->refusalReason)->toBe('not_allowed');
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/messages'));
    // The live check that ran first legitimately records its own outcome —
    // just never Requested, since nothing was actually sent.
    expect(LeadCallPermission::where('lead_id', $x['lead']->id)->sole()->status)->toBe(LeadCallPermissionStatus::NoPermission);
});

test('refuses when the live permission check itself fails, without attempting to send', function () {
    $x = rlcpScenario();
    Http::fake(function () {
        throw new ConnectionException('cURL error 28: Operation timed out');
    });

    $result = app(RequestLeadCallPermission::class)->handle($x['lead'], $x['source']);

    expect($result->sent)->toBeFalse();
    expect($result->refusalReason)->toBe('permission_check_failed');
});

test('refuses and does not mark the row Requested when Meta rejects the template send', function () {
    $x = rlcpScenario();
    Http::fake([
        '*/call_permissions*' => Http::response(rlcpAllowed(true)),
        '*/messages' => Http::response(['error' => ['message' => 'rejected', 'code' => 100]], 400),
    ]);

    $result = app(RequestLeadCallPermission::class)->handle($x['lead'], $x['source']);

    expect($result->sent)->toBeFalse();
    expect($result->refusalReason)->toBe('meta_rejected');
    // The preceding live check recorded its own (allowed) outcome, but the
    // template send itself failed, so the row must never read Requested.
    expect(LeadCallPermission::where('lead_id', $x['lead']->id)->sole()->status)->not->toBe(LeadCallPermissionStatus::Requested);
});

test('never repeatedly sends without a fresh live allow — a second refused attempt still checks live rather than trusting local Requested state', function () {
    $x = rlcpScenario();
    LeadCallPermission::factory()->requested()->create(['tenant_id' => $x['tenant']->id, 'lead_id' => $x['lead']->id, 'lead_source_id' => $x['source']->id]);
    Http::fake(['*/call_permissions*' => Http::response(rlcpAllowed(false))]);

    $result = app(RequestLeadCallPermission::class)->handle($x['lead'], $x['source']);

    expect($result->sent)->toBeFalse();
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/messages'));
});

test('a newer observation that lands while the template send is in flight is never overwritten back to Requested', function () {
    $x = rlcpScenario();

    Http::fake([
        '*/call_permissions*' => Http::response(rlcpAllowed(true)),
        '*/messages' => function () use ($x) {
            // Simulates a call_permission_reply webhook landing WHILE this
            // template-send request is still in flight — a genuinely newer
            // observation than the one RequestLeadCallPermission::handle()
            // captured at its own start, before either network call.
            app(RecordCallPermissionReply::class)->handle(
                $x['lead'], $x['source'], response: 'accept', isPermanent: true,
                expirationTimestamp: null, observedAt: Carbon::now()->addSecond(),
            );

            return Http::response(['messages' => [['id' => 'wamid.PERM-RACE']]]);
        },
    ]);

    $result = app(RequestLeadCallPermission::class)->handle($x['lead'], $x['source']);

    expect($result->sent)->toBeTrue();
    // The webhook's Permanent grant, which landed AFTER this request's own
    // observation time, must win — never regressed back to Requested.
    expect(LeadCallPermission::where('lead_id', $x['lead']->id)->sole()->status)->toBe(LeadCallPermissionStatus::Permanent);
});

test('CheckLeadCallPermission alone never sends a permission request, even when status is no_permission', function () {
    $x = rlcpScenario();
    Http::fake(['*' => Http::response(['permission' => ['status' => 'no_permission']])]);

    app(CheckLeadCallPermission::class)->handle($x['lead'], $x['source']);

    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/messages'));
});
