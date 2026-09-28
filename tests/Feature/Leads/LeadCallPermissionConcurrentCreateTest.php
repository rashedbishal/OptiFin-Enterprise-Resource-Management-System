<?php

use App\Actions\Tenant\CheckLeadCallPermission;
use App\Actions\Tenant\RecordCallPermissionReply;
use App\Enums\LeadCallPermissionStatus;
use App\Models\Lead;
use App\Models\LeadCallPermission;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\WhatsAppCallFixtures as Wa;

/*
 * WhatsApp Outbound Calling — CheckLeadCallPermission::writeObservation() and
 * RecordCallPermissionReply::handle() both do a check-then-create for a
 * Lead's first-ever LeadCallPermission row (unique on lead_id). Two writers
 * racing for the same Lead (a live permission check racing a webhook reply,
 * or two overlapping webhook redeliveries) can both read "no existing row"
 * before either commits its create(), so the loser's create() hits the real
 * DB unique-constraint violation. These tests force that exact interleaving
 * via a `creating` model event (which fires synchronously inside the
 * winner's own create() call, so the "concurrent" insert genuinely lands
 * first) and prove the loser recovers instead of throwing.
 */

function lcpccScenario(): array
{
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = Wa::source($tenant, [], ['access_token' => 'LCPCC-sentinel-token']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'phone' => Wa::CALLER]);

    return ['tenant' => $tenant, 'source' => $source, 'lead' => $lead];
}

/**
 * Registers a one-shot `creating` listener that inserts a competing row for
 * the same lead directly (simulating the concurrent winner) the first time
 * any LeadCallPermission is about to be created during the test.
 */
function lcpccRaceOnFirstCreate(array $x, array $winnerAttributes): void
{
    $raced = false;

    LeadCallPermission::creating(function () use (&$raced, $x, $winnerAttributes) {
        if ($raced) {
            return;
        }

        $raced = true;

        LeadCallPermission::query()->create(array_merge([
            'tenant_id' => $x['tenant']->id,
            'lead_id' => $x['lead']->id,
            'lead_source_id' => $x['source']->id,
        ], $winnerAttributes));
    });
}

test('CheckLeadCallPermission losing the create race recovers and applies the newer observation, never throws', function () {
    $x = lcpccScenario();
    Http::fake(['*' => Http::response(['permission' => ['status' => 'temporary', 'expiration_time' => 1900000000]])]);

    // The concurrent winner's observation is OLDER than this check's own —
    // this check's result must still win after it recovers from the race.
    lcpccRaceOnFirstCreate($x, [
        'status' => LeadCallPermissionStatus::Permanent,
        'meta_raw_status' => 'permanent',
        'last_synced_at' => Carbon::now()->subMinute(),
    ]);

    $result = app(CheckLeadCallPermission::class)->handle($x['lead'], $x['source']);

    expect($result->successful)->toBeTrue();
    expect(LeadCallPermission::where('lead_id', $x['lead']->id)->count())->toBe(1);
    $row = LeadCallPermission::where('lead_id', $x['lead']->id)->sole();
    expect($row->status)->toBe(LeadCallPermissionStatus::Temporary);
});

test('CheckLeadCallPermission losing the create race to a NEWER concurrent observation does not overwrite it', function () {
    $x = lcpccScenario();
    Http::fake(['*' => Http::response(['permission' => ['status' => 'no_permission']])]);

    // The concurrent winner's observation is NEWER than this check's own
    // $observedAt (captured before the HTTP call even ran) — a stale
    // observation losing the race must never clobber it.
    lcpccRaceOnFirstCreate($x, [
        'status' => LeadCallPermissionStatus::Permanent,
        'meta_raw_status' => 'permanent',
        'last_synced_at' => Carbon::now()->addMinute(),
    ]);

    $result = app(CheckLeadCallPermission::class)->handle($x['lead'], $x['source']);

    expect($result->successful)->toBeTrue();
    expect(LeadCallPermission::where('lead_id', $x['lead']->id)->count())->toBe(1);
    $row = LeadCallPermission::where('lead_id', $x['lead']->id)->sole();
    expect($row->status)->toBe(LeadCallPermissionStatus::Permanent);
});

test('RecordCallPermissionReply losing the create race recovers and applies the newer reply, never throws', function () {
    $x = lcpccScenario();

    lcpccRaceOnFirstCreate($x, [
        'status' => LeadCallPermissionStatus::Rejected,
        'last_synced_at' => Carbon::now()->subMinute(),
    ]);

    app(RecordCallPermissionReply::class)->handle(
        $x['lead'],
        $x['source'],
        response: 'accept',
        isPermanent: true,
        expirationTimestamp: null,
        observedAt: Carbon::now(),
    );

    expect(LeadCallPermission::where('lead_id', $x['lead']->id)->count())->toBe(1);
    $row = LeadCallPermission::where('lead_id', $x['lead']->id)->sole();
    expect($row->status)->toBe(LeadCallPermissionStatus::Permanent);
});

test('two writers racing across BOTH actions for the same lead still end with exactly one row, no exception, and the newer write wins', function () {
    $x = lcpccScenario();

    // A live check races a webhook reply that lands first with a NEWER
    // observation than the check's own.
    lcpccRaceOnFirstCreate($x, [
        'status' => LeadCallPermissionStatus::Rejected,
        'last_synced_at' => Carbon::now()->addMinute(),
    ]);
    Http::fake(['*' => Http::response(['permission' => ['status' => 'permanent']])]);

    $result = app(CheckLeadCallPermission::class)->handle($x['lead'], $x['source']);

    expect($result->successful)->toBeTrue();
    expect(LeadCallPermission::where('lead_id', $x['lead']->id)->count())->toBe(1);
    // The webhook reply's observation is newer, so the losing check must not
    // have overwritten it.
    expect(LeadCallPermission::where('lead_id', $x['lead']->id)->sole()->status)->toBe(LeadCallPermissionStatus::Rejected);
});
