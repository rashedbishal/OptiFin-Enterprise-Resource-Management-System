<?php

use App\Enums\LeadSourceType;
use App\Livewire\Platform\Clients\LeadSources;
use App\Models\Currency;
use App\Models\LeadSource;
use App\Models\PlatformUser;
use App\Models\Tenant;
use Livewire\Livewire;

/*
 * Batch 5 — confirmed crash-path fix (Finding #1). LeadSources::
 * selectedType()/save() both called LeadSourceType::from($this->type)
 * directly on a plain wire:model-bound string with no validation, so a
 * tampered Livewire request carrying a non-enum value threw an uncaught
 * ValueError (HTTP 500). save() also let a request through to
 * $this->tenant->leadSources()->create(['type' => LeadSourceType::Manual,
 * ...]) despite typeOptions() excluding Manual/CsvImport from the UI
 * dropdown — that exclusion was never enforced server-side, and since
 * Batch 4 added the unique(tenant_id, singleton_type) constraint, a tenant
 * that already has its lazily-created Manual/CsvImport source would hit an
 * uncaught UniqueConstraintViolationException instead.
 *
 * Batch 6 — confirmed regression fix. Batch 5's Rule::notIn() guard was
 * applied unconditionally, so it also rejected editing an ALREADY-EXISTING
 * Manual/CsvImport source (the only field there is to edit on one is its
 * name) through the same save() method's edit branch — save() is shared
 * between create and edit, and leadSources()/the Edit button have no type
 * gating, so this was a real, reachable regression. Fixed by scoping
 * Rule::notIn() to $this->editingId === null (create only), the same
 * condition the adjacent currency check already uses.
 */

function leadSourceValidationScenario(): array
{
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $tenant->currencies()->attach(Currency::factory()->create()->id);

    return ['motherAdmin' => $motherAdmin, 'tenant' => $tenant];
}

test('an invalid type value does not throw and does not create a LeadSource', function () {
    $x = leadSourceValidationScenario();

    Livewire::actingAs($x['motherAdmin'], 'platform')
        ->test(LeadSources::class, ['tenant' => $x['tenant']])
        ->call('create')
        ->set('type', 'not-a-real-type')
        ->set('name', 'Whatever')
        ->call('save')
        ->assertDispatched('toast-show', fn ($name, $params) => $params['dataset']['variant'] === 'danger');

    expect(LeadSource::where('tenant_id', $x['tenant']->id)->count())->toBe(0);
});

test('selectedType() returns null instead of throwing for an invalid type value', function () {
    $x = leadSourceValidationScenario();

    $component = Livewire::actingAs($x['motherAdmin'], 'platform')
        ->test(LeadSources::class, ['tenant' => $x['tenant']])
        ->call('create')
        ->set('type', 'not-a-real-type');

    expect($component->instance()->selectedType())->toBeNull();
});

test('Manual is rejected by save validation, not silently created', function () {
    $x = leadSourceValidationScenario();

    Livewire::actingAs($x['motherAdmin'], 'platform')
        ->test(LeadSources::class, ['tenant' => $x['tenant']])
        ->call('create')
        ->set('type', LeadSourceType::Manual->value)
        ->set('name', 'Custom')
        ->call('save')
        ->assertHasErrors(['type']);

    expect(LeadSource::where('tenant_id', $x['tenant']->id)->where('type', LeadSourceType::Manual)->exists())->toBeFalse();
});

test('CsvImport is rejected by save validation, not silently created', function () {
    $x = leadSourceValidationScenario();

    Livewire::actingAs($x['motherAdmin'], 'platform')
        ->test(LeadSources::class, ['tenant' => $x['tenant']])
        ->call('create')
        ->set('type', LeadSourceType::CsvImport->value)
        ->set('name', 'CSV Import')
        ->call('save')
        ->assertHasErrors(['type']);

    expect(LeadSource::where('tenant_id', $x['tenant']->id)->where('type', LeadSourceType::CsvImport)->exists())->toBeFalse();
});

test('submitting Manual when the tenant already has its lazily-created Manual source is rejected, not an uncaught unique-constraint crash', function () {
    $x = leadSourceValidationScenario();
    LeadSource::factory()->create(['tenant_id' => $x['tenant']->id, 'type' => LeadSourceType::Manual]);

    Livewire::actingAs($x['motherAdmin'], 'platform')
        ->test(LeadSources::class, ['tenant' => $x['tenant']])
        ->call('create')
        ->set('type', LeadSourceType::Manual->value)
        ->set('name', 'Custom')
        ->call('save')
        ->assertHasErrors(['type']);

    expect(LeadSource::where('tenant_id', $x['tenant']->id)->where('type', LeadSourceType::Manual)->count())->toBe(1);
});

test('a valid WebsiteForm type still saves normally', function () {
    $x = leadSourceValidationScenario();

    Livewire::actingAs($x['motherAdmin'], 'platform')
        ->test(LeadSources::class, ['tenant' => $x['tenant']])
        ->call('create')
        ->set('type', LeadSourceType::WebsiteForm->value)
        ->set('name', 'Website Form')
        ->call('save')
        ->assertHasNoErrors();

    expect(LeadSource::where('tenant_id', $x['tenant']->id)->where('type', LeadSourceType::WebsiteForm)->exists())->toBeTrue();
});

test('an existing Manual source can still be edited (renamed) through save(), not blocked by the singleton create restriction', function () {
    $x = leadSourceValidationScenario();
    $source = LeadSource::factory()->create(['tenant_id' => $x['tenant']->id, 'type' => LeadSourceType::Manual, 'name' => 'Custom']);

    Livewire::actingAs($x['motherAdmin'], 'platform')
        ->test(LeadSources::class, ['tenant' => $x['tenant']])
        ->call('edit', $source->id)
        ->set('name', 'Renamed Manual Source')
        ->call('save')
        ->assertHasNoErrors();

    $source->refresh();
    expect($source->name)->toBe('Renamed Manual Source');
    expect(LeadSource::where('tenant_id', $x['tenant']->id)->where('type', LeadSourceType::Manual)->count())->toBe(1);
});

test('an existing CsvImport source can still be edited (renamed) through save(), not blocked by the singleton create restriction', function () {
    $x = leadSourceValidationScenario();
    $source = LeadSource::factory()->create(['tenant_id' => $x['tenant']->id, 'type' => LeadSourceType::CsvImport, 'name' => 'CSV Import']);

    Livewire::actingAs($x['motherAdmin'], 'platform')
        ->test(LeadSources::class, ['tenant' => $x['tenant']])
        ->call('edit', $source->id)
        ->set('name', 'Renamed CSV Import Source')
        ->call('save')
        ->assertHasNoErrors();

    $source->refresh();
    expect($source->name)->toBe('Renamed CSV Import Source');
    expect(LeadSource::where('tenant_id', $x['tenant']->id)->where('type', LeadSourceType::CsvImport)->count())->toBe(1);
});

test('a valid multi-source type (WhatsApp) still saves normally, including a second one for the same tenant', function () {
    $x = leadSourceValidationScenario();

    Livewire::actingAs($x['motherAdmin'], 'platform')
        ->test(LeadSources::class, ['tenant' => $x['tenant']])
        ->call('create')
        ->set('type', LeadSourceType::Whatsapp->value)
        ->set('name', 'WhatsApp Line 1')
        ->set('config.phone_number_id', '1')
        ->set('config.access_token', 'token-1')
        ->set('config.verify_token', 'verify-1')
        ->call('save')
        ->assertHasNoErrors();

    Livewire::actingAs($x['motherAdmin'], 'platform')
        ->test(LeadSources::class, ['tenant' => $x['tenant']])
        ->call('create')
        ->set('type', LeadSourceType::Whatsapp->value)
        ->set('name', 'WhatsApp Line 2')
        ->set('config.phone_number_id', '2')
        ->set('config.access_token', 'token-2')
        ->set('config.verify_token', 'verify-2')
        ->call('save')
        ->assertHasNoErrors();

    expect(LeadSource::where('tenant_id', $x['tenant']->id)->where('type', LeadSourceType::Whatsapp)->count())->toBe(2);
});
