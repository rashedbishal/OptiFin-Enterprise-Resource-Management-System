<?php

use App\Events\LeadStatusChanged;
use App\Events\LeadUpdated;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Lead;
use App\Models\TenantUser;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

test('the merged Status & Assignment card updates both status and assignment from one submit', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $rep = TenantUser::factory()->create(['tenant_id' => $tenant->id]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->set('status', leadStatus('contacted')->id)
        ->set('assigneeId', $rep->id)
        ->call('updateStatusAndAssignment');

    $lead->refresh();
    expect($lead->status_id)->toBe(leadStatus('contacted')->id);
    expect($lead->assigned_to)->toBe($rep->id);
});

test('the merged submit still broadcasts LeadUpdated and LeadStatusChanged over Reverb, same as the separate forms did', function () {
    Event::fake([LeadUpdated::class, LeadStatusChanged::class]);
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $rep = TenantUser::factory()->create(['tenant_id' => $tenant->id]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->set('status', leadStatus('contacted')->id)
        ->set('assigneeId', $rep->id)
        ->call('updateStatusAndAssignment');

    Event::assertDispatched(LeadStatusChanged::class, fn (LeadStatusChanged $event): bool => $event->lead->is($lead));
    // Once for the status change, once for the assignment — each half
    // still broadcasts its own update exactly as it did as a separate form.
    Event::assertDispatched(LeadUpdated::class, fn (LeadUpdated $event): bool => $event->lead->is($lead), 2);
});

test('a viewer who can update status but not assign only has the status half applied', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $otherRep = TenantUser::factory()->create(['tenant_id' => $tenant->id]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id, 'assigned_to' => $rep->id]);

    Livewire::actingAs($rep)
        ->test(Show::class, ['lead' => $lead])
        ->set('status', leadStatus('contacted')->id)
        ->set('assigneeId', $otherRep->id)
        ->call('updateStatusAndAssignment');

    $lead->refresh();
    expect($lead->status_id)->toBe(leadStatus('contacted')->id);
    expect($lead->assigned_to)->toBe($rep->id);
});

test('a viewer without update permission has neither half applied', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view']);
    $otherRep = TenantUser::factory()->create(['tenant_id' => $tenant->id]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id, 'assigned_to' => $rep->id]);

    Livewire::actingAs($rep)
        ->test(Show::class, ['lead' => $lead])
        ->set('status', leadStatus('contacted')->id)
        ->set('assigneeId', $otherRep->id)
        ->call('updateStatusAndAssignment');

    $lead->refresh();
    expect($lead->status_id)->toBe(leadStatus('new')->id);
    expect($lead->assigned_to)->toBe($rep->id);
});
