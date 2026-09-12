<?php

use App\Livewire\Tenant\Leads\Show;
use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\TenantUser;
use App\Services\LeadMergeService;
use Livewire\Livewire;

test('the notes card shows a single note with its content and author', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    LeadNote::factory()->create([
        'lead_id' => $lead->id,
        'tenant_user_id' => $clientAdmin->id,
        'note' => 'This is a unique note for testing.',
    ]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->assertSee('Notes')
        ->assertSee('This is a unique note for testing.')
        ->assertSee($clientAdmin->name);
});

test('the notes card shows all notes for a lead with multiple notes', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    LeadNote::factory()->create(['lead_id' => $lead->id, 'tenant_user_id' => $clientAdmin->id, 'note' => 'First note content here.']);
    LeadNote::factory()->create(['lead_id' => $lead->id, 'tenant_user_id' => $clientAdmin->id, 'note' => 'Second note content here.']);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->assertSee('First note content here.')
        ->assertSee('Second note content here.');
});

test('the notes card shows an empty state when a lead has no notes', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->assertSee('Notes')
        ->assertSee('No notes yet.');
});

test('notes reparented from a merged lead are visible on the survivor detail page', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $loserNote = LeadNote::factory()->create([
        'lead_id' => $loser->id,
        'tenant_user_id' => $clientAdmin->id,
        'note' => 'Reparented note from the merged lead.',
    ]);

    app(LeadMergeService::class)->merge($survivor, $loser, $clientAdmin, []);

    expect($loserNote->fresh()->lead_id)->toBe($survivor->id);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $survivor])
        ->call('completeInitialLoad')
        ->assertSee('Reparented note from the merged lead.');
});

test('notes from another tenant are not visible on the lead detail page', function () {
    ['tenant' => $tenantA, 'clientAdmin' => $clientAdminA] = tenantWithLeadManagement();
    ['tenant' => $tenantB, 'clientAdmin' => $clientAdminB] = tenantWithLeadManagement();

    $leadA = Lead::factory()->create(['tenant_id' => $tenantA->id]);
    $leadB = Lead::factory()->create(['tenant_id' => $tenantB->id]);
    LeadNote::factory()->create([
        'lead_id' => $leadB->id,
        'tenant_user_id' => $clientAdminB->id,
        'note' => 'Tenant B secret note.',
    ]);

    Livewire::actingAs($clientAdminA)
        ->test(Show::class, ['lead' => $leadA])
        ->call('completeInitialLoad')
        ->assertDontSee('Tenant B secret note.');
});

test('the notes card does not produce an N+1 query for the note authors', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    $anotherUser = TenantUser::factory()->create(['tenant_id' => $tenant->id]);
    LeadNote::factory()->create(['lead_id' => $lead->id, 'tenant_user_id' => $clientAdmin->id, 'note' => 'Alpha note.']);
    LeadNote::factory()->create(['lead_id' => $lead->id, 'tenant_user_id' => $anotherUser->id, 'note' => 'Beta note.']);
    LeadNote::factory()->create(['lead_id' => $lead->id, 'tenant_user_id' => $clientAdmin->id, 'note' => 'Gamma note.']);

    // All three author names are resolved from the eager-loaded `tenantUser`
    // — no per-note lazy-load (verified by asserting the correct names render).
    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->assertSee('Alpha note.')
        ->assertSee('Beta note.')
        ->assertSee('Gamma note.')
        ->assertSee($clientAdmin->name)
        ->assertSee($anotherUser->name);
});
