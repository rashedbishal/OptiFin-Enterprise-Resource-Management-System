<?php

use App\Enums\DealStatus;
use App\Livewire\Platform\LeadManagement\Settings;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Currency;
use App\Models\Deal;
use App\Models\DealStatusLog;
use App\Models\Lead;
use App\Models\PlatformUser;
use App\Services\LeadAssignmentService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('a client admin can create a deal with default currency and owner', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $clientAdmin->id]);
    $currency = $tenant->currencies()->first();

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('createDeal')
        ->assertSet('dealCurrencyId', (string) $currency->id)
        ->assertSet('dealOwnerId', (string) $clientAdmin->id)
        ->set('dealName', 'Initial Package')
        ->set('dealValue', '15000')
        ->set('dealProbability', '60')
        ->set('dealExpectedCloseDate', now()->addDays(14)->toDateString())
        ->call('saveDeal')
        ->assertHasNoErrors();

    $deal = Deal::where('lead_id', $lead->id)->firstOrFail();
    expect($deal->tenant_id)->toBe($tenant->id);
    expect($deal->name)->toBe('Initial Package');
    expect((float) $deal->value)->toBe(15000.0);
    expect($deal->currency_id)->toBe($currency->id);
    expect($deal->probability)->toBe(60);
    expect($deal->status)->toBe(DealStatus::Open);
    expect($deal->owner_id)->toBe($clientAdmin->id);
    expect($deal->created_by)->toBe($clientAdmin->id);
});

test('rendering the deal list does not N+1 per-deal currency/owner lookups', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $currency = $tenant->currencies()->first();
    $owners = collect(range(1, 4))->map(fn () => clientUserWithLeadPermission($tenant));

    foreach ($owners as $owner) {
        Deal::factory()->create([
            'tenant_id' => $tenant->id,
            'lead_id' => $lead->id,
            'currency_id' => $currency->id,
            'owner_id' => $owner->id,
        ]);
    }

    $component = Livewire::actingAs($clientAdmin)->test(Show::class, ['lead' => $lead])->call('completeInitialLoad');

    DB::flushQueryLog();
    DB::enableQueryLog();
    $deals = $component->instance()->deals();
    // Force the lazy `currency`/`owner` relations to actually resolve, the
    // same way the Blade card does — without eager loading this triggers
    // one extra query per deal per relation (2 x 4 = 8 more), not the flat
    // handful an eager-loaded fetch costs regardless of deal count.
    $deals->each(fn ($deal) => [$deal->currency?->code, $deal->owner?->name]);
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queryCount)->toBeLessThanOrEqual(3);
});

test('a lead can have multiple deals simultaneously', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    Deal::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'name' => 'Deal A']);
    Deal::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'name' => 'Deal B']);

    $component = Livewire::actingAs($clientAdmin)->test(Show::class, ['lead' => $lead])->call('completeInitialLoad');

    expect($component->get('deals'))->toHaveCount(2);
});

test('a client admin can edit a deal', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $deal = Deal::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'name' => 'Original']);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('editDeal', $deal->id)
        ->assertSet('dealName', 'Original')
        ->set('dealName', 'Renamed')
        ->set('dealValue', '20000')
        ->call('saveDeal')
        ->assertHasNoErrors();

    $deal->refresh();
    expect($deal->name)->toBe('Renamed');
    expect((float) $deal->value)->toBe(20000.0);
});

test('a client admin can delete a deal', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $deal = Deal::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('confirmDeleteDeal', $deal->id)
        ->call('deleteDealConfirmed');

    expect(Deal::find($deal->id))->toBeNull();
});

test('deleting a deal cascades its status log rows', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $deal = Deal::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('changeDealStatus', $deal->id, 'won');

    expect($deal->statusLogs()->count())->toBe(1);

    $deal->delete();

    expect(DealStatusLog::where('deal_id', $deal->id)->count())->toBe(0);
});

// --- Authorization -------------------------------------------------------

test('an assigned client user with update permission can create and edit deals', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id]);

    Livewire::actingAs($rep)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('createDeal')
        ->set('dealName', 'Rep Deal')
        ->call('saveDeal')
        ->assertHasNoErrors();

    expect(Deal::where('lead_id', $lead->id)->exists())->toBeTrue();
});

test('an assigned client user without update permission cannot create a deal', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id]);

    Livewire::actingAs($rep)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->set('dealName', 'Should Not Save')
        ->call('saveDeal');

    expect(Deal::where('lead_id', $lead->id)->exists())->toBeFalse();
});

test('an assigned client user with update but not delete rights cannot delete a deal', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id]);
    $deal = Deal::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);

    Livewire::actingAs($rep)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('confirmDeleteDeal', $deal->id)
        ->call('deleteDealConfirmed');

    expect(Deal::find($deal->id))->not->toBeNull();
});

test('a client user not assigned to the lead cannot even view it, let alone its deals', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $otherRep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id]);

    Livewire::actingAs($otherRep)
        ->test(Show::class, ['lead' => $lead])
        ->assertForbidden();
});

// --- Tenant isolation ------------------------------------------------------

test('a deal id belonging to another lead cannot be edited through a different lead\'s page', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $leadA = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $leadB = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $dealOnB = Deal::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $leadB->id]);

    $component = Livewire::actingAs($clientAdmin)->test(Show::class, ['lead' => $leadA])->call('completeInitialLoad');

    expect(fn () => $component->call('editDeal', $dealOnB->id))->toThrow(ModelNotFoundException::class);
});

test('a deal belonging to another tenant can never be accessed even with a known deal id', function () {
    ['tenant' => $tenantA, 'clientAdmin' => $adminA] = tenantWithLeadManagement();
    ['tenant' => $tenantB] = tenantWithLeadManagement();
    $leadA = Lead::factory()->create(['tenant_id' => $tenantA->id]);
    $leadB = Lead::factory()->create(['tenant_id' => $tenantB->id]);
    $foreignDeal = Deal::factory()->create(['tenant_id' => $tenantB->id, 'lead_id' => $leadB->id]);

    $component = Livewire::actingAs($adminA)->test(Show::class, ['lead' => $leadA])->call('completeInitialLoad');

    expect(fn () => $component->call('editDeal', $foreignDeal->id))->toThrow(ModelNotFoundException::class);
    expect(fn () => $component->call('changeDealStatus', $foreignDeal->id, 'won'))->toThrow(ModelNotFoundException::class);
    expect(fn () => $component->call('confirmDeleteDeal', $foreignDeal->id)->call('deleteDealConfirmed'))
        ->toThrow(ModelNotFoundException::class);

    expect(Deal::find($foreignDeal->id))->not->toBeNull();
});

// --- Ownership ---------------------------------------------------------

test('a new deal defaults its owner to the lead\'s current assignee', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('createDeal')
        ->set('dealName', 'Owner Default Test')
        ->call('saveDeal');

    $deal = Deal::where('lead_id', $lead->id)->firstOrFail();
    expect($deal->owner_id)->toBe($rep->id);
});

test('a deal\'s owner can be set independently of the lead\'s assignee', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $otherRep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('createDeal')
        ->set('dealName', 'Independent Owner')
        ->set('dealOwnerId', (string) $otherRep->id)
        ->call('saveDeal');

    $deal = Deal::where('lead_id', $lead->id)->firstOrFail();
    expect($deal->owner_id)->toBe($otherRep->id);
});

test('reassigning the lead does not overwrite an already-existing deal\'s owner', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $otherRep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id]);
    $deal = Deal::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'owner_id' => $rep->id]);

    app(LeadAssignmentService::class)->assign($lead, $otherRep, $clientAdmin);

    expect($deal->fresh()->owner_id)->toBe($rep->id);
});

test('a deal owner can be left unassigned', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => null]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('createDeal')
        ->assertSet('dealOwnerId', '')
        ->set('dealName', 'No Owner')
        ->set('dealOwnerId', '')
        ->call('saveDeal')
        ->assertHasNoErrors();

    $deal = Deal::where('lead_id', $lead->id)->firstOrFail();
    expect($deal->owner_id)->toBeNull();
});

// --- Currency ------------------------------------------------------------

test('a deal accepts a currency the tenant has enabled', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $eur = Currency::factory()->create(['code' => 'EUR', 'name' => 'Euro']);
    $tenant->currencies()->attach($eur->id);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('createDeal')
        ->set('dealName', 'Euro Deal')
        ->set('dealValue', '500')
        ->set('dealCurrencyId', (string) $eur->id)
        ->call('saveDeal')
        ->assertHasNoErrors();

    expect(Deal::where('lead_id', $lead->id)->firstOrFail()->currency_id)->toBe($eur->id);
});

test('a currency not enabled for the tenant is rejected for a deal', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    ['tenant' => $otherTenant] = tenantWithLeadManagement();

    // A currency exclusively enabled for $otherTenant — not the shared
    // default "USD" every fresh tenant already has attached (see .ai/rules/
    // migrations-models.md), which would make this test pass for the wrong
    // reason (both tenants already sharing the same currency, not actually
    // exercising the tenant-scoped exists() check).
    $foreignCurrency = Currency::factory()->create(['code' => 'GBP', 'name' => 'British Pound']);
    $otherTenant->currencies()->attach($foreignCurrency->id);

    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('createDeal')
        ->set('dealName', 'Bad Currency Deal')
        ->set('dealValue', '500')
        ->set('dealCurrencyId', (string) $foreignCurrency->id)
        ->call('saveDeal')
        ->assertHasErrors('dealCurrencyId');

    expect(Deal::where('lead_id', $lead->id)->exists())->toBeFalse();
});

test('a currency in use by a deal cannot be deleted from the platform catalog', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $currency = $tenant->currencies()->first();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    Deal::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'currency_id' => $currency->id]);

    $platformAdmin = PlatformUser::factory()->motherAdmin()->create();

    Livewire::actingAs($platformAdmin, 'platform')
        ->test(Settings::class)
        ->call('deleteCurrency', $currency->id);

    expect(Currency::find($currency->id))->not->toBeNull();
});
