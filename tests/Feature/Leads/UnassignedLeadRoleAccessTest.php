<?php

use App\Enums\LeadSourceType;
use App\Livewire\Tenant\Leads\Index;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\TenantUser;
use App\Policies\LeadPolicy;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

/*
 * "Receive unassigned leads" — a role granted access to a specific
 * LeadSource (see LeadSource::unassignedRoles()'s own docblock) lets a
 * Client User see and work (view/update) an UNassigned lead from that
 * source, in addition to their own assigned leads — purely additive, never
 * a substitute for the base assigned-to-self visibility, and never a path
 * to self-claiming ownership: LeadPolicy::assign() stays Client-Admin-only
 * throughout, proven explicitly below.
 */

function unassignedAccessSource($tenant, LeadSourceType $type = LeadSourceType::Whatsapp): LeadSource
{
    return LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => $type]);
}

function grantUnassignedAccess(LeadSource $source, TenantUser $user): void
{
    $source->unassignedRoles()->attach($user->role_id);
}

// ── Kanban board visibility (FiltersLeadsQuery::applyAssignmentScope) ──────

test('a Client User without any grant still only ever sees their own assigned leads — unchanged baseline', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $source = unassignedAccessSource($tenant);
    $own = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'assigned_to' => $rep->id]);
    $unassigned = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'assigned_to' => null]);

    $board = Livewire::actingAs($rep)->test(Index::class)->call('completeInitialLoad')->get('boardLeads');
    $visibleIds = $board->flatten(1)->pluck('id')->all();

    expect($visibleIds)->toContain($own->id);
    expect($visibleIds)->not->toContain($unassigned->id);
});

test('a Client User with a grant sees their own assigned leads PLUS unassigned leads from the granted source', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $source = unassignedAccessSource($tenant);
    grantUnassignedAccess($source, $rep);
    $own = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'assigned_to' => $rep->id]);
    $unassigned = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'assigned_to' => null]);

    $board = Livewire::actingAs($rep)->test(Index::class)->call('completeInitialLoad')->get('boardLeads');
    $visibleIds = $board->flatten(1)->pluck('id')->all();

    expect($visibleIds)->toContain($own->id);
    expect($visibleIds)->toContain($unassigned->id);
});

test('the grant never surfaces an unassigned lead from a DIFFERENT, non-granted source', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $grantedSource = unassignedAccessSource($tenant);
    $otherSource = unassignedAccessSource($tenant);
    grantUnassignedAccess($grantedSource, $rep);
    $unassignedElsewhere = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $otherSource->id, 'assigned_to' => null]);

    $board = Livewire::actingAs($rep)->test(Index::class)->call('completeInitialLoad')->get('boardLeads');
    $visibleIds = $board->flatten(1)->pluck('id')->all();

    expect($visibleIds)->not->toContain($unassignedElsewhere->id);
});

test('the grant never surfaces a lead from the granted source that is already assigned to someone else', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $otherRep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $source = unassignedAccessSource($tenant);
    grantUnassignedAccess($source, $rep);
    $othersLead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'assigned_to' => $otherRep->id]);

    $board = Livewire::actingAs($rep)->test(Index::class)->call('completeInitialLoad')->get('boardLeads');
    $visibleIds = $board->flatten(1)->pluck('id')->all();

    expect($visibleIds)->not->toContain($othersLead->id);
});

test('a Client Admin is unaffected either way — they already see everything', function () {
    ['tenant' => $tenant, 'clientAdmin' => $admin] = tenantWithLeadManagement();
    $source = unassignedAccessSource($tenant);
    $unassigned = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'assigned_to' => null]);

    $board = Livewire::actingAs($admin)->test(Index::class)->call('completeInitialLoad')->get('boardLeads');
    $visibleIds = $board->flatten(1)->pluck('id')->all();

    expect($visibleIds)->toContain($unassigned->id);
});

// ── Single-lead policy (LeadPolicy::view()/update()/assign()) ──────────────

test('a qualifying Client User can view and update an unassigned lead from the granted source', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $source = unassignedAccessSource($tenant);
    grantUnassignedAccess($source, $rep);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'assigned_to' => null]);

    expect(Gate::forUser($rep)->allows('view', $lead))->toBeTrue();
    expect(Gate::forUser($rep)->allows('update', $lead))->toBeTrue();
});

test('the same user is refused on an unassigned lead from a non-granted source', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $grantedSource = unassignedAccessSource($tenant);
    $otherSource = unassignedAccessSource($tenant);
    grantUnassignedAccess($grantedSource, $rep);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $otherSource->id, 'assigned_to' => null]);

    expect(Gate::forUser($rep)->allows('view', $lead))->toBeFalse();
    expect(Gate::forUser($rep)->allows('update', $lead))->toBeFalse();
});

test('the same user is refused once the lead is assigned to someone else, even on the granted source', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $otherRep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $source = unassignedAccessSource($tenant);
    grantUnassignedAccess($source, $rep);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'assigned_to' => $otherRep->id]);

    expect(Gate::forUser($rep)->allows('view', $lead))->toBeFalse();
    expect(Gate::forUser($rep)->allows('update', $lead))->toBeFalse();
});

test('a qualifying Client User still cannot assign (claim) the lead to themselves — assign() stays Client-Admin-only', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $source = unassignedAccessSource($tenant);
    grantUnassignedAccess($source, $rep);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'assigned_to' => null]);

    expect(Gate::forUser($rep)->allows('assign', $lead))->toBeFalse();

    // Belt and suspenders: the lead genuinely never gets claimed by merely
    // being viewed/worked — nothing in this feature ever writes assigned_to.
    expect($lead->fresh()->assigned_to)->toBeNull();
});

test('a Client Admin can still assign the lead normally — ownership assignment is untouched by this feature', function () {
    ['tenant' => $tenant, 'clientAdmin' => $admin] = tenantWithLeadManagement();
    $source = unassignedAccessSource($tenant);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'assigned_to' => null]);

    expect(Gate::forUser($admin)->allows('assign', $lead))->toBeTrue();
});

test('a lead belonging to another tenant is never reachable via the grant', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $source = unassignedAccessSource($tenant);
    grantUnassignedAccess($source, $rep);

    ['tenant' => $otherTenant] = tenantWithLeadManagement();
    $foreignSource = unassignedAccessSource($otherTenant);
    $foreignLead = Lead::factory()->create(['tenant_id' => $otherTenant->id, 'source_id' => $foreignSource->id, 'assigned_to' => null]);

    expect(Gate::forUser($rep)->allows('view', $foreignLead))->toBeFalse();
});

test('hasUnassignedAccess is never consulted when the user already lacks the base view/update permission', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $noPermission = clientUserWithLeadPermission($tenant, []);
    $source = unassignedAccessSource($tenant);
    grantUnassignedAccess($source, $noPermission);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'assigned_to' => null]);

    expect(Gate::forUser($noPermission)->allows('view', $lead))->toBeFalse();
});

test('a role deliberately ungranted on any source leaves LeadPolicy behaving exactly as before this feature', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $source = unassignedAccessSource($tenant);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'assigned_to' => null]);

    expect(app(LeadPolicy::class)->view($rep, $lead))->toBeFalse();
    expect(app(LeadPolicy::class)->update($rep, $lead))->toBeFalse();
});
