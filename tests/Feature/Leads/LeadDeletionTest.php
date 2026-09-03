<?php

use App\Enums\LeadAttachmentStatus;
use App\Enums\LeadSourceType;
use App\Livewire\Tenant\Leads\Index;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Lead;
use App\Models\LeadAttachment;
use App\Models\LeadSource;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('a client admin can delete a website form lead', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::WebsiteForm]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('delete', $lead->id)
        ->assertDispatched('toast-show');

    expect(Lead::find($lead->id))->toBeNull();
});

test('a client admin can delete an email lead', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Email]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('delete', $lead->id);

    expect(Lead::find($lead->id))->toBeNull();
});

test('deleting a lead also removes all of its attachments from disk', function () {
    Storage::fake('local');
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::WebsiteForm]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    $paths = [
        "leads/{$tenant->tenant_code}/messenger/psid-1_123.jpg",
        "leads/{$tenant->tenant_code}/messenger/psid-1_456.jpg",
    ];

    foreach ($paths as $path) {
        LeadAttachment::factory()->create(['lead_id' => $lead->id, 'path' => $path]);
        Storage::disk('local')->put($path, 'fake-image-bytes');
    }

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('delete', $lead->id);

    foreach ($paths as $path) {
        Storage::disk('local')->assertMissing($path);
    }
});

test('deleting a lead with no attachment does not error', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::WebsiteForm]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('delete', $lead->id)
        ->assertDispatched('toast-show');

    expect(Lead::find($lead->id))->toBeNull();
});

test('deleting a lead with a failed (pathless) attachment does not error', function () {
    Storage::fake('local');
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::WebsiteForm]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    LeadAttachment::factory()->create([
        'lead_id' => $lead->id,
        'path' => null,
        'status' => LeadAttachmentStatus::Failed,
    ]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('delete', $lead->id)
        ->assertDispatched('toast-show');

    expect(Lead::find($lead->id))->toBeNull();
});

test('a client user without delete permission cannot delete a lead', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant);
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::WebsiteForm]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'assigned_to' => $rep->id]);

    Livewire::actingAs($rep)
        ->test(Index::class)
        ->call('delete', $lead->id)
        ->assertDispatched('toast-show');

    expect(Lead::find($lead->id))->not->toBeNull();
});

test('a client admin cannot delete another tenant\'s lead', function () {
    ['tenant' => $tenantA, 'clientAdmin' => $clientAdminA] = tenantWithLeadManagement();
    ['tenant' => $tenantB] = tenantWithLeadManagement();
    $sourceB = LeadSource::factory()->create(['tenant_id' => $tenantB->id, 'type' => LeadSourceType::WebsiteForm]);
    $leadB = Lead::factory()->create(['tenant_id' => $tenantB->id, 'source_id' => $sourceB->id]);

    expect(fn () => Livewire::actingAs($clientAdminA)->test(Index::class)->call('delete', $leadB->id))
        ->toThrow(ModelNotFoundException::class);

    expect(Lead::find($leadB->id))->not->toBeNull();
});

test('deleting a lead from the index requires opening the password modal, then the correct password', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::WebsiteForm]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('confirmDeleteLead', $lead->id)
        ->assertSet('showDeleteLeadModal', true)
        ->assertSet('confirmingDeleteLeadId', $lead->id)
        ->set('deletePassword', 'password')
        ->call('deleteLeadConfirmed')
        ->assertSet('showDeleteLeadModal', false)
        ->assertDispatched('toast-show');

    expect(Lead::find($lead->id))->toBeNull();
});

test('an incorrect password does not delete the lead from the index and shows an error', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::WebsiteForm]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('confirmDeleteLead', $lead->id)
        ->set('deletePassword', 'wrong-password')
        ->call('deleteLeadConfirmed')
        ->assertHasErrors(['deletePassword'])
        ->assertSet('showDeleteLeadModal', true);

    expect(Lead::find($lead->id))->not->toBeNull();
});

test('an empty password is rejected before checking against the stored password', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::WebsiteForm]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('confirmDeleteLead', $lead->id)
        ->set('deletePassword', '')
        ->call('deleteLeadConfirmed')
        ->assertHasErrors(['deletePassword' => 'required']);

    expect(Lead::find($lead->id))->not->toBeNull();
});

test('a user without delete permission cannot even open the delete password modal for a lead', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant);
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::WebsiteForm]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'assigned_to' => $rep->id]);

    Livewire::actingAs($rep)
        ->test(Index::class)
        ->call('confirmDeleteLead', $lead->id)
        ->assertSet('showDeleteLeadModal', false)
        ->assertDispatched('toast-show');

    expect(Lead::find($lead->id))->not->toBeNull();
});

test('deleting a lead from the detail page also requires the correct password', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::WebsiteForm]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('confirmDelete')
        ->assertSet('showDeleteModal', true)
        ->set('deletePassword', 'wrong-password')
        ->call('deleteConfirmed')
        ->assertHasErrors(['deletePassword']);

    expect(Lead::find($lead->id))->not->toBeNull();

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('confirmDelete')
        ->set('deletePassword', 'password')
        ->call('deleteConfirmed');

    expect(Lead::find($lead->id))->toBeNull();
});
