<?php

use App\Livewire\Tenant\WhatsAppTemplates\Index;
use App\Models\WhatsAppMessageTemplate;
use Livewire\Livewire;
use Tests\Support\WhatsAppCallFixtures;

/*
 * Tenant self-service catalog of WhatsApp message templates already
 * approved in Meta's WhatsApp Manager. The page itself requires a WhatsApp
 * lead source (mount() redirects away otherwise, same convention as
 * CallingSettingsRingtoneTest's own redirect test), so every test
 * exercising the page's actual behavior sets one up via
 * WhatsAppCallFixtures::source().
 */

test('a client admin can register a template', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = WhatsAppCallFixtures::source($tenant);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('create')
        ->set('name', 'order_update')
        ->set('language', 'en_US')
        ->set('variableCount', '2')
        ->call('save')
        ->assertHasNoErrors();

    $template = WhatsAppMessageTemplate::where('tenant_id', $tenant->id)->firstOrFail();
    expect($template->lead_source_id)->toBe($source->id);
    expect($template->name)->toBe('order_update');
    expect($template->language)->toBe('en_US');
    expect($template->variable_count)->toBe(2);
    expect($template->is_active)->toBeTrue();
});

test('a template name that is not lowercase/numbers/underscores is rejected', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    WhatsAppCallFixtures::source($tenant);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('create')
        ->set('name', 'Order Update!')
        ->set('language', 'en_US')
        ->call('save')
        ->assertHasErrors('name');

    expect(WhatsAppMessageTemplate::where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('a duplicate name/language on the same source is rejected', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = WhatsAppCallFixtures::source($tenant);
    WhatsAppMessageTemplate::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_source_id' => $source->id,
        'name' => 'order_update',
        'language' => 'en_US',
    ]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('create')
        ->set('name', 'order_update')
        ->set('language', 'en_US')
        ->call('save')
        ->assertHasErrors('name');

    expect(WhatsAppMessageTemplate::where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('toggleActive flips a template on and off without deleting it', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = WhatsAppCallFixtures::source($tenant);
    $template = WhatsAppMessageTemplate::factory()->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('toggleActive', $template->id);

    expect($template->fresh()->is_active)->toBeFalse();
});

test('a client admin can delete a registered template', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = WhatsAppCallFixtures::source($tenant);
    $template = WhatsAppMessageTemplate::factory()->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('confirmDelete', $template->id)
        ->call('deleteConfirmed');

    expect(WhatsAppMessageTemplate::find($template->id))->toBeNull();
});

test('a client user (non-admin) cannot open the whatsapp templates page', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    WhatsAppCallFixtures::source($tenant);
    $clientUser = clientUserWithLeadPermission($tenant);

    Livewire::actingAs($clientUser)
        ->test(Index::class)
        ->assertForbidden();
});

test('a client admin is redirected away from the page when the tenant has no WhatsApp lead source', function () {
    ['clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->assertRedirect(route('lead-management.index'));
});
