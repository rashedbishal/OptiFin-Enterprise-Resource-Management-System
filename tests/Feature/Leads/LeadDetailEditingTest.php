<?php

use App\Livewire\Tenant\Leads\Show;
use App\Models\Lead;
use App\Models\LeadDetailUpdateLog;
use Livewire\Livewire;

test('a client admin can update a lead\'s contact details', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Old Name', 'phone' => null, 'email' => null]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
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
        ->test(Show::class, ['lead' => $lead])
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
    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'service_interest' => null, 'estimated_budget' => null, 'deal_value' => null,
        'win_probability' => null, 'next_follow_up_at' => null, 'meeting_date' => null,
    ]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->set('leadServiceInterest', 'Custom Web Application & SaaS')
        ->set('leadEstimatedBudget', '250000')
        ->set('leadDealValue', '250000')
        ->set('leadWinProbability', '50')
        ->set('leadNextFollowUpAt', now()->addDays(3)->toDateString())
        ->set('leadMeetingDate', now()->addDays(5)->toDateString())
        ->call('updateDeal')
        ->assertHasNoErrors();

    $lead->refresh();
    expect($lead->service_interest)->toBe('Custom Web Application & SaaS');
    expect((float) $lead->estimated_budget)->toBe(250000.0);
    expect((float) $lead->deal_value)->toBe(250000.0);
    expect($lead->win_probability)->toBe(50);
    expect($lead->next_follow_up_at->toDateString())->toBe(now()->addDays(3)->toDateString());
    expect($lead->meeting_date->toDateString())->toBe(now()->addDays(5)->toDateString());

    $log = LeadDetailUpdateLog::where('lead_id', $lead->id)->firstOrFail();
    expect($log->changed_fields)->toEqualCanonicalizing(['service_interest', 'estimated_budget', 'deal_value', 'win_probability', 'next_follow_up_at', 'meeting_date']);
});

test('a meeting date in the past is rejected when editing deal details', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => null]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->set('leadMeetingDate', now()->subDay()->toDateString())
        ->call('updateDeal')
        ->assertHasErrors(['leadMeetingDate' => 'after_or_equal']);

    expect($lead->fresh()->meeting_date)->toBeNull();
});

test('saving with no actual changes does not create an activity log entry', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'company' => 'Existing Co']);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->set('leadCompany', 'Existing Co')
        ->call('updateCompany')
        ->assertHasNoErrors();

    expect(LeadDetailUpdateLog::where('lead_id', $lead->id)->exists())->toBeFalse();
});

test('the activity feed shows a combined entry naming the changed fields', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'company' => null, 'website' => null]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->set('leadCompany', 'Apex Global Logistics Ltd.')
        ->set('leadWebsite', 'https://apexlogistics.com')
        ->call('updateCompany');

    $component = Livewire::actingAs($clientAdmin)->test(Show::class, ['lead' => $lead]);
    $activity = $component->instance()->activity();

    expect($activity[0]['type'])->toBe('details_updated');
    expect($activity[0]['fields'])->toEqualCanonicalizing(['Company', 'Website']);
});

test('a sales rep without update permission cannot edit lead details', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'company' => 'Original Co', 'assigned_to' => $rep->id]);

    Livewire::actingAs($rep)
        ->test(Show::class, ['lead' => $lead])
        ->set('leadCompany', 'Hijacked Co')
        ->call('updateCompany')
        ->assertDispatched('toast-show');

    expect($lead->fresh()->company)->toBe('Original Co');
});

test('updating deal details from the panel notifies the board instead of redirecting', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'win_probability' => null]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead, 'panel' => true])
        ->set('leadWinProbability', '75')
        ->call('updateDeal')
        ->assertDispatched('lead-panel-updated');

    expect($lead->fresh()->win_probability)->toBe(75);
});
