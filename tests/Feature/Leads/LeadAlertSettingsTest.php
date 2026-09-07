<?php

use App\Livewire\Tenant\Leads\Index;
use Livewire\Livewire;

test('turning a meeting alert on opens the days editor without enabling it yet', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('toggleMeetingAlert')
        ->assertSet('showMeetingAlertModal', true);

    expect($tenant->fresh()->meeting_alert_enabled)->toBeFalse();
});

test('saving the days editor enables the alert with the given days', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('toggleMeetingAlert')
        ->set('meetingAlertDaysInput', '5')
        ->call('saveMeetingAlertDays')
        ->assertSet('showMeetingAlertModal', false)
        ->assertHasNoErrors();

    $tenant->refresh();
    expect($tenant->meeting_alert_enabled)->toBeTrue();
    expect($tenant->meeting_alert_days_before)->toBe(5);
});

test('a negative days-before value is rejected', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('toggleMeetingAlert')
        ->set('meetingAlertDaysInput', '-1')
        ->call('saveMeetingAlertDays')
        ->assertHasErrors(['meetingAlertDaysInput']);

    expect($tenant->fresh()->meeting_alert_enabled)->toBeFalse();
});

test('a zero days-before value is allowed — alerts only on the exact day', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('toggleMeetingAlert')
        ->set('meetingAlertDaysInput', '0')
        ->call('saveMeetingAlertDays')
        ->assertHasNoErrors();

    expect($tenant->fresh()->meeting_alert_enabled)->toBeTrue();
});

test('toggling an already-enabled alert off is immediate, no prompt', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['meeting_alert_enabled' => true, 'meeting_alert_days_before' => 3]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('toggleMeetingAlert')
        ->assertSet('showMeetingAlertModal', false);

    expect($tenant->fresh()->meeting_alert_enabled)->toBeFalse();
});

test('the days editor can be reopened without touching whether the alert is enabled', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['meeting_alert_enabled' => true, 'meeting_alert_days_before' => 7]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('openMeetingAlertDaysEditor')
        ->assertSet('showMeetingAlertModal', true)
        ->assertSet('meetingAlertDaysInput', '7');

    expect($tenant->fresh()->meeting_alert_enabled)->toBeTrue();
});

test('a client user (non-admin) cannot toggle tenant alert settings', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant);

    Livewire::actingAs($rep)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('toggleMeetingAlert')
        ->assertSet('showMeetingAlertModal', false);

    expect($tenant->fresh()->meeting_alert_enabled)->toBeFalse();
});

test('follow-up alert settings behave identically to meeting alert settings', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('toggleFollowUpAlert')
        ->set('followUpAlertDaysInput', '2')
        ->call('saveFollowUpAlertDays')
        ->assertHasNoErrors();

    $tenant->refresh();
    expect($tenant->followup_alert_enabled)->toBeTrue();
    expect($tenant->followup_alert_days_before)->toBe(2);
});
