<?php

use App\Actions\Tenant\CheckLeadCallPermission;
use App\Enums\LeadCallPermissionStatus;
use App\Models\Lead;
use App\Models\LeadCallPermission;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Support\WhatsAppCallFixtures as Wa;

/*
 * WhatsApp Outbound Calling — the live, authoritative permission check. Meta
 * is always faked via Http::fake(); nothing here hits the network.
 */

function clcpScenario(): array
{
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = Wa::source($tenant, [], ['access_token' => 'CLCP-sentinel-token']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'phone' => Wa::CALLER]);

    return ['tenant' => $tenant, 'source' => $source, 'lead' => $lead];
}

test('a fresh check with no existing row creates one, mapping Meta status correctly', function (string $metaStatus, LeadCallPermissionStatus $expected, bool $expiresAt) {
    $x = clcpScenario();
    Http::fake(['*' => Http::response(['permission' => array_filter(['status' => $metaStatus, 'expiration_time' => $expiresAt ? 1900000000 : null], fn ($v) => $v !== null)])]);

    $result = app(CheckLeadCallPermission::class)->handle($x['lead'], $x['source']);

    expect($result->successful)->toBeTrue();
    $row = LeadCallPermission::where('lead_id', $x['lead']->id)->sole();
    expect($row->tenant_id)->toBe($x['tenant']->id);
    expect($row->lead_source_id)->toBe($x['source']->id);
    expect($row->status)->toBe($expected);
    expect($row->meta_raw_status)->toBe($metaStatus);
    expect($row->expires_at !== null)->toBe($expiresAt);
    expect($row->last_synced_at)->not->toBeNull();
})->with([
    'no_permission' => ['no_permission', LeadCallPermissionStatus::NoPermission, false],
    'temporary' => ['temporary', LeadCallPermissionStatus::Temporary, true],
    'permanent' => ['permanent', LeadCallPermissionStatus::Permanent, false],
]);

test('a later check updates the existing row in place', function () {
    $x = clcpScenario();
    $row = LeadCallPermission::factory()->create(['tenant_id' => $x['tenant']->id, 'lead_id' => $x['lead']->id, 'lead_source_id' => $x['source']->id]);

    Http::fake(['*' => Http::response(['permission' => ['status' => 'permanent']])]);
    app(CheckLeadCallPermission::class)->handle($x['lead'], $x['source']);

    expect(LeadCallPermission::where('lead_id', $x['lead']->id)->count())->toBe(1);
    expect($row->fresh()->status)->toBe(LeadCallPermissionStatus::Permanent);
});

test('a stale/delayed check never overwrites a newer observation already on the row', function () {
    $x = clcpScenario();
    // Simulates a webhook reply (or an earlier, still-in-flight check) that
    // already landed a newer observation than this one will carry.
    $row = LeadCallPermission::factory()->permanent()->create([
        'tenant_id' => $x['tenant']->id,
        'lead_id' => $x['lead']->id,
        'lead_source_id' => $x['source']->id,
        'last_synced_at' => now()->addMinutes(5),
    ]);

    Http::fake(['*' => Http::response(['permission' => ['status' => 'no_permission']])]);
    app(CheckLeadCallPermission::class)->handle($x['lead'], $x['source']);

    expect($row->fresh()->status)->toBe(LeadCallPermissionStatus::Permanent);
});

test('a failed check (network error) leaves local state untouched and returns an unsuccessful result', function () {
    $x = clcpScenario();
    LeadCallPermission::factory()->permanent()->create(['tenant_id' => $x['tenant']->id, 'lead_id' => $x['lead']->id, 'lead_source_id' => $x['source']->id]);

    Http::fake(function () {
        throw new ConnectionException('cURL error 28: Operation timed out');
    });

    $result = app(CheckLeadCallPermission::class)->handle($x['lead'], $x['source']);

    expect($result->successful)->toBeFalse();
    expect(LeadCallPermission::where('lead_id', $x['lead']->id)->sole()->status)->toBe(LeadCallPermissionStatus::Permanent);
});

test('a malformed Meta response never throws and defaults to no_permission', function () {
    $x = clcpScenario();
    Http::fake(['*' => Http::response(['unexpected' => 'shape'])]);

    $result = app(CheckLeadCallPermission::class)->handle($x['lead'], $x['source']);

    expect($result->successful)->toBeTrue();
    expect(LeadCallPermission::where('lead_id', $x['lead']->id)->sole()->status)->toBe(LeadCallPermissionStatus::NoPermission);
});

test('two tenants checking the same consumer phone get fully independent rows', function () {
    $a = clcpScenario();
    $b = clcpScenario();
    Http::fake(['*' => Http::response(['permission' => ['status' => 'permanent']])]);

    app(CheckLeadCallPermission::class)->handle($a['lead'], $a['source']);
    app(CheckLeadCallPermission::class)->handle($b['lead'], $b['source']);

    expect(LeadCallPermission::where('tenant_id', $a['tenant']->id)->sole()->lead_id)->toBe($a['lead']->id);
    expect(LeadCallPermission::where('tenant_id', $b['tenant']->id)->sole()->lead_id)->toBe($b['lead']->id);
    expect(LeadCallPermission::count())->toBe(2);
});
