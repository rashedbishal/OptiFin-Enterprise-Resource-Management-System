<?php

use App\Enums\TenantStatus;
use App\Models\Lead;
use App\Notifications\FollowUpAlertNotification;
use App\Notifications\MeetingAlertNotification;

test('a suspended tenant is skipped entirely, even with alerts enabled and a due meeting', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['meeting_alert_enabled' => true, 'meeting_alert_days_before' => 2, 'status' => TenantStatus::Suspended]);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => now()->addDays(2)]);

    $this->artisan('leads:send-alert-notifications')->assertSuccessful();

    expect($clientAdmin->notifications()->count())->toBe(0);
});

test('a client admin is notified once a lead\'s meeting alert window opens', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['meeting_alert_enabled' => true, 'meeting_alert_days_before' => 2]);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => now()->addDays(2)]);

    $this->artisan('leads:send-alert-notifications')->assertSuccessful();

    expect($clientAdmin->notifications()->where('type', MeetingAlertNotification::class)->count())->toBe(1);
});

test('a lead outside its meeting alert window does not notify anyone', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['meeting_alert_enabled' => true, 'meeting_alert_days_before' => 2]);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => now()->addDays(10)]);

    $this->artisan('leads:send-alert-notifications');

    expect($clientAdmin->notifications()->count())->toBe(0);
});

test('a disabled meeting alert setting sends nothing even for a due meeting', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['meeting_alert_enabled' => false, 'meeting_alert_days_before' => 2]);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => now()]);

    $this->artisan('leads:send-alert-notifications');

    expect($clientAdmin->notifications()->count())->toBe(0);
});

test('only the assigned rep and admins are notified for a follow-up alert, not an unrelated rep', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['followup_alert_enabled' => true, 'followup_alert_days_before' => 1]);
    $assignedRep = clientUserWithLeadPermission($tenant);
    $otherRep = clientUserWithLeadPermission($tenant);
    Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'next_follow_up_at' => now()->addDay(),
        'assigned_to' => $assignedRep->id,
    ]);

    $this->artisan('leads:send-alert-notifications');

    expect($clientAdmin->notifications()->where('type', FollowUpAlertNotification::class)->count())->toBe(1);
    expect($assignedRep->notifications()->where('type', FollowUpAlertNotification::class)->count())->toBe(1);
    expect($otherRep->notifications()->count())->toBe(0);
});

test('running the sweep twice in the same day does not duplicate a notification', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['meeting_alert_enabled' => true, 'meeting_alert_days_before' => 0]);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => now()]);

    $this->artisan('leads:send-alert-notifications');
    $this->artisan('leads:send-alert-notifications');

    expect($clientAdmin->notifications()->where('type', MeetingAlertNotification::class)->count())->toBe(1);
});

test('a cancelled meeting (null meeting_date) is never alerted on', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['meeting_alert_enabled' => true, 'meeting_alert_days_before' => 5]);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => null]);

    $this->artisan('leads:send-alert-notifications');

    expect($clientAdmin->notifications()->count())->toBe(0);
});

test('a merged-away lead\'s meeting alert never notifies anyone, while an eligible survivor still does', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['meeting_alert_enabled' => true, 'meeting_alert_days_before' => 2]);

    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => now()->addDays(2)]);
    Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'meeting_date' => now()->addDays(2),
        'merged_into_lead_id' => $survivor->id,
    ]);

    $this->artisan('leads:send-alert-notifications')->assertSuccessful();

    // Exactly one notification — proves the merged-away lead produced none
    // of its own while the eligible survivor still notified as expected.
    expect($clientAdmin->notifications()->where('type', MeetingAlertNotification::class)->count())->toBe(1);
});
