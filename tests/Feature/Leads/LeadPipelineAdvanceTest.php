<?php

use App\Enums\LeadStatus;
use App\Livewire\Tenant\Leads\Index;
use App\Models\Lead;
use Livewire\Livewire;

test('nextInPipeline steps through the core pipeline and stops at Won', function () {
    expect(LeadStatus::New->nextInPipeline())->toBe(LeadStatus::Contacted);
    expect(LeadStatus::Contacted->nextInPipeline())->toBe(LeadStatus::Qualified);
    expect(LeadStatus::Qualified->nextInPipeline())->toBe(LeadStatus::ProposalSent);
    expect(LeadStatus::ProposalSent->nextInPipeline())->toBe(LeadStatus::Negotiation);
    expect(LeadStatus::Negotiation->nextInPipeline())->toBe(LeadStatus::Won);
});

test('Won, Lost and Unqualified have no next pipeline step', function (LeadStatus $status) {
    expect($status->nextInPipeline())->toBeNull();
})->with([LeadStatus::Won, LeadStatus::Lost, LeadStatus::Unqualified]);

test('the advance button moves a lead to the next status', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status' => LeadStatus::Qualified, 'assigned_to' => $rep->id]);

    Livewire::actingAs($rep)
        ->test(Index::class)
        ->call('moveLead', $lead->id, $lead->status->nextInPipeline()->value);

    expect($lead->fresh()->status)->toBe(LeadStatus::ProposalSent);
});

test('the advance button is not shown on a Won lead', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status' => LeadStatus::Won, 'assigned_to' => $rep->id]);

    Livewire::actingAs($rep)
        ->test(Index::class)
        ->assertDontSee(__('Advance'))
        ->assertDontSeeHtml("wire:click.stop=\"moveLead('{$lead->id}'");
});

test('the advance button is shown on a lead still in the core pipeline', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status' => LeadStatus::Negotiation, 'assigned_to' => $rep->id]);

    Livewire::actingAs($rep)
        ->test(Index::class)
        ->assertSee(__('Advance'))
        ->assertSeeHtml("wire:click.stop=\"moveLead('{$lead->id}', 'won')\"");
});

test('the advance button is hidden without update permission', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status' => LeadStatus::New, 'assigned_to' => $rep->id]);

    Livewire::actingAs($rep)
        ->test(Index::class)
        ->assertDontSee(__('Advance'))
        ->assertDontSeeHtml("wire:click.stop=\"moveLead('{$lead->id}'");
});
