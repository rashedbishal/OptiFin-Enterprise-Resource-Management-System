<?php

use App\Livewire\Tenant\Leads\Index;
use App\Models\Lead;
use Livewire\Livewire;

test('nextPipelineStatus steps through the pipeline and stops at Won', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $stages = ['new', 'contacted', 'qualified', 'proposal_sent', 'negotiation'];
    $expectedNext = ['contacted', 'qualified', 'proposal_sent', 'negotiation', 'won'];

    foreach ($stages as $index => $slug) {
        $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus($slug)->id]);

        expect($lead->nextPipelineStatus()->id)->toBe(leadStatus($expectedNext[$index])->id);
    }
});

test('Won, Lost and Unqualified have no next pipeline step', function (string $slug) {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus($slug)->id]);

    expect($lead->nextPipelineStatus())->toBeNull();
})->with(['won', 'lost', 'unqualified']);

test('the advance button moves a lead to the next status', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('qualified')->id, 'assigned_to' => $rep->id]);

    Livewire::actingAs($rep)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('moveLead', $lead->id, $lead->nextPipelineStatus()->id);

    expect($lead->fresh()->status_id)->toBe(leadStatus('proposal_sent')->id);
});

test('the advance button is not shown on a Won lead', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('won')->id, 'assigned_to' => $rep->id]);

    Livewire::actingAs($rep)
        ->test(Index::class)->call('completeInitialLoad')
        ->assertDontSee(__('Advance'))
        ->assertDontSeeHtml("wire:click.stop=\"moveLead('{$lead->id}'");
});

test('the advance button is shown on a lead still in the core pipeline', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('negotiation')->id, 'assigned_to' => $rep->id]);

    Livewire::actingAs($rep)
        ->test(Index::class)->call('completeInitialLoad')
        ->assertSee(__('Advance'))
        ->assertSeeHtml("wire:click.stop=\"moveLead('{$lead->id}', '".leadStatus('won')->id."')\"");
});

test('the advance button is hidden without update permission', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id, 'assigned_to' => $rep->id]);

    Livewire::actingAs($rep)
        ->test(Index::class)->call('completeInitialLoad')
        ->assertDontSee(__('Advance'))
        ->assertDontSeeHtml("wire:click.stop=\"moveLead('{$lead->id}'");
});
