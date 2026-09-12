<?php

use App\Enums\ConsentSource;
use App\Enums\ConsentStatus;
use App\Enums\ConsentType;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Lead;
use App\Models\LeadConsent;
use App\Models\LeadConsentEvent;
use Livewire\Livewire;

test('a client admin can grant privacy consent from the lead detail page', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('grantConsent', ConsentType::Privacy->value)
        ->assertDispatched('toast-show');

    $consent = LeadConsent::where('lead_id', $lead->id)->where('consent_type', ConsentType::Privacy)->first();
    expect($consent)->not->toBeNull();
    expect($consent->status)->toBe(ConsentStatus::Granted);
    expect($consent->source)->toBe(ConsentSource::Manual);
    expect($consent->changed_by)->toBe($clientAdmin->id);
});

test('a client admin can withdraw marketing consent from the lead detail page', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    $component = Livewire::actingAs($clientAdmin)->test(Show::class, ['lead' => $lead])->call('completeInitialLoad');
    $component->call('grantConsent', ConsentType::Marketing->value);
    $component->call('withdrawConsent', ConsentType::Marketing->value);

    $consent = LeadConsent::where('lead_id', $lead->id)->where('consent_type', ConsentType::Marketing)->first();
    expect($consent->status)->toBe(ConsentStatus::Withdrawn);
    expect(LeadConsentEvent::where('lead_id', $lead->id)->count())->toBe(2);
});

test('a client user without update permission cannot grant or withdraw consent', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id]);

    Livewire::actingAs($rep)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('grantConsent', ConsentType::Privacy->value)
        ->assertDispatched('toast-show');

    expect(LeadConsent::where('lead_id', $lead->id)->exists())->toBeFalse();
});

test('a rep with update permission assigned to the lead can grant consent', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id]);

    Livewire::actingAs($rep)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('grantConsent', ConsentType::Privacy->value);

    expect(LeadConsent::where('lead_id', $lead->id)->where('consent_type', ConsentType::Privacy)->exists())->toBeTrue();
});

test('an invalid consent type string is silently ignored', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('grantConsent', 'not-a-real-type');

    expect(LeadConsent::where('lead_id', $lead->id)->exists())->toBeFalse();
});

test('the consent card displays current status for both consent types', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    LeadConsent::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'consent_type' => ConsentType::Privacy]);
    LeadConsent::factory()->withdrawn()->marketing()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->assertSee('Consent')
        ->assertSee('Granted')
        ->assertSee('Withdrawn');
});

test('a manual consent grant is a distinct activity entry with the acting user attributed', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    $component = Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('grantConsent', ConsentType::Privacy->value);

    $activity = $component->instance()->activity();
    $consentEvent = collect($activity)->firstWhere('type', 'consent_change');

    expect($consentEvent)->not->toBeNull();
    expect($consentEvent['user'])->toBe($clientAdmin->name);
    expect($consentEvent['consent_type'])->toBe(ConsentType::Privacy);
    expect($consentEvent['consent_status'])->toBe(ConsentStatus::Granted);
    expect($consentEvent['consent_source'])->toBe(ConsentSource::Manual);
});

test('a website-form-originated consent event shows the form as the source with no user attribution', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    LeadConsentEvent::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'source' => ConsentSource::WebsiteForm,
        'website_form_name' => 'Contact Us',
        'changed_by' => null,
    ]);

    $activity = Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->instance()
        ->activity();

    $consentEvent = collect($activity)->firstWhere('type', 'consent_change');

    expect($consentEvent['user'])->toBeNull();
    expect($consentEvent['website_form_name'])->toBe('Contact Us');
});

test('consent history is ordered newest first', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    LeadConsentEvent::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'occurred_at' => now()->subDays(2)]);
    LeadConsentEvent::factory()->withdrawn()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'occurred_at' => now()->subDay()]);

    $history = Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->instance()
        ->consentHistory();

    expect($history->first()->status)->toBe(ConsentStatus::Withdrawn);
    expect($history->last()->status)->toBe(ConsentStatus::Granted);
});

test('no code path updates or deletes an existing consent event row', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    $component = Livewire::actingAs($clientAdmin)->test(Show::class, ['lead' => $lead])->call('completeInitialLoad');
    $component->call('grantConsent', ConsentType::Privacy->value);
    $firstEventId = LeadConsentEvent::where('lead_id', $lead->id)->first()->id;

    $component->call('withdrawConsent', ConsentType::Privacy->value);

    // The original grant event still exists, completely unmodified —
    // withdrawal only ever appends a new row.
    $originalEvent = LeadConsentEvent::find($firstEventId);
    expect($originalEvent)->not->toBeNull();
    expect($originalEvent->status)->toBe(ConsentStatus::Granted);
    expect(LeadConsentEvent::where('lead_id', $lead->id)->count())->toBe(2);
});
