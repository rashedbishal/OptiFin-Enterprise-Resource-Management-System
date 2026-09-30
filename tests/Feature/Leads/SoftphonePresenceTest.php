<?php

use App\Contracts\ReverbChannelUsersGateway;
use App\Models\TenantUser;
use App\Services\SoftphonePresence;
use Illuminate\Support\Carbon;
use Tests\Support\FakeReverbChannelUsersGateway;

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

// ── WhatsApp Call Backup / Overflow Receiver, Phase 2 — Reverb presence ────
//
// Reverb presence is disabled by default (calling.reverb_presence_enabled =
// false, unchanged in every test above), so every test above this line
// already proves the pre-existing timestamp fallback is completely
// untouched. These tests explicitly opt in via fakeReverbPresence() (see
// tests/Pest.php) and never contact a real Reverb server — the gateway is
// a fake bound in the container.

test('Reverb says the user is present -> available, regardless of the heartbeat timestamp', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $user = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $user->forceFill(['softphone_last_seen_at' => now()->subDays(30)])->save();

    fakeReverbPresence()->returningMembers([$user->id]);

    expect(app(SoftphonePresence::class)->isAvailable($user->fresh()))->toBeTrue();
});

test('Reverb says the user is absent -> unavailable, even with a perfectly fresh heartbeat', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $user = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $user->forceFill(['softphone_last_seen_at' => now()])->save();

    fakeReverbPresence()->returningMembers(['some-other-user-id']);

    expect(app(SoftphonePresence::class)->isAvailable($user->fresh()))->toBeFalse();
});

test('Reverb query fails + a fresh heartbeat -> falls back to available', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $user = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $user->forceFill(['softphone_last_seen_at' => now()])->save();

    fakeReverbPresence()->failing();

    expect(app(SoftphonePresence::class)->isAvailable($user->fresh()))->toBeTrue();
});

test('Reverb query fails + a stale heartbeat -> falls back to unavailable', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $user = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $user->forceFill(['softphone_last_seen_at' => now()->subSeconds(200)])->save();

    fakeReverbPresence()->failing();

    expect(app(SoftphonePresence::class)->isAvailable($user->fresh()))->toBeFalse();
});

test('Reverb query fails + no heartbeat ever recorded -> falls back to available', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $user = clientUserWithLeadPermission($tenant, ['view', 'update']);
    expect($user->softphone_last_seen_at)->toBeNull();

    fakeReverbPresence()->failing();

    expect(app(SoftphonePresence::class)->isAvailable($user->fresh()))->toBeTrue();
});

test('Reverb disabled (the default) never queries the gateway at all', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $user = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $user->forceFill(['softphone_last_seen_at' => now()])->save();

    // Deliberately NOT calling fakeReverbPresence() — reverb_presence_enabled
    // stays at its default (false). Still rebind the gateway to a fake that
    // would fail the test if it were ever called, proving the disabled flag
    // short-circuits before the gateway is touched at all.
    $fake = new FakeReverbChannelUsersGateway;
    app()->instance(ReverbChannelUsersGateway::class, $fake);

    app(SoftphonePresence::class)->isAvailable($user->fresh());

    expect($fake->queriedChannels)->toBe([]);
});

test('the queried channel name is the tenant-scoped presence channel, wire-prefixed', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $user = clientUserWithLeadPermission($tenant, ['view', 'update']);

    $fake = fakeReverbPresence()->returningMembers([$user->id]);

    app(SoftphonePresence::class)->isAvailable($user->fresh());

    expect($fake->queriedChannels)->toBe(['presence-tenant-softphone.'.$tenant->id]);
});
