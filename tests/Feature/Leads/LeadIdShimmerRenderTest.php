<?php

use App\Livewire\Tenant\Leads\Index;
use App\Models\Lead;
use App\Models\TenantUser;
use Livewire\Livewire;

test('a lead inside its tenant\'s meeting alert window shows the shimmer on its Lead ID in the Client Admin table', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['meeting_alert_enabled' => true, 'meeting_alert_days_before' => 3]);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => today()->addDay()]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->assertSeeHtml('lead-id-shimmer-text');
});

test('a lead outside the alert window shows no shimmer on its Lead ID', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['meeting_alert_enabled' => true, 'meeting_alert_days_before' => 3]);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => today()->addDays(10)]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->assertDontSeeHtml('lead-id-shimmer-text');
});

test('hovering the shimmering Lead ID shows the assigned person\'s name and the meeting details', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['meeting_alert_enabled' => true, 'meeting_alert_days_before' => 3]);
    $rep = TenantUser::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Sajid Hasan']);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id, 'meeting_date' => today()]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->assertSee('Assigned to Sajid Hasan')
        ->assertSee('Meeting scheduled on');
});

test('an unassigned shimmering lead shows "Unassigned" rather than a blank name', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['followup_alert_enabled' => true, 'followup_alert_days_before' => 3]);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => null, 'next_follow_up_at' => today()]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->assertSee('Assigned to Unassigned')
        ->assertSee('Follow-up scheduled on');
});

test('the mobile card stack also shimmers a shimmering Lead ID, not just the desktop table', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['meeting_alert_enabled' => true, 'meeting_alert_days_before' => 3]);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => today()->addDay()]);

    $html = Livewire::actingAs($clientAdmin)->test(Index::class)->html();

    expect(substr_count($html, 'lead-id-shimmer-text'))->toBe(2);
});

test('the mobile card stack\'s Lead ID is not muted when there is no active alert', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    Lead::factory()->create(['tenant_id' => $tenant->id]);

    $html = Livewire::actingAs($clientAdmin)->test(Index::class)->html();

    expect($html)->not->toContain('font-mono text-[11px] text-zinc-400');
});
