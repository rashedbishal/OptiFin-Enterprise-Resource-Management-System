<?php

use App\Enums\LeadSourceType;
use App\Livewire\Platform\Clients\LeadSources;
use App\Models\LeadSource;
use App\Models\MessengerMessageTemplate;
use App\Models\PlatformUser;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
 * Platform Admin side — the "Create Template" control on a Messenger lead
 * source row, wiring LeadSources::createTemplate()/saveTemplate() to
 * CreateMessengerMessageTemplate.
 */

function pmtcComponent(Tenant $tenant)
{
    $admin = PlatformUser::query()->where('role_id', null)->first() ?? PlatformUser::factory()->motherAdmin()->create();

    return Livewire::actingAs($admin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('completeInitialLoad');
}

test('a Messenger row shows the templates control, other source types do not', function () {
    $tenant = Tenant::factory()->create();
    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '1', 'page_access_token' => 'token'],
    ]);

    pmtcComponent($tenant)->assertSee('Create Template');

    $other = Tenant::factory()->create();
    LeadSource::factory()->create(['tenant_id' => $other->id, 'type' => LeadSourceType::FacebookLeadAds]);

    pmtcComponent($other)->assertDontSee('Create Template');
});

test('creating a template through the component writes both the Meta call and the catalog row', function () {
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '1', 'page_access_token' => 'token'],
    ]);

    Http::fake(['graph.facebook.com/*' => Http::response(['id' => '999', 'status' => 'APPROVED'], 200)]);

    pmtcComponent($tenant)
        ->call('createTemplate', $source->id)
        ->assertSet('showCreateTemplateModal', true)
        ->set('templateName', 'followup_after_24h')
        ->set('templateLanguage', 'en_US')
        ->set('templateBodyText', 'Hi {{1}}, following up.')
        ->call('saveTemplate')
        ->assertHasNoErrors()
        ->assertSet('showCreateTemplateModal', false)
        ->assertDispatched('toast-show');

    expect(MessengerMessageTemplate::where('tenant_id', $tenant->id)->where('name', 'followup_after_24h')->exists())->toBeTrue();
});

test('a source with an existing template shows a name pill instead of a Create Template button', function () {
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '1', 'page_access_token' => 'token'],
    ]);
    MessengerMessageTemplate::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_source_id' => $source->id,
        'name' => 'followup_after_24h',
        'language' => 'en_US',
        'body_text' => 'Hi {{1}}, following up.',
        'variable_count' => 1,
    ]);

    pmtcComponent($tenant)
        ->assertSee('followup_after_24h')
        // The pill shows only the name — language/body/variable count live
        // behind it, in the details modal, not inline on the row. The
        // modal's own content (including the body text, which appears
        // nowhere else on this page) only renders once viewTemplate() sets
        // a viewing source, so its absence here confirms that.
        ->assertDontSee('Hi {{1}}, following up.')
        ->assertDontSeeHtml('wire:click="createTemplate(\''.$source->id.'\')"');
});

test('clicking the template pill opens a modal with its full details', function () {
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '1', 'page_access_token' => 'token'],
    ]);
    MessengerMessageTemplate::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_source_id' => $source->id,
        'name' => 'followup_after_24h',
        'language' => 'en_US',
        'body_text' => 'Hi {{1}}, following up.',
        'variable_count' => 1,
    ]);

    pmtcComponent($tenant)
        ->call('viewTemplate', $source->id)
        ->assertSet('showTemplateDetailsModal', true)
        ->assertSee('en_US')
        ->assertSee('Hi {{1}}, following up.')
        ->assertSee('1 variable');
});

test('deleting the existing template through the component calls Meta and removes the catalog row', function () {
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '1', 'page_access_token' => 'token'],
    ]);
    $template = MessengerMessageTemplate::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_source_id' => $source->id,
        'name' => 'followup_after_24h',
    ]);

    Http::fake(['graph.facebook.com/*' => Http::response(['success' => true], 200)]);

    pmtcComponent($tenant)
        ->call('confirmDeleteTemplate', $source->id)
        ->assertSet('showDeleteTemplateModal', true)
        ->call('deleteTemplateConfirmed')
        ->assertSet('showDeleteTemplateModal', false)
        ->assertDispatched('toast-show');

    expect(MessengerMessageTemplate::find($template->id))->toBeNull();

    // The row goes back to offering "Create Template" once its one
    // template is gone.
    pmtcComponent($tenant)->assertSee('Create Template');
});

test('the example body text is shown and can be applied with one click', function () {
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '1', 'page_access_token' => 'token'],
    ]);

    pmtcComponent($tenant)
        ->call('createTemplate', $source->id)
        ->assertSee(LeadSources::EXAMPLE_TEMPLATE_BODY)
        ->call('useExampleTemplateBody')
        ->assertSet('templateBodyText', LeadSources::EXAMPLE_TEMPLATE_BODY);
});

test('an invalid template name is rejected client-side without calling Meta', function () {
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '1', 'page_access_token' => 'token'],
    ]);

    Http::fake();

    pmtcComponent($tenant)
        ->call('createTemplate', $source->id)
        ->set('templateName', 'Not A Valid Name!')
        ->set('templateLanguage', 'en_US')
        ->set('templateBodyText', 'Hello.')
        ->call('saveTemplate')
        ->assertHasErrors('templateName');

    Http::assertNothingSent();
});
