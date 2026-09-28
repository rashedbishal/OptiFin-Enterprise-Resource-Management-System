<?php

use App\Models\Lead;
use App\Models\LeadCallPermission;
use App\Models\LeadSource;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/*
 * WhatsApp Outbound Calling — the local, bookkeeping-only permission model.
 */

test('one row per lead: a second row for the same lead violates the unique constraint', function () {
    $lead = Lead::factory()->create();

    LeadCallPermission::factory()->create(['lead_id' => $lead->id]);

    expect(fn () => LeadCallPermission::factory()->create(['lead_id' => $lead->id]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('effectivelyGranted is true for a permanent permission', function () {
    $permission = LeadCallPermission::factory()->permanent()->make();

    expect($permission->effectivelyGranted())->toBeTrue();
});

test('effectivelyGranted is true for a temporary permission before it expires', function () {
    $permission = LeadCallPermission::factory()->temporary()->make(['expires_at' => now()->addHour()]);

    expect($permission->effectivelyGranted())->toBeTrue();
});

test('effectivelyGranted is false for a temporary permission after it expires — computed locally, no webhook exists for this', function () {
    $permission = LeadCallPermission::factory()->temporary()->make(['expires_at' => now()->subMinute()]);

    expect($permission->effectivelyGranted())->toBeFalse();
});

test('effectivelyGranted respects an explicit "now" so expiry is deterministic in tests', function () {
    $permission = LeadCallPermission::factory()->temporary()->make(['expires_at' => Carbon::parse('2026-01-01 12:00:00')]);

    expect($permission->effectivelyGranted(Carbon::parse('2026-01-01 11:59:59')))->toBeTrue();
    expect($permission->effectivelyGranted(Carbon::parse('2026-01-01 12:00:01')))->toBeFalse();
});

test('effectivelyGranted is false for no_permission, requested and rejected', function (string $state) {
    $permission = LeadCallPermission::factory()->{$state}()->make();

    expect($permission->effectivelyGranted())->toBeFalse();
})->with(['requested', 'rejected']);

test('effectivelyGranted is false for the default no-permission state', function () {
    expect(LeadCallPermission::factory()->make()->effectivelyGranted())->toBeFalse();
});

test('belongs to its lead and lead source', function () {
    $lead = Lead::factory()->create();
    $source = LeadSource::factory()->create();
    $permission = LeadCallPermission::factory()->create(['lead_id' => $lead->id, 'lead_source_id' => $source->id]);

    expect($permission->lead->is($lead))->toBeTrue();
    expect($permission->leadSource->is($source))->toBeTrue();
});
