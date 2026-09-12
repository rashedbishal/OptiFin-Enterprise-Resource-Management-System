<?php

use App\Enums\LeadPriority;
use App\Enums\LeadScoringField;
use App\Enums\LeadScoringOperator;
use App\Enums\LeadSourceType;
use App\Livewire\Tenant\LeadScoring\Index;
use App\Models\Lead;
use App\Models\LeadScoreLog;
use App\Models\LeadScoringRule;
use App\Services\LeadScoringService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

/**
 * Regression test for the browser QA bug: saving a "Has Email is 1" rule
 * through the Livewire form stored the string "Yes" instead of PHP bool true,
 * so the scoring service never matched a lead that had an email address.
 *
 * Root cause: LeadScoringField::valueOptions() returned ['Yes'=>'1','No'=>'0']
 * (label => value), but x-dropdown-select treats array keys as the stored
 * value and array values as the display label — the opposite mapping.
 * The fix inverts the pair to ['yes'=>'Yes','no'=>'No'] so the correct
 * string 'yes' reaches normalizeValueForStorage() and is converted to bool true.
 */
test('a has-email rule created via the UI form stores a bool and scores a lead with an email', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    // Simulate exactly what the dropdown-select component sends when the user
    // picks "Yes": value='yes' (the key in the corrected ['yes'=>'Yes'] map).
    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('create')
        ->set('name', 'Has Email Test')
        ->set('field', LeadScoringField::HasEmail->value)
        ->set('operator', LeadScoringOperator::Equals->value)
        ->set('value', 'yes')
        ->set('points', '10')
        ->set('sortOrder', '0')
        ->call('save')
        ->assertHasNoErrors();

    $rule = LeadScoringRule::where('tenant_id', $tenant->id)->firstOrFail();

    // The stored value must be PHP bool true — not the string "Yes" or "yes".
    expect($rule->value)->toBeTrue();

    $leadWithEmail = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'test@example.com']);
    $leadWithoutEmail = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => null]);

    $service = app(LeadScoringService::class);

    expect($service->computeFor($leadWithEmail, 'manual'))->toBe(10);
    expect($service->computeFor($leadWithoutEmail, 'manual'))->toBe(0);
});

test('editing a has-email rule pre-selects the correct yes/no option in the form', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $rule = LeadScoringRule::factory()->create([
        'tenant_id' => $tenant->id,
        'field' => LeadScoringField::HasEmail->value,
        'operator' => LeadScoringOperator::Equals->value,
        'value' => true,
        'points' => 10,
    ]);

    // edit() must convert stored bool true back to 'yes' so the dropdown
    // can pre-select the "Yes" option.
    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('edit', $rule->id)
        ->assertSet('value', 'yes');

    // And bool false must come back as 'no'.
    $rule->update(['value' => false]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('edit', $rule->id)
        ->assertSet('value', 'no');
});

/**
 * True reproduction of the browser bug: x-dropdown-select's own inline PHP
 * block (resources/views/components/dropdown-select.blade.php) builds the
 * `options: [...]` array embedded in its Alpine x-data attribute
 * SERVER-SIDE at render time — clicking an option in the browser just does
 * `selected = option.value` against that pre-built array. So the actual
 * value a real click would send to the server is fully determined by what
 * gets rendered here; parsing it out of the real rendered HTML (rather
 * than simulating the assumed-correct ->set('value', ...) the way the
 * tests below this one do) is what actually proves the browser-reported
 * bug is fixed, not just that computeFor() matches given an
 * already-correct value.
 */
test('the rendered Priority value dropdown embeds the enum VALUE as option.value, not its label', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $html = Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('create')
        ->set('field', LeadScoringField::Priority->value)
        ->set('operator', LeadScoringOperator::Equals->value)
        ->html();

    // Blade's {{ }} HTML-escapes the json_encode()'d array before it lands
    // inside the x-data="..." attribute (so a literal `"` in a label never
    // breaks the surrounding HTML attribute quoting) — decode those
    // entities back before parsing as JSON. The form renders 3
    // x-dropdown-select instances in order (Field, Condition, Value) — the
    // Value one (what this test cares about) is the last "options:" match.
    preg_match_all('/options:\s*(\[.*?\]),\s*placeholder:/s', $html, $matches);
    expect($matches[1])->not->toBeEmpty('Could not locate any rendered x-dropdown-select options array in the HTML.');

    $renderedOptions = json_decode(html_entity_decode(end($matches[1])), true);
    $highOption = collect($renderedOptions)->firstWhere('label', 'High');

    expect($highOption)->not->toBeNull();
    // This is the literal string a real browser click sends to the server
    // as $this->value — it must be the enum's value ('high'), never its
    // display label ('High').
    expect($highOption['value'])->toBe('high');
});

/**
 * Regression test for the browser QA bug (2026-09-09): "Priority is high"
 * built through the UI form's dropdown never matched a lead whose priority
 * really was High.
 *
 * Root cause: LeadScoringField::valueOptions() built Priority/SourceType as
 * [label() => value] (e.g. 'High' => 'high'), the same backwards
 * orientation HasEmail/HasPhone were already fixed from once before (see
 * the docblock above) — x-dropdown-select's associative-array branch treats
 * the array KEY as the option's stored value, so picking the row labeled
 * "High" actually bound wire:model="value" to the string "High" (the
 * label), not "high" (the enum value). LeadScoringOperator::normalize()
 * then case-sensitively compared "High" against Lead::priority->value
 * ("high") and never matched. Fixed by inverting the pair to
 * [value => label], matching HasEmail/HasPhone's already-correct
 * orientation.
 */
test('a priority rule created via the UI dropdown stores the enum VALUE, not its label, and matches', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    // Simulate exactly what x-dropdown-select sends when the admin picks
    // the row labeled "High": value = the array KEY of the now-correct
    // [value => label] map, i.e. the enum's actual value 'high'.
    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('create')
        ->set('name', 'Priority High Test')
        ->set('field', LeadScoringField::Priority->value)
        ->set('operator', LeadScoringOperator::Equals->value)
        ->set('value', LeadPriority::High->value)
        ->set('points', '20')
        ->set('sortOrder', '0')
        ->call('save')
        ->assertHasNoErrors();

    $rule = LeadScoringRule::where('tenant_id', $tenant->id)->firstOrFail();

    // The stored value must be the lowercase enum value, never the
    // capitalized display label.
    expect($rule->value)->toBe('high');

    $highPriorityLead = Lead::factory()->create(['tenant_id' => $tenant->id, 'priority' => LeadPriority::High]);
    $lowPriorityLead = Lead::factory()->create(['tenant_id' => $tenant->id, 'priority' => LeadPriority::Low]);

    $service = app(LeadScoringService::class);

    expect($service->computeFor($highPriorityLead, 'manual'))->toBe(20);
    expect($service->computeFor($lowPriorityLead, 'manual'))->toBe(0);
});

test('the exact browser-reported scenario: Has Email (+10) and Priority High (+20) together score 30, both shown in the breakdown', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('create')
        ->set('name', 'Has Email Test')
        ->set('field', LeadScoringField::HasEmail->value)
        ->set('operator', LeadScoringOperator::Equals->value)
        ->set('value', 'yes')
        ->set('points', '10')
        ->set('sortOrder', '0')
        ->call('save')
        ->assertHasNoErrors();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('create')
        ->set('name', 'Priority High Test')
        ->set('field', LeadScoringField::Priority->value)
        ->set('operator', LeadScoringOperator::Equals->value)
        ->set('value', LeadPriority::High->value)
        ->set('points', '20')
        ->set('sortOrder', '1')
        ->call('save')
        ->assertHasNoErrors();

    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com', 'priority' => LeadPriority::High]);

    $score = app(LeadScoringService::class)->computeFor($lead, 'manual');

    expect($score)->toBe(30);

    $log = LeadScoreLog::where('lead_id', $lead->id)->latest('computed_at')->first();
    expect($log->breakdown)->toHaveCount(2);
    expect(collect($log->breakdown)->pluck('rule_name')->all())->toEqualCanonicalizing(['Has Email Test', 'Priority High Test']);
});

test('a source_type rule created via the UI dropdown stores the enum VALUE, not its label, and matches', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('create')
        ->set('name', 'Website Form Source')
        ->set('field', LeadScoringField::SourceType->value)
        ->set('operator', LeadScoringOperator::Equals->value)
        ->set('value', LeadSourceType::WebsiteForm->value)
        ->set('points', '15')
        ->set('sortOrder', '0')
        ->call('save')
        ->assertHasNoErrors();

    $rule = LeadScoringRule::where('tenant_id', $tenant->id)->firstOrFail();
    expect($rule->value)->toBe('website_form');

    // Lead factory's default LeadSource is already type WebsiteForm.
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    expect(app(LeadScoringService::class)->computeFor($lead, 'manual'))->toBe(15);
});

test('editing a priority rule pre-selects the correct dropdown option, not its label', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $rule = LeadScoringRule::factory()->create([
        'tenant_id' => $tenant->id,
        'field' => LeadScoringField::Priority->value,
        'operator' => LeadScoringOperator::Equals->value,
        'value' => 'urgent',
        'points' => 10,
    ]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('edit', $rule->id)
        ->assertSet('value', 'urgent');
});

/**
 * Regression test for a second, browser-only bug found while investigating
 * a report that Priority still failed to match even after the
 * valueOptions() orientation fix above was deployed and re-verified as
 * fully correct at every server-side layer (DB round-trip, Eloquent cast,
 * field resolution, operator evaluation, and the actual recalculateScore()
 * action all traced and proven correct in isolation).
 *
 * Root cause: x-dropdown-select bakes its `options: [...]` array into
 * Alpine's x-data ONCE, the first time that DOM node mounts — Alpine never
 * re-reads x-data on a later Livewire morph/patch of the SAME node, only on
 * a full destroy+recreate. The Value dropdown had no wire:key at all, so
 * reopening the scoring-rule modal (create() or edit(), even for the exact
 * same rule/field) could keep showing whatever options array was baked in
 * the FIRST time that dropdown ever mounted in a given browser tab — stale
 * relative to the current server code even though every subsequent
 * Livewire response already reflects the fix. This can't be exercised by a
 * Livewire::test() HTML assertion (Pest never executes real Alpine/DOM
 * lifecycle), so this test instead proves the necessary server-side
 * precondition: the rendered wire:key changes on every create()/edit()
 * call, which is what forces Livewire to tear down and remount the
 * element (and therefore force Alpine to re-read x-data fresh) instead of
 * reusing a stale mount.
 */
test('the Value dropdown\'s wire:key changes every time the form is (re)opened, forcing a fresh Alpine mount', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $rule = LeadScoringRule::factory()->create([
        'tenant_id' => $tenant->id,
        'field' => LeadScoringField::Priority->value,
        'operator' => LeadScoringOperator::Equals->value,
        'value' => 'high',
    ]);

    $extractKey = function (string $html): string {
        preg_match('/scoring-rule-value-([^"]+)"/', $html, $matches);

        return $matches[1] ?? '';
    };

    $component = Livewire::actingAs($clientAdmin)->test(Index::class);

    $firstOpenKey = $extractKey($component->call('edit', $rule->id)->html());
    $component->call('$set', 'showModal', false);
    // Reopening the SAME rule (same id, same field) a second time — the
    // exact scenario reported: editing one already-open rule again.
    $secondOpenKey = $extractKey($component->call('edit', $rule->id)->html());

    expect($firstOpenKey)->not->toBe('')->not->toBe($secondOpenKey);
});

test('the Value dropdown\'s wire:key also changes when switching between two Enum-kind fields inside an already-open form', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $extractKey = function (string $html): string {
        preg_match('/scoring-rule-value-([^"]+)"/', $html, $matches);

        return $matches[1] ?? '';
    };

    $component = Livewire::actingAs($clientAdmin)->test(Index::class)->call('create');
    $priorityKey = $extractKey($component->set('field', LeadScoringField::Priority->value)->set('operator', LeadScoringOperator::Equals->value)->html());
    $sourceTypeKey = $extractKey($component->set('field', LeadScoringField::SourceType->value)->set('operator', LeadScoringOperator::Equals->value)->html());

    expect($priorityKey)->not->toBe('')->not->toBe($sourceTypeKey);
});

test('a client admin can create a scoring rule', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('create')
        ->set('name', 'High budget')
        ->set('field', LeadScoringField::EstimatedBudget->value)
        ->set('operator', LeadScoringOperator::GreaterThanOrEqual->value)
        ->set('value', '1000000')
        ->set('points', '20')
        ->set('sortOrder', '0')
        ->call('save')
        ->assertHasNoErrors();

    $rule = LeadScoringRule::where('tenant_id', $tenant->id)->firstOrFail();
    expect($rule->name)->toBe('High budget');
    expect($rule->field)->toBe('estimated_budget');
    expect($rule->operator)->toBe('>=');
    expect((float) $rule->value)->toBe(1000000.0);
    expect($rule->points)->toBe(20);
    expect($rule->is_active)->toBeTrue();
});

test('a client admin can create a rule with negative points and zero points', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('create')
        ->set('name', 'Stale lead')
        ->set('field', LeadScoringField::DaysSinceCreated->value)
        ->set('operator', LeadScoringOperator::GreaterThanOrEqual->value)
        ->set('value', '30')
        ->set('points', '-10')
        ->set('sortOrder', '0')
        ->call('save')
        ->assertHasNoErrors();

    expect(LeadScoringRule::where('tenant_id', $tenant->id)->firstOrFail()->points)->toBe(-10);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('create')
        ->set('name', 'Neutral')
        ->set('field', LeadScoringField::HasEmail->value)
        ->set('operator', LeadScoringOperator::Equals->value)
        ->set('value', '1')
        ->set('points', '0')
        ->set('sortOrder', '0')
        ->call('save')
        ->assertHasNoErrors();

    expect(LeadScoringRule::where('tenant_id', $tenant->id)->where('name', 'Neutral')->firstOrFail()->points)->toBe(0);
});

test('an invalid field/operator combination is rejected server-side even if submitted directly', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('create')
        ->set('name', 'Bad combo')
        ->set('field', LeadScoringField::EstimatedBudget->value)
        ->set('operator', LeadScoringOperator::In->value)
        ->set('value', '1000')
        ->set('points', '10')
        ->set('sortOrder', '0')
        ->call('save')
        ->assertHasErrors('operator');

    expect(LeadScoringRule::where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('a client admin can edit, deactivate, and delete a scoring rule', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $rule = LeadScoringRule::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Original', 'points' => 5]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('edit', $rule->id)
        ->set('name', 'Renamed')
        ->set('points', '25')
        ->call('save')
        ->assertHasNoErrors();

    expect($rule->fresh()->name)->toBe('Renamed');
    expect($rule->fresh()->points)->toBe(25);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('toggleActive', $rule->id);

    expect($rule->fresh()->is_active)->toBeFalse();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('deleteConfirmed'); // no confirmingDeleteId set yet -> no-op

    expect($rule->fresh())->not->toBeNull();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('confirmDelete', $rule->id)
        ->call('deleteConfirmed');

    expect(LeadScoringRule::find($rule->id))->toBeNull();
});

test('a client user without admin rights cannot access the scoring rules page', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);

    Livewire::actingAs($rep)
        ->test(Index::class)
        ->assertForbidden();
});

test('a tenant\'s scoring rules never appear for, or can be touched by, another tenant', function () {
    ['tenant' => $tenantA, 'clientAdmin' => $adminA] = tenantWithLeadManagement();
    ['tenant' => $tenantB] = tenantWithLeadManagement();

    $ruleA = LeadScoringRule::factory()->create(['tenant_id' => $tenantA->id, 'name' => 'Tenant A rule']);
    LeadScoringRule::factory()->create(['tenant_id' => $tenantB->id, 'name' => 'Tenant B rule']);

    $component = Livewire::actingAs($adminA)->test(Index::class);

    $component->assertSee('Tenant A rule')->assertDontSee('Tenant B rule');

    expect(fn () => $component->call('edit', LeadScoringRule::where('tenant_id', $tenantB->id)->firstOrFail()->id))
        ->toThrow(ModelNotFoundException::class);

    expect($ruleA->fresh())->not->toBeNull();
});
