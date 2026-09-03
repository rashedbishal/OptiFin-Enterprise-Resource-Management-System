<?php

use App\Models\Lead;

test('meeting alert is inactive when the tenant setting is disabled', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => today()]);

    expect($lead->meetingAlertActive(false, 3))->toBeFalse();
});

test('meeting alert is inactive when no meeting date is set', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => null]);

    expect($lead->meetingAlertActive(true, 3))->toBeFalse();
});

test('meeting alert activates exactly N days before the meeting when enabled', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $tooEarly = Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => today()->addDays(4)]);
    $onTheBoundary = Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => today()->addDays(3)]);
    $insideWindow = Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => today()->addDays(1)]);

    expect($tooEarly->meetingAlertActive(true, 3))->toBeFalse();
    expect($onTheBoundary->meetingAlertActive(true, 3))->toBeTrue();
    expect($insideWindow->meetingAlertActive(true, 3))->toBeTrue();
});

test('a daysBefore of 0 only alerts on the exact meeting day', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $tomorrow = Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => today()->addDay()]);
    $today = Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => today()]);

    expect($tomorrow->meetingAlertActive(true, 0))->toBeFalse();
    expect($today->meetingAlertActive(true, 0))->toBeTrue();
});

test('a meeting too close for the full lead time still alerts instantly, with no special-casing needed', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    // daysBefore=3 but the meeting is only 1 day out — the window "started"
    // 2 days ago, so this must already be active, not wait for a 3-day lead time.
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => today()->addDay()]);

    expect($lead->meetingAlertActive(true, 3))->toBeTrue();
});

test('meeting alert stays active indefinitely once overdue, with no upper bound', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => today()->subMonths(2)]);

    expect($lead->meetingAlertActive(true, 3))->toBeTrue();
});

test('follow-up alert follows the identical rules against next_follow_up_at', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'next_follow_up_at' => today()->addDays(2)]);

    expect($lead->followUpAlertActive(false, 5))->toBeFalse();
    expect($lead->followUpAlertActive(true, 1))->toBeFalse();
    expect($lead->followUpAlertActive(true, 2))->toBeTrue();
});
