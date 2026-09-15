<?php

use App\Enums\AutoDistributionMethod;
use App\Enums\LeadDistributionMode;
use App\Enums\LeadSourceType;
use App\Events\LeadReceived;
use App\Livewire\Tenant\Leads\Index;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\TenantUser;
use App\Notifications\FollowUpUpdatedNotification;
use App\Notifications\MeetingUpdatedNotification;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

test('a client admin can create a manual lead with full CRM details', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('createLead')
        ->set('leadName', 'Rafiqul Islam')
        ->set('leadCompany', 'Apex Global Logistics Ltd.')
        ->set('leadWebsite', 'https://apexlogistics.com')
        ->set('leadIndustry', 'Technology & Digital')
        ->set('leadLocation', 'Dhaka, Bangladesh')
        ->set('leadServiceInterest', 'Custom Web Application & SaaS')
        ->set('leadSource', 'Referral')
        ->set('leadPhone', '+8801712345678')
        ->set('leadEmail', 'rafiq@apexlogistics.com')
        ->set('leadMessage', 'Interested in a custom SaaS build.')
        ->set('leadEstimatedBudget', '250000')
        ->set('leadDealValue', '250000')
        ->set('leadWinProbability', '50')
        ->set('leadNextFollowUpAt', now()->addDays(3)->toDateString())
        ->set('leadMeetingDate', now()->addDays(5)->format('Y-m-d').'T15:00')
        ->call('saveLead')
        ->assertHasNoErrors();

    $lead = Lead::where('tenant_id', $tenant->id)->where('name', 'Rafiqul Islam')->firstOrFail();

    expect($lead->company)->toBe('Apex Global Logistics Ltd.');
    expect($lead->website)->toBe('https://apexlogistics.com');
    expect($lead->industry)->toBe('Technology & Digital');
    expect($lead->location)->toBe('Dhaka, Bangladesh');
    expect($lead->service_interest)->toBe('Custom Web Application & SaaS');
    expect($lead->lead_source)->toBe('Referral');
    expect($lead->phone)->toBe('+8801712345678');
    expect($lead->email)->toBe('rafiq@apexlogistics.com');
    expect($lead->message)->toBe('Interested in a custom SaaS build.');
    expect((float) $lead->estimated_budget)->toBe(250000.0);
    expect((float) $lead->deal_value)->toBe(250000.0);
    expect($lead->win_probability)->toBe(50);
    expect($lead->next_follow_up_at->toDateString())->toBe(now()->addDays(3)->toDateString());
    expect($lead->meeting_date->toDateString())->toBe(now()->addDays(5)->toDateString());
    expect($lead->meetingTimeFormatted())->toBe('3:00 PM');
    expect($lead->status_id)->toBe(leadStatus('new')->id);
    expect($lead->assigned_to)->toBeNull();
    expect($lead->source->type)->toBe(LeadSourceType::Manual);
    expect($lead->source->name)->toBe('Custom');
});

test('the CRM detail fields on a manual lead are all optional', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('createLead')
        ->set('leadName', 'Bare Minimum Lead')
        ->set('leadPhone', '+8801700000099')
        ->call('saveLead')
        ->assertHasNoErrors();

    $lead = Lead::where('tenant_id', $tenant->id)->where('name', 'Bare Minimum Lead')->firstOrFail();

    expect($lead->company)->toBeNull();
    expect($lead->website)->toBeNull();
    expect($lead->estimated_budget)->toBeNull();
    expect($lead->win_probability)->toBeNull();
    expect($lead->next_follow_up_at)->toBeNull();
});

test('creating a lead with a meeting and follow-up already set notifies other admins as fresh schedules', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $otherAdmin = TenantUser::factory()->clientAdmin()->create(['tenant_id' => $tenant->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('createLead')
        ->set('leadName', 'Rafiqul Islam')
        ->set('leadPhone', '+8801712345678')
        ->set('leadNextFollowUpAt', now()->addDays(3)->toDateString())
        ->set('leadMeetingDate', now()->addDays(5)->format('Y-m-d').'T15:00')
        ->call('saveLead')
        ->assertHasNoErrors();

    expect($clientAdmin->notifications()->count())->toBe(0);

    $meetingNotification = $otherAdmin->notifications()->where('type', MeetingUpdatedNotification::class)->firstOrFail();
    expect($meetingNotification->data['rescheduled'])->toBeFalse();

    $followUpNotification = $otherAdmin->notifications()->where('type', FollowUpUpdatedNotification::class)->firstOrFail();
    expect($followUpNotification->data['rescheduled'])->toBeFalse();
});

test('creating a lead with no meeting or follow-up sends neither notification', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $otherAdmin = TenantUser::factory()->clientAdmin()->create(['tenant_id' => $tenant->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('createLead')
        ->set('leadName', 'Bare Minimum Lead')
        ->set('leadPhone', '+8801700000099')
        ->call('saveLead')
        ->assertHasNoErrors();

    expect($otherAdmin->notifications()->count())->toBe(0);
});

test('win probability must be between 0 and 100', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('createLead')
        ->set('leadName', 'Invalid Probability Lead')
        ->set('leadPhone', '+8801700000098')
        ->set('leadWinProbability', '150')
        ->call('saveLead')
        ->assertHasErrors(['leadWinProbability' => 'max']);

    expect(Lead::where('tenant_id', $tenant->id)->where('name', 'Invalid Probability Lead')->exists())->toBeFalse();
});

test('creating multiple manual leads reuses the same tenant Custom source', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    foreach (['Lead One', 'Lead Two'] as $name) {
        Livewire::actingAs($clientAdmin)
            ->test(Index::class)->call('completeInitialLoad')
            ->call('createLead')
            ->set('leadName', $name)
            ->set('leadPhone', '+8801700000000')
            ->call('saveLead');
    }

    expect(LeadSource::where('tenant_id', $tenant->id)->where('type', LeadSourceType::Manual)->count())->toBe(1);
    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(2);
});

test('a client admin can assign a manual lead to a team member on creation', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('createLead')
        ->set('leadName', 'Assigned Lead')
        ->set('leadPhone', '+8801700000001')
        ->set('leadAssigneeId', $rep->id)
        ->call('saveLead')
        ->assertHasNoErrors();

    $lead = Lead::where('tenant_id', $tenant->id)->where('name', 'Assigned Lead')->firstOrFail();

    expect($lead->assigned_to)->toBe($rep->id);
    expect($lead->assigned_at)->not->toBeNull();
});

test('a non-admin with create permission is auto-assigned the manual lead they create', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'create']);

    Livewire::actingAs($rep)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('createLead')
        ->set('leadName', 'Self Assigned Lead')
        ->set('leadPhone', '+8801700000002')
        ->call('saveLead')
        ->assertHasNoErrors();

    $lead = Lead::where('tenant_id', $tenant->id)->where('name', 'Self Assigned Lead')->firstOrFail();

    expect($lead->assigned_to)->toBe($rep->id);
});

test('a user without lead-management create permission cannot create a manual lead', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view']);

    $component = Livewire::actingAs($rep)->test(Index::class)->call('completeInitialLoad');

    expect($component->instance()->canCreateLead)->toBeFalse();

    $component->call('createLead')->assertDispatched('toast-show');
    expect($component->instance()->showCreateLeadModal)->toBeFalse();
    expect(Lead::where('tenant_id', $tenant->id)->exists())->toBeFalse();
});

test('name and phone are required to create a manual lead', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('createLead')
        ->call('saveLead')
        ->assertHasErrors(['leadName' => 'required', 'leadPhone' => 'required']);

    expect(Lead::where('tenant_id', $tenant->id)->exists())->toBeFalse();
});

test('a contact name with digits or stray symbols is rejected', function (string $invalidName) {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('createLead')
        ->set('leadName', $invalidName)
        ->set('leadPhone', '+8801700000097')
        ->call('saveLead')
        ->assertHasErrors(['leadName' => 'regex']);

    expect(Lead::where('tenant_id', $tenant->id)->exists())->toBeFalse();
})->with([
    'Rafiqul123',
    'Rafiqul <script>',
    '123456',
]);

test('a phone number that is too short or has invalid characters is rejected', function (string $invalidPhone) {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('createLead')
        ->set('leadName', 'Valid Name')
        ->set('leadPhone', $invalidPhone)
        ->call('saveLead')
        ->assertHasErrors(['leadPhone' => 'regex']);

    expect(Lead::where('tenant_id', $tenant->id)->exists())->toBeFalse();
})->with([
    '12345',
    'call me maybe',
    '() - --',
]);

test('a website url without http or https is rejected', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('createLead')
        ->set('leadName', 'Valid Name')
        ->set('leadPhone', '+8801700000096')
        ->set('leadWebsite', 'ftp://apexlogistics.com')
        ->call('saveLead')
        ->assertHasErrors(['leadWebsite']);

    expect(Lead::where('tenant_id', $tenant->id)->exists())->toBeFalse();
});

test('a budget in scientific notation or with thousands separators is rejected', function (string $invalidAmount) {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('createLead')
        ->set('leadName', 'Valid Name')
        ->set('leadPhone', '+8801700000095')
        ->set('leadEstimatedBudget', $invalidAmount)
        ->call('saveLead')
        ->assertHasErrors(['leadEstimatedBudget']);

    expect(Lead::where('tenant_id', $tenant->id)->exists())->toBeFalse();
})->with([
    '2.5e10',
    '250,000',
    '250.999',
]);

test('a next follow-up date in the past is rejected', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('createLead')
        ->set('leadName', 'Valid Name')
        ->set('leadPhone', '+8801700000094')
        ->set('leadNextFollowUpAt', now()->subDay()->toDateString())
        ->call('saveLead')
        ->assertHasErrors(['leadNextFollowUpAt' => 'after_or_equal']);

    expect(Lead::where('tenant_id', $tenant->id)->exists())->toBeFalse();
});

test('a meeting date in the past is rejected', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('createLead')
        ->set('leadName', 'Valid Name')
        ->set('leadPhone', '+8801700000094')
        ->set('leadMeetingDate', now()->subDay()->format('Y-m-d').'T09:00')
        ->call('saveLead')
        ->assertHasErrors(['leadMeetingDate' => 'after_or_equal']);

    expect(Lead::where('tenant_id', $tenant->id)->exists())->toBeFalse();
});

/**
 * M9 — a Client Admin leaving the assignee dropdown blank previously left
 * every manual lead unassigned regardless of the tenant's distribution
 * settings (LeadAssignmentService::autoAssign() was structurally
 * unreachable — see CreateManualLead's own docblock history). This proves
 * the new else-branch actually reaches autoAssign() and its existing,
 * unmodified round-robin picking logic. A single eligible candidate is
 * used deliberately, so the result is unambiguous regardless of
 * last_assigned_at ordering.
 */
test('a client admin leaving the assignee blank auto-distributes the lead when the tenant uses automatic distribution', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['lead_distribution_mode' => LeadDistributionMode::Automatic, 'auto_distribution_method' => AutoDistributionMethod::RoundRobin]);
    $rep = clientUserWithLeadPermission($tenant);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('createLead')
        ->set('leadName', 'Auto Distributed Lead')
        ->set('leadPhone', '+8801700000095')
        ->call('saveLead')
        ->assertHasNoErrors();

    $lead = Lead::where('tenant_id', $tenant->id)->where('name', 'Auto Distributed Lead')->firstOrFail();

    expect($lead->assigned_to)->toBe($rep->id);
});

/**
 * M9 — explicit assignment must always win over automatic distribution.
 * $explicitAssignee is a Client Admin — structurally excluded from
 * LeadAssignmentService::eligibleUsers() (user_type = ClientUser only) —
 * so a lead ending up assigned to them is proof autoAssign() could not
 * have produced that result; $autoDistributionCandidate is the sole
 * eligible round-robin candidate, present only to prove it was NOT
 * picked instead. This is deterministic and does not depend on
 * last_assigned_at ordering.
 */
test('a client admin\'s explicit assignee choice is preserved even when the tenant uses automatic distribution', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['lead_distribution_mode' => LeadDistributionMode::Automatic, 'auto_distribution_method' => AutoDistributionMethod::RoundRobin]);
    $autoDistributionCandidate = clientUserWithLeadPermission($tenant);
    $explicitAssignee = TenantUser::factory()->clientAdmin()->create(['tenant_id' => $tenant->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('createLead')
        ->set('leadName', 'Explicitly Assigned Lead')
        ->set('leadPhone', '+8801700000096')
        ->set('leadAssigneeId', $explicitAssignee->id)
        ->call('saveLead')
        ->assertHasNoErrors();

    $lead = Lead::where('tenant_id', $tenant->id)->where('name', 'Explicitly Assigned Lead')->firstOrFail();

    expect($lead->assigned_to)->toBe($explicitAssignee->id);
    expect($lead->assigned_to)->not->toBe($autoDistributionCandidate->id);
});

/**
 * M9 — the tenant default (Manual distribution, unchanged by this
 * closure) must continue to leave a blank-assignee manual lead
 * unassigned, exactly as before this change: LeadAssignmentService::
 * autoAssign() no-ops immediately when lead_distribution_mode !==
 * Automatic — see its own existing, unmodified guard clause.
 */
test('a client admin leaving the assignee blank stays unassigned when the tenant uses manual distribution', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    expect($tenant->lead_distribution_mode)->toBe(LeadDistributionMode::Manual);
    clientUserWithLeadPermission($tenant);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('createLead')
        ->set('leadName', 'Still Unassigned Lead')
        ->set('leadPhone', '+8801700000097')
        ->call('saveLead')
        ->assertHasNoErrors();

    $lead = Lead::where('tenant_id', $tenant->id)->where('name', 'Still Unassigned Lead')->firstOrFail();

    expect($lead->assigned_to)->toBeNull();
});

/**
 * M9 — manual lead creation must continue to never fire LeadReceived,
 * regardless of whether the new autoAssign() branch runs — mirrors
 * CreateLeadFromImportTest.php's identically-shaped CSV proof
 * ('LeadReceived and LeadUpdated never fire for a CSV import row').
 */
test('manual lead creation never fires LeadReceived', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['lead_distribution_mode' => LeadDistributionMode::Automatic, 'auto_distribution_method' => AutoDistributionMethod::RoundRobin]);
    clientUserWithLeadPermission($tenant);

    Event::fake([LeadReceived::class]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('createLead')
        ->set('leadName', 'Silent Manual Lead')
        ->set('leadPhone', '+8801700000098')
        ->call('saveLead')
        ->assertHasNoErrors();

    Event::assertNotDispatched(LeadReceived::class);
});
