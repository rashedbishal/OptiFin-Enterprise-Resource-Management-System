<?php

use App\Livewire\Platform\LeadManagement\Settings;
use App\Models\Currency;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\PlatformUser;
use App\Models\Tenant;
use Livewire\Livewire;

test('mother admin can create a custom lead status', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Settings::class)
        ->set('statusName', 'On Hold')
        ->set('statusColor', 'purple')
        ->set('statusIsClosed', false)
        ->call('saveStatus')
        ->assertHasNoErrors();

    $status = LeadStatus::where('slug', 'on-hold')->firstOrFail();
    expect($status->name)->toBe('On Hold');
    expect($status->color)->toBe('purple');
    expect($status->is_closed)->toBeFalse();
});

test('mother admin can edit an existing lead status', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $status = LeadStatus::factory()->create(['name' => 'Draft', 'color' => 'zinc']);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Settings::class)
        ->call('editStatus', $status->id)
        ->set('statusName', 'Archived')
        ->set('statusColor', 'red')
        ->set('statusIsClosed', true)
        ->call('saveStatus')
        ->assertHasNoErrors();

    $status->refresh();
    expect($status->name)->toBe('Archived');
    expect($status->color)->toBe('red');
    expect($status->is_closed)->toBeTrue();
});

test('two statuses cannot share the same name', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    LeadStatus::factory()->create(['name' => 'Escalated', 'slug' => 'escalated']);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Settings::class)
        ->set('statusName', 'Escalated')
        ->call('saveStatus');

    expect(LeadStatus::where('name', 'Escalated')->count())->toBe(1);
});

test('a status referenced by a lead cannot be deleted', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $status = $tenant->leadStatuses()->first();
    Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => $status->id]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Settings::class)
        ->call('deleteStatus', $status->id)
        ->assertDispatched('toast-show');

    expect(LeadStatus::whereKey($status->id)->exists())->toBeTrue();
});

test('a status selected in a tenant pipeline but used by no leads cannot be deleted', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $status = $tenant->leadStatuses()->first();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Settings::class)
        ->call('deleteStatus', $status->id)
        ->assertDispatched('toast-show');

    expect(LeadStatus::whereKey($status->id)->exists())->toBeTrue();
});

test('a status used by no client can be deleted', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $status = LeadStatus::factory()->create();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Settings::class)
        ->call('deleteStatus', $status->id)
        ->assertDispatched('toast-show');

    expect(LeadStatus::whereKey($status->id)->exists())->toBeFalse();
});

test('deleting a status goes through the confirm modal', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $status = LeadStatus::factory()->create();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Settings::class)
        ->call('confirmDeleteStatus', $status->id)
        ->assertSet('showDeleteStatusModal', true)
        ->assertSet('confirmingDeleteStatusId', $status->id)
        ->call('deleteStatusConfirmed')
        ->assertSet('showDeleteStatusModal', false);

    expect(LeadStatus::whereKey($status->id)->exists())->toBeFalse();
});

test('mother admin can create a custom currency', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Settings::class)
        ->set('currencyCode', 'eur')
        ->set('currencyName', 'Euro')
        ->call('saveCurrency')
        ->assertHasNoErrors();

    $currency = Currency::where('code', 'EUR')->firstOrFail();
    expect($currency->name)->toBe('Euro');
});

test('mother admin can edit an existing currency', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $currency = Currency::factory()->create(['code' => 'GBP', 'name' => 'Pound']);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Settings::class)
        ->call('editCurrency', $currency->id)
        ->set('currencyName', 'British Pound')
        ->call('saveCurrency')
        ->assertHasNoErrors();

    expect($currency->fresh()->name)->toBe('British Pound');
});

test('two currencies cannot share the same code', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    Currency::factory()->create(['code' => 'JPY']);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Settings::class)
        ->set('currencyCode', 'jpy')
        ->set('currencyName', 'Japanese Yen (duplicate)')
        ->call('saveCurrency')
        ->assertHasErrors(['currencyCode']);

    expect(Currency::where('code', 'JPY')->count())->toBe(1);
});

test('a currency referenced by a lead cannot be deleted', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $currency = $tenant->currencies()->first();
    Lead::factory()->create(['tenant_id' => $tenant->id, 'estimated_budget' => 100, 'estimated_budget_currency_id' => $currency->id]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Settings::class)
        ->call('deleteCurrency', $currency->id)
        ->assertDispatched('toast-show');

    expect(Currency::whereKey($currency->id)->exists())->toBeTrue();
});

test('a currency enabled for a tenant but used by no leads cannot be deleted', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $currency = $tenant->currencies()->first();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Settings::class)
        ->call('deleteCurrency', $currency->id)
        ->assertDispatched('toast-show');

    expect(Currency::whereKey($currency->id)->exists())->toBeTrue();
});

test('a currency used by no client can be deleted', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $currency = Currency::factory()->create();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Settings::class)
        ->call('deleteCurrency', $currency->id)
        ->assertDispatched('toast-show');

    expect(Currency::whereKey($currency->id)->exists())->toBeFalse();
});

test('deleting a currency goes through the confirm modal', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $currency = Currency::factory()->create();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Settings::class)
        ->call('confirmDeleteCurrency', $currency->id)
        ->assertSet('showDeleteCurrencyModal', true)
        ->assertSet('confirmingDeleteCurrencyId', $currency->id)
        ->call('deleteCurrencyConfirmed')
        ->assertSet('showDeleteCurrencyModal', false);

    expect(Currency::whereKey($currency->id)->exists())->toBeFalse();
});
