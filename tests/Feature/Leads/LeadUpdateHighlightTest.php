<?php

use App\Livewire\Tenant\Leads\Index;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Eloquent's save()/Builder::update() both auto-touch `updated_at` to now(),
 * clobbering any explicit value passed alongside it — same reason
 * Lead::markUpdateSeen() itself uses a raw DB::table() update instead of
 * save(). These tests need full control over created_at/updated_at/
 * update_seen_at to simulate "updated a while ago", so they go around
 * Eloquent the same way.
 */
function setLeadTimestamps(Lead $lead, array $timestamps): void
{
    DB::table('leads')->where('id', $lead->id)->update($timestamps);
}

test('a lead updated more than 30 seconds ago still shows the "recently updated" highlight if nobody has viewed it', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    setLeadTimestamps($lead, ['created_at' => now()->subMinutes(10), 'updated_at' => now()->subMinutes(5)]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->assertSeeHtml('animate-lead-lift bg-amber-500/10');
});

test('viewing a lead\'s detail page clears the "recently updated" highlight, even after a fresh page load', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    setLeadTimestamps($lead, ['created_at' => now()->subMinutes(10), 'updated_at' => now()->subMinutes(5)]);

    Livewire::actingAs($clientAdmin)->test(Show::class, ['lead' => $lead]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->assertDontSeeHtml('animate-lead-lift bg-amber-500/10');
});

test('a lead with no update since it was last seen shows no highlight', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    setLeadTimestamps($lead, [
        'created_at' => now()->subMinutes(10),
        'updated_at' => now()->subMinutes(5),
        'update_seen_at' => now()->subMinutes(1),
    ]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->assertDontSeeHtml('animate-lead-lift bg-amber-500/10');
});

test('an update after the lead was last seen shows the highlight again', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    setLeadTimestamps($lead, [
        'created_at' => now()->subMinutes(10),
        'update_seen_at' => now()->subMinutes(5),
        'updated_at' => now()->subMinute(),
    ]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->assertSeeHtml('animate-lead-lift bg-amber-500/10');
});
