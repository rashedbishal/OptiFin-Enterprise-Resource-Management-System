<?php

use App\Models\TenantUser;
use App\Services\SoftphonePresence;
use Illuminate\Support\Carbon;

/*
 * WhatsApp Call Backup / Overflow Receiver — SoftphonePresence in isolation.
 * Pure logic against an in-memory TenantUser (never persisted, no DB query
 * involved), so these only exercise the staleness comparison itself; the
 * assignee's whole eligibility chain (including this check) is CallRouter's
 * own test suite.
 */

function softphonePresenceUser(?Carbon $lastSeen): TenantUser
{
    $user = new TenantUser;
    $user->softphone_last_seen_at = $lastSeen;

    return $user;
}

test('a null last-seen (never opened the softphone, or a pre-existing row) is treated as available', function () {
    expect(app(SoftphonePresence::class)->isAvailable(softphonePresenceUser(null)))->toBeTrue();
});

test('a fresh heartbeat is available', function () {
    expect(app(SoftphonePresence::class)->isAvailable(softphonePresenceUser(Carbon::now())))->toBeTrue();
});

test('a heartbeat just inside the configured threshold is still available', function () {
    config(['calling.softphone_presence_stale_seconds' => 45]);

    expect(app(SoftphonePresence::class)->isAvailable(softphonePresenceUser(Carbon::now()->subSeconds(44))))->toBeTrue();
});

test('a heartbeat past the configured threshold is stale', function () {
    config(['calling.softphone_presence_stale_seconds' => 45]);

    expect(app(SoftphonePresence::class)->isAvailable(softphonePresenceUser(Carbon::now()->subSeconds(46))))->toBeFalse();
});

test('the threshold is read from config, not hardcoded', function () {
    $lastSeen = Carbon::now()->subSeconds(10);

    config(['calling.softphone_presence_stale_seconds' => 5]);
    expect(app(SoftphonePresence::class)->isAvailable(softphonePresenceUser($lastSeen)))->toBeFalse();

    config(['calling.softphone_presence_stale_seconds' => 20]);
    expect(app(SoftphonePresence::class)->isAvailable(softphonePresenceUser($lastSeen)))->toBeTrue();
});

test('the default threshold is 45 seconds — three times the healthy softphone heartbeat interval', function () {
    expect(config('calling.softphone_presence_stale_seconds'))->toBe(45);
    expect(config('calling.softphone_heartbeat_seconds'))->toBe(15);
});
