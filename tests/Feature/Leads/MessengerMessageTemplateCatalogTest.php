<?php

use App\Enums\LeadSourceType;
use App\Livewire\Tenant\MessengerTemplates\Index;
use App\Models\LeadSource;
use App\Models\MessengerMessageTemplate;
use Livewire\Livewire;

/*
 * Tenant self-service catalog of Messenger Utility Message Templates
 * already approved in Meta's tools. The page itself requires a Messenger
 * lead source (mount() redirects away otherwise, same convention as
 * WhatsAppMessageTemplateCatalogTest's own redirect test).
 */

function messengerSource(string $tenantId): LeadSource
{
    return LeadSource::factory()->create([
        'tenant_id' => $tenantId,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '1', 'page_access_token' => 'page-token'],
    ]);
}

test('a client admin can register a template', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = messengerSource($tenant->id);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('create')
        ->set('name', 'order_update')
        ->set('language', 'en')
        ->set('variableCount', '2')
        ->call('save')
        ->assertHasNoErrors();

    $template = MessengerMessageTemplate::where('tenant_id', $tenant->id)->firstOrFail();
    expect($template->lead_source_id)->toBe($source->id);
    expect($template->name)->toBe('order_update');
    expect($template->language)->toBe('en');
    expect($template->variable_count)->toBe(2);
    expect($template->is_active)->toBeTrue();
});

test('a template name that is not lowercase/numbers/underscores is rejected', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    messengerSource($tenant->id);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('create')
        ->set('name', 'Order Update!')
        ->set('language', 'en')
        ->call('save')
        ->assertHasErrors('name');

    expect(MessengerMessageTemplate::where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('a duplicate name/language on the same source is rejected', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = messengerSource($tenant->id);
    MessengerMessageTemplate::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_source_id' => $source->id,
        'name' => 'order_update',
        'language' => 'en',
    ]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('create')
        ->set('name', 'order_update')
        ->set('language', 'en')
        ->call('save')
        ->assertHasErrors('name');

    expect(MessengerMessageTemplate::where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('toggleActive flips a template on and off without deleting it', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = messengerSource($tenant->id);
    $template = MessengerMessageTemplate::factory()->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('toggleActive', $template->id);

    expect($template->fresh()->is_active)->toBeFalse();
});

test('a client admin can delete a registered template', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = messengerSource($tenant->id);
    $template = MessengerMessageTemplate::factory()->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('confirmDelete', $template->id)
        ->call('deleteConfirmed');

    expect(MessengerMessageTemplate::find($template->id))->toBeNull();
});

test('a client user (non-admin) cannot open the messenger templates page', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    messengerSource($tenant->id);
    $clientUser = clientUserWithLeadPermission($tenant);

    Livewire::actingAs($clientUser)
        ->test(Index::class)
        ->assertForbidden();
});

test('a client admin is redirected away from the page when the tenant has no Messenger lead source', function () {
    ['clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->assertRedirect(route('lead-management.index'));
});
