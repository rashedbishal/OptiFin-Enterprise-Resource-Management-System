<?php

use App\Livewire\Tenant\Leads\Index;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Currency;
use App\Models\Lead;
use Livewire\Livewire;

/**
 * Est. Budget and Deal Value are almost always quoted in the same currency,
 * so the two pickers on the "New Lead" form stay mirrored — changing either
 * one updates the other instantly, rather than letting them drift apart.
 */
test('changing the estimated budget currency mirrors it onto deal value', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $bdt = Currency::factory()->create(['code' => 'BDT', 'name' => 'Bangladeshi Taka']);
    $tenant->currencies()->attach($bdt->id);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('createLead')
        ->set('leadEstimatedBudgetCurrencyId', $bdt->id)
        ->assertSet('leadDealValueCurrencyId', $bdt->id);
});

test('changing the deal value currency mirrors it onto estimated budget', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $bdt = Currency::factory()->create(['code' => 'BDT', 'name' => 'Bangladeshi Taka']);
    $tenant->currencies()->attach($bdt->id);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('createLead')
        ->set('leadDealValueCurrencyId', $bdt->id)
        ->assertSet('leadEstimatedBudgetCurrencyId', $bdt->id);
});

/**
 * The Kanban flyout (opened by clicking a card) edits the same Est.
 * Budget / Deal Value fields, but through App\Livewire\Tenant\Leads\Show
 * (rendered with panel=true) rather than Index — a separate component with
 * its own copy of these properties, so the mirroring has to be wired there
 * too, not just on the "New Lead" form.
 */
test('the kanban flyout mirrors the estimated budget currency onto deal value', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $bdt = Currency::factory()->create(['code' => 'BDT', 'name' => 'Bangladeshi Taka']);
    $tenant->currencies()->attach($bdt->id);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead, 'panel' => true])->call('completeInitialLoad')
        ->set('leadEstimatedBudgetCurrencyId', $bdt->id)
        ->assertSet('leadDealValueCurrencyId', $bdt->id);
});

test('the kanban flyout mirrors the deal value currency onto estimated budget', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $bdt = Currency::factory()->create(['code' => 'BDT', 'name' => 'Bangladeshi Taka']);
    $tenant->currencies()->attach($bdt->id);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead, 'panel' => true])->call('completeInitialLoad')
        ->set('leadDealValueCurrencyId', $bdt->id)
        ->assertSet('leadEstimatedBudgetCurrencyId', $bdt->id);
});

test('a currency resolves its display symbol, falling back to its code', function (string $code, string $symbol, string $symbolLabel) {
    $currency = new Currency(['code' => $code, 'name' => 'Test']);

    expect($currency->symbol())->toBe($symbol)
        ->and($currency->symbolLabel())->toBe($symbolLabel);
})->with([
    'intl symbol' => ['EUR', '€', '€ EUR'],
    'fallback map' => ['BDT', '৳', '৳ BDT'],
    'unknown code' => ['XTS', 'XTS', 'XTS'],
]);
