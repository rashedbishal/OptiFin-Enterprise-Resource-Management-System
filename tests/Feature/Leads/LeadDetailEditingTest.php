<?php

use App\Enums\LeadSourceType;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Lead;
use App\Models\LeadDetailUpdateLog;
use App\Models\LeadSource;
use App\Models\TenantUser;
use App\Notifications\FollowUpUpdatedNotification;
use App\Notifications\MeetingUpdatedNotification;
use Livewire\Livewire;

test('the meeting date field is pre-filled as a single date+time value from the stored date and time', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => '2026-09-05', 'meeting_time' => '14:30']);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->assertSet('leadMeetingDate', '2026-09-05T14:30');
});

test('the meeting date field defaults the time to midnight when only a date was stored', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => '2026-09-05', 'meeting_time' => null]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->assertSet('leadMeetingDate', '2026-09-05T00:00');
});

test('a client admin can update a lead\'s contact details', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Old Name', 'phone' => null, 'email' => null]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->set('leadName', 'New Name')
        ->set('leadPhone', '+8801712345678')
        ->set('leadEmail', 'lead@example.com')
        ->call('updateContact')
        ->assertHasNoErrors();

    $lead->refresh();
    expect($lead->name)->toBe('New Name');
    expect($lead->phone)->toBe('+8801712345678');
    expect($lead->email)->toBe('lead@example.com');

    $log = LeadDetailUpdateLog::where('lead_id', $lead->id)->firstOrFail();
    expect($log->changed_fields)->toEqualCanonicalizing(['name', 'phone', 'email']);
});

test('an automated lead with no company details yet can have them filled in', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'company' => null, 'website' => null, 'industry' => null, 'location' => null,
    ]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->set('leadCompany', 'Apex Global Logistics Ltd.')
        ->set('leadWebsite', 'https://apexlogistics.com')
        ->set('leadIndustry', 'Technology & Digital')
        ->set('leadLocation', 'Dhaka, Bangladesh')
        ->call('updateCompany')
        ->assertHasNoErrors();

    $lead->refresh();
    expect($lead->company)->toBe('Apex Global Logistics Ltd.');
    expect($lead->website)->toBe('https://apexlogistics.com');
    expect($lead->industry)->toBe('Technology & Digital');
    expect($lead->location)->toBe('Dhaka, Bangladesh');

    $log = LeadDetailUpdateLog::where('lead_id', $lead->id)->firstOrFail();
    expect($log->changed_fields)->toEqualCanonicalizing(['company', 'website', 'industry', 'location']);
});

test('deal details can be filled in on a lead that never had them', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $currency = $tenant->currencies()->first();
    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'service_interest' => null, 'estimated_budget' => null, 'deal_value' => null,
        'win_probability' => null, 'next_follow_up_at' => null, 'meeting_date' => null,
    ]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->set('leadServiceInterest', 'Custom Web Application & SaaS')
        ->set('leadEstimatedBudget', '250000')
        ->set('leadEstimatedBudgetCurrencyId', $currency->id)
        ->set('leadDealValue', '250000')
        ->set('leadDealValueCurrencyId', $currency->id)
        ->set('leadWinProbability', '50')
        ->set('leadNextFollowUpAt', now()->addDays(3)->toDateString())
        ->set('leadMeetingDate', now()->addDays(5)->format('Y-m-d').'T14:30')
        ->call('updateDeal')
        ->assertHasNoErrors();

    $lead->refresh();
    expect($lead->service_interest)->toBe('Custom Web Application & SaaS');
    expect((float) $lead->estimated_budget)->toBe(250000.0);
    expect($lead->estimated_budget_currency_id)->toBe($currency->id);
    expect((float) $lead->deal_value)->toBe(250000.0);
    expect($lead->deal_value_currency_id)->toBe($currency->id);
    expect($lead->win_probability)->toBe(50);
    expect($lead->next_follow_up_at->toDateString())->toBe(now()->addDays(3)->toDateString());
    expect($lead->meeting_date->toDateString())->toBe(now()->addDays(5)->toDateString());
    expect(substr($lead->meeting_time, 0, 5))->toBe('14:30');
    expect($lead->meetingTimeFormatted())->toBe('2:30 PM');

    $log = LeadDetailUpdateLog::where('lead_id', $lead->id)->firstOrFail();
    expect($log->changed_fields)->toEqualCanonicalizing([
        'service_interest', 'estimated_budget', 'estimated_budget_currency_id',
        'deal_value', 'deal_value_currency_id', 'win_probability', 'next_follow_up_at', 'meeting_date', 'meeting_time',
    ]);
});

test('scheduling a meeting for the first time notifies other admins, but not the one who scheduled it', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $otherAdmin = TenantUser::factory()->clientAdmin()->create(['tenant_id' => $tenant->id]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => null]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->set('leadMeetingDate', now()->addDays(5)->format('Y-m-d').'T14:30')
        ->call('updateDeal');

    expect($clientAdmin->notifications()->where('type', MeetingUpdatedNotification::class)->count())->toBe(0);

    $notification = $otherAdmin->notifications()->where('type', MeetingUpdatedNotification::class)->firstOrFail();
    expect($notification->data['rescheduled'])->toBeFalse();
    expect($notification->data['meeting_time'])->toBe('2:30 PM');
});

test('changing an already-scheduled meeting to a different date notifies admins that it was rescheduled', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $otherAdmin = TenantUser::factory()->clientAdmin()->create(['tenant_id' => $tenant->id]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => now()->addDays(2)]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->set('leadMeetingDate', now()->addDays(9)->format('Y-m-d').'T10:00')
        ->call('updateDeal');

    $notification = $otherAdmin->notifications()->where('type', MeetingUpdatedNotification::class)->firstOrFail();
    expect($notification->data['rescheduled'])->toBeTrue();
});

test('resubmitting the deal form with the same meeting date sends no notification', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $otherAdmin = TenantUser::factory()->clientAdmin()->create(['tenant_id' => $tenant->id]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => now()->addDays(2), 'meeting_time' => '10:00:00']);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->set('leadMeetingDate', now()->addDays(2)->format('Y-m-d').'T10:00')
        ->call('updateDeal');

    expect($otherAdmin->notifications()->where('type', MeetingUpdatedNotification::class)->count())->toBe(0);
});

test('scheduling a follow-up for the first time notifies other admins as a fresh schedule, not a reschedule', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $otherAdmin = TenantUser::factory()->clientAdmin()->create(['tenant_id' => $tenant->id]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'next_follow_up_at' => null]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->set('leadNextFollowUpAt', now()->addDays(3)->toDateString())
        ->call('updateDeal');

    $notification = $otherAdmin->notifications()->where('type', FollowUpUpdatedNotification::class)->firstOrFail();
    expect($notification->data['rescheduled'])->toBeFalse();
});

test('the assigned rep scheduling a meeting notifies every admin (there is no self to exclude)', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => null, 'assigned_to' => $rep->id]);

    Livewire::actingAs($rep)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->set('leadMeetingDate', now()->addDays(5)->format('Y-m-d').'T14:30')
        ->call('updateDeal');

    expect($clientAdmin->notifications()->where('type', MeetingUpdatedNotification::class)->count())->toBe(1);
    expect($rep->notifications()->where('type', MeetingUpdatedNotification::class)->count())->toBe(0);
});

test('a meeting date in the past is rejected when editing deal details', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => null]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->set('leadMeetingDate', now()->subDay()->format('Y-m-d').'T09:00')
        ->call('updateDeal')
        ->assertHasErrors(['leadMeetingDate' => 'after_or_equal']);

    expect($lead->fresh()->meeting_date)->toBeNull();
});

test('lead source can be filled in via the sidebar on a manual-entry lead', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $manualSource = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Manual]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $manualSource->id, 'lead_source' => null]);

    $component = Livewire::actingAs($clientAdmin)->test(Show::class, ['lead' => $lead])->call('completeInitialLoad');
    expect($component->instance()->isManualLead)->toBeTrue();

    $component->set('leadSource', 'Referral')
        ->call('updateDeal')
        ->assertHasNoErrors();

    expect($lead->fresh()->lead_source)->toBe('Referral');

    $log = LeadDetailUpdateLog::where('lead_id', $lead->id)->firstOrFail();
    expect($log->changed_fields)->toEqualCanonicalizing(['lead_source']);
});

test('lead source is not shown or editable on a non-manual lead', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'lead_source' => null]);
    expect($lead->source->type)->not->toBe(LeadSourceType::Manual);

    $component = Livewire::actingAs($clientAdmin)->test(Show::class, ['lead' => $lead])->call('completeInitialLoad');
    expect($component->instance()->isManualLead)->toBeFalse();
    $component->assertDontSee('Lead Source');

    // Even a submission that tampers with the property directly (bypassing
    // the hidden field) must not persist — the gate lives server-side in
    // updateDeal(), not just in the view.
    $component->set('leadSource', 'Referral')
        ->call('updateDeal')
        ->assertHasNoErrors();

    expect($lead->fresh()->lead_source)->toBeNull();
    expect(LeadDetailUpdateLog::where('lead_id', $lead->id)->exists())->toBeFalse();
});

test('a client admin sees a manual lead\'s contact/company/deal cards read-only by default on the full page, each with its own Edit link', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $manualSource = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Manual]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $manualSource->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->assertDontSeeHtml('wire:submit="updateContact"')
        ->assertDontSeeHtml('wire:submit="updateCompany"')
        ->assertDontSeeHtml('wire:submit="updateDeal"')
        ->assertSeeHtml('wire:click="startEditingContact"')
        ->assertSeeHtml('wire:click="startEditingCompany"')
        ->assertSeeHtml('wire:click="startEditingDeal"');
});

test('clicking a manual lead\'s Edit link switches only that card into its form, and Save collapses it back', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $manualSource = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Manual]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $manualSource->id, 'company' => 'Old Co']);

    $component = Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->call('startEditingCompany');

    $component->assertSeeHtml('wire:submit="updateCompany"')
        ->assertDontSeeHtml('wire:submit="updateContact"')
        ->assertDontSeeHtml('wire:submit="updateDeal"');

    $component->set('leadCompany', 'New Co')
        ->call('updateCompany')
        ->assertHasNoErrors()
        ->assertSet('editingCompany', false)
        ->assertDontSeeHtml('wire:submit="updateCompany"');

    expect($lead->fresh()->company)->toBe('New Co');
});

test('cancelling a manual lead\'s edit reverts any unsaved draft and collapses back to read-only', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $manualSource = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Manual]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $manualSource->id, 'company' => 'Original Co']);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->call('startEditingCompany')
        ->set('leadCompany', 'Abandoned Draft')
        ->call('cancelEditingCompany')
        ->assertSet('editingCompany', false)
        ->assertSet('leadCompany', 'Original Co')
        ->assertDontSeeHtml('wire:submit="updateCompany"');

    expect($lead->fresh()->company)->toBe('Original Co');
});

test('cancelling one card\'s edit does not wipe an unsaved draft still open in another card', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $manualSource = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Manual]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $manualSource->id, 'company' => 'Original Co', 'service_interest' => 'Original Interest']);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->call('startEditingCompany')
        ->set('leadCompany', 'Unsaved Company Draft')
        ->call('startEditingDeal')
        ->set('leadServiceInterest', 'Unsaved Deal Draft')
        // Cancelling Deal must only revert Deal's own fields.
        ->call('cancelEditingDeal')
        ->assertSet('leadServiceInterest', 'Original Interest')
        ->assertSet('leadCompany', 'Unsaved Company Draft')
        ->assertSet('editingCompany', true);
});

test('a manual lead\'s assignee stays visible on the Overview tab even to a view-only viewer', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view']);
    $manualSource = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Manual]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $manualSource->id, 'assigned_to' => $rep->id]);

    Livewire::actingAs($rep)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->assertSee('Assigned to: '.$rep->name);
});

test('a client admin still sees only read-only contact/company cards for a non-manual lead on the full page', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    expect($lead->source->type)->not->toBe(LeadSourceType::Manual);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->assertDontSeeHtml('wire:submit="updateContact"')
        ->assertDontSeeHtml('wire:submit="updateCompany"')
        ->assertDontSeeHtml('wire:submit="updateDeal"')
        ->assertDontSeeHtml('wire:click="startEditingContact"');
});

test('a manual lead shows no Edit links or forms on the full page to a viewer without update permission', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view']);
    $manualSource = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Manual]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $manualSource->id, 'assigned_to' => $rep->id]);

    Livewire::actingAs($rep)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->assertDontSeeHtml('wire:submit="updateContact"')
        ->assertDontSeeHtml('wire:submit="updateCompany"')
        ->assertDontSeeHtml('wire:submit="updateDeal"')
        ->assertDontSeeHtml('wire:click="startEditingContact"');
});

test('a tampered editingCompany property cannot force a manual lead\'s form open for a viewer without update permission', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view']);
    $manualSource = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Manual]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $manualSource->id, 'assigned_to' => $rep->id, 'company' => 'Original Co']);

    Livewire::actingAs($rep)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->set('editingCompany', true)
        ->assertDontSeeHtml('wire:submit="updateCompany"');
});

test('saving with no actual changes does not create an activity log entry', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'company' => 'Existing Co']);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->set('leadCompany', 'Existing Co')
        ->call('updateCompany')
        ->assertHasNoErrors();

    expect(LeadDetailUpdateLog::where('lead_id', $lead->id)->exists())->toBeFalse();
});

test('the activity feed shows a combined entry naming the changed fields', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'company' => null, 'website' => null]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->set('leadCompany', 'Apex Global Logistics Ltd.')
        ->set('leadWebsite', 'https://apexlogistics.com')
        ->call('updateCompany');

    $component = Livewire::actingAs($clientAdmin)->test(Show::class, ['lead' => $lead])->call('completeInitialLoad');
    $activity = $component->instance()->activity();

    expect($activity[0]['type'])->toBe('details_updated');
    expect($activity[0]['fields'])->toEqualCanonicalizing(['Company', 'Website']);
});

test('a sales rep without update permission cannot edit lead details', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'company' => 'Original Co', 'assigned_to' => $rep->id]);

    Livewire::actingAs($rep)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->set('leadCompany', 'Hijacked Co')
        ->call('updateCompany')
        ->assertDispatched('toast-show');

    expect($lead->fresh()->company)->toBe('Original Co');
});

test('updating deal details from the panel notifies the board instead of redirecting', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'win_probability' => null]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead, 'panel' => true])->call('completeInitialLoad')
        ->set('leadWinProbability', '75')
        ->call('updateDeal')
        ->assertDispatched('lead-panel-updated');

    expect($lead->fresh()->win_probability)->toBe(75);
});
