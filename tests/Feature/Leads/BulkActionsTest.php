<?php

use App\Enums\LeadDistributionMode;
use App\Enums\LeadPriority;
use App\Enums\LeadSourceType;
use App\Events\LeadAssigned;
use App\Events\LeadUpdated;
use App\Livewire\Tenant\Leads\Index;
use App\Models\Lead;
use App\Models\LeadAssignment;
use App\Models\LeadDetailUpdateLog;
use App\Models\LeadSource;
use App\Models\LeadStatusLog;
use App\Models\LeadTag;
use App\Models\Tenant;
use App\Notifications\LeadAssignedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * @return array<int, Lead>
 */
function createBulkTestLeads(Tenant $tenant, int $count = 3, array $attributes = []): array
{
    return Lead::factory()->count($count)->create(array_merge(['tenant_id' => $tenant->id], $attributes))->all();
}

test('bulk status change moves every eligible lead and writes a status log per lead', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $leads = createBulkTestLeads($tenant, 3, ['status_id' => leadStatus('new')->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('completeInitialLoad')
        ->set('selectedLeadIds', collect($leads)->pluck('id')->all())
        ->call('openBulkStatusModal')
        ->set('bulkStatusId', leadStatus('contacted')->id)
        ->call('applyBulkStatusChange');

    foreach ($leads as $lead) {
        expect($lead->fresh()->status_id)->toBe(leadStatus('contacted')->id);
    }

    expect(LeadStatusLog::whereIn('lead_id', collect($leads)->pluck('id'))->count())->toBe(3);
});

test('bulk status change treats a lead already at the target status as a success, not a skip', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $alreadyThere = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('contacted')->id]);
    $needsMoving = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('completeInitialLoad')
        ->set('selectedLeadIds', [$alreadyThere->id, $needsMoving->id])
        ->call('openBulkStatusModal')
        ->set('bulkStatusId', leadStatus('contacted')->id)
        ->call('applyBulkStatusChange');

    // No-op lead: no new status log row, but still counted as succeeded, not skipped.
    expect(LeadStatusLog::where('lead_id', $alreadyThere->id)->count())->toBe(0);
    expect(LeadStatusLog::where('lead_id', $needsMoving->id)->count())->toBe(1);
});

test('bulk priority change writes one LeadDetailUpdateLog per changed lead and recomputes score', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $leads = createBulkTestLeads($tenant, 2, ['priority' => LeadPriority::Low]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('completeInitialLoad')
        ->set('selectedLeadIds', collect($leads)->pluck('id')->all())
        ->call('openBulkPriorityModal')
        ->set('bulkPriority', LeadPriority::High->value)
        ->call('applyBulkPriorityChange');

    foreach ($leads as $lead) {
        expect($lead->fresh()->priority)->toBe(LeadPriority::High);
        $log = LeadDetailUpdateLog::where('lead_id', $lead->id)->first();
        expect($log)->not->toBeNull();
        expect($log->changed_fields)->toBe(['priority']);
    }
});

test('bulk assign uses LeadAssignmentService directly, never autoAssign/round-robin distribution', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['lead_distribution_mode' => LeadDistributionMode::Automatic]);
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $leads = createBulkTestLeads($tenant, 2);

    Event::fake([LeadAssigned::class]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('completeInitialLoad')
        ->set('selectedLeadIds', collect($leads)->pluck('id')->all())
        ->call('openBulkAssignModal')
        ->set('bulkAssigneeId', $rep->id)
        ->call('applyBulkAssign');

    foreach ($leads as $lead) {
        expect($lead->fresh()->assigned_to)->toBe($rep->id);
    }

    expect(LeadAssignment::whereIn('lead_id', collect($leads)->pluck('id'))->count())->toBe(2);
    Event::assertDispatchedTimes(LeadAssigned::class, 2);
});

test('bulk assign is a no-op for a lead already assigned to the chosen assignee — no duplicate row, no re-notification', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id]);
    LeadAssignment::factory()->create(['lead_id' => $lead->id, 'assigned_to' => $rep->id, 'assigned_by' => $clientAdmin->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('completeInitialLoad')
        ->set('selectedLeadIds', [$lead->id])
        ->call('openBulkAssignModal')
        ->set('bulkAssigneeId', $rep->id)
        ->call('applyBulkAssign');

    expect(LeadAssignment::where('lead_id', $lead->id)->count())->toBe(1);
    expect($rep->notifications()->where('type', LeadAssignedNotification::class)->count())->toBe(0);
});

test('a non-admin cannot open the bulk assign modal', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $otherRep = clientUserWithLeadPermission($tenant, ['view']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id]);

    Livewire::actingAs($rep)
        ->test(Index::class)
        ->call('completeInitialLoad')
        ->set('selectedLeadIds', [$lead->id])
        ->call('openBulkAssignModal');

    // This only proves the UI entry point stays closed for a non-admin —
    // it does NOT prove applyBulkAssign() itself denies a non-admin who
    // calls it directly (a Livewire action is callable independent of
    // whatever earlier UI state opened it). See the dedicated write-time
    // denial test below (QA-1) for that boundary.
    expect($lead->fresh()->assigned_to)->toBe($rep->id);
});

// ── QA-1 — assignment-denial authorization coverage ─────────────────────────
//
// applyBulkAssign()/bulkUnassign() each re-check Gate::denies('assign', $lead)
// per-Lead, at write time, independent of openBulkAssignModal()'s own
// earlier isClientAdmin() guard above. The tests below call the bulk action
// directly — never opening the modal first — to prove that write-time
// boundary is what actually stops a denied actor. Every Lead in the
// selection is denied uniformly here (assign()'s isClientAdmin()/
// hasModulePermission() conditions are actor-level, not per-Lead, so a
// mixed allowed/denied selection isn't a scenario this policy can actually
// produce — see the QA-1 Architecture Brief for the full reasoning).

test('a non-admin is denied applyBulkAssign() at the write-time Gate check, not just the closed modal', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $target = clientUserWithLeadPermission($tenant);
    $leads = createBulkTestLeads($tenant, 2, ['assigned_to' => $rep->id]);

    Livewire::actingAs($rep)
        ->test(Index::class)
        ->call('completeInitialLoad')
        ->set('selectedLeadIds', collect($leads)->pluck('id')->all())
        ->set('bulkAssigneeId', $target->id)
        ->call('applyBulkAssign');

    foreach ($leads as $lead) {
        expect($lead->fresh()->assigned_to)->toBe($rep->id);
    }
    expect(LeadAssignment::whereIn('lead_id', collect($leads)->pluck('id'))->count())->toBe(0);
    expect($target->notifications()->where('type', LeadAssignedNotification::class)->count())->toBe(0);
});

test('a non-admin is denied bulkUnassign() at the write-time Gate check, not just the closed modal', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $leads = createBulkTestLeads($tenant, 2, ['assigned_to' => $rep->id, 'assigned_at' => now()]);

    Livewire::actingAs($rep)
        ->test(Index::class)
        ->call('completeInitialLoad')
        ->set('selectedLeadIds', collect($leads)->pluck('id')->all())
        ->call('bulkUnassign');

    foreach ($leads as $lead) {
        expect($lead->fresh()->assigned_to)->toBe($rep->id);
    }
    expect(LeadDetailUpdateLog::whereIn('lead_id', collect($leads)->pluck('id'))->where('action', 'unassigned')->count())->toBe(0);
});

test('bulk unassign replicates the manual LeadDetailUpdateLog + LeadUpdated event Show::assign() writes for a single unassign', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id, 'assigned_at' => now()]);

    Event::fake([LeadUpdated::class]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('completeInitialLoad')
        ->set('selectedLeadIds', [$lead->id])
        ->call('bulkUnassign');

    expect($lead->fresh()->assigned_to)->toBeNull();
    expect(LeadDetailUpdateLog::where('lead_id', $lead->id)->where('action', 'unassigned')->exists())->toBeTrue();
    Event::assertDispatched(LeadUpdated::class);
});

test('bulk tag attach is idempotent and only logs when the tag actually gets added', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tag = LeadTag::factory()->create(['tenant_id' => $tenant->id]);
    $alreadyTagged = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $alreadyTagged->tags()->attach($tag->id, ['tagged_by' => $clientAdmin->id]);
    $notYetTagged = Lead::factory()->create(['tenant_id' => $tenant->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('completeInitialLoad')
        ->set('selectedLeadIds', [$alreadyTagged->id, $notYetTagged->id])
        ->call('openBulkTagModal', 'attach')
        ->set('bulkTagId', $tag->id)
        ->call('applyBulkTagAction');

    expect($alreadyTagged->fresh()->tags()->where('lead_tags.id', $tag->id)->count())->toBe(1);
    expect($notYetTagged->fresh()->tags()->where('lead_tags.id', $tag->id)->count())->toBe(1);
    // Only the lead that actually gained the tag gets a log entry.
    expect(LeadDetailUpdateLog::where('lead_id', $alreadyTagged->id)->where('action', 'tag_added')->exists())->toBeFalse();
    expect(LeadDetailUpdateLog::where('lead_id', $notYetTagged->id)->where('action', 'tag_added')->exists())->toBeTrue();
});

test('bulk tag remove detaches the tag from every eligible lead that has it', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tag = LeadTag::factory()->create(['tenant_id' => $tenant->id]);
    $leads = createBulkTestLeads($tenant, 2);
    foreach ($leads as $lead) {
        $lead->tags()->attach($tag->id, ['tagged_by' => $clientAdmin->id]);
    }

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('completeInitialLoad')
        ->set('selectedLeadIds', collect($leads)->pluck('id')->all())
        ->call('openBulkTagModal', 'remove')
        ->set('bulkTagId', $tag->id)
        ->call('applyBulkTagAction');

    foreach ($leads as $lead) {
        expect($lead->fresh()->tags()->where('lead_tags.id', $tag->id)->count())->toBe(0);
    }
});

test('bulk delete only removes leads whose source type is email, website_form, or manual', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $deletableSource = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::WebsiteForm]);
    $undeletableSource = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Whatsapp]);
    $deletableLead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $deletableSource->id]);
    $undeletableLead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $undeletableSource->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('completeInitialLoad')
        ->set('selectedLeadIds', [$deletableLead->id, $undeletableLead->id])
        ->call('confirmBulkDelete')
        ->set('deletePassword', 'password')
        ->call('deleteLeadConfirmed');

    expect(Lead::find($deletableLead->id))->toBeNull();
    expect(Lead::find($undeletableLead->id))->not->toBeNull();
});

test('bulk delete requires the acting user\'s own correct password', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Manual]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('completeInitialLoad')
        ->set('selectedLeadIds', [$lead->id])
        ->call('confirmBulkDelete')
        ->set('deletePassword', 'wrong-password')
        ->call('deleteLeadConfirmed')
        ->assertHasErrors('deletePassword');

    expect(Lead::find($lead->id))->not->toBeNull();
});

test('bulk delete is Client-Admin-only', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update', 'delete']);
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Manual]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'assigned_to' => $rep->id]);

    Livewire::actingAs($rep)
        ->test(Index::class)
        ->call('completeInitialLoad')
        ->set('selectedLeadIds', [$lead->id])
        ->call('confirmBulkDelete');

    expect(Lead::find($lead->id))->not->toBeNull();
});

test('bulk delete never touches a merged-away lead, even if its stale id is still selected', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Manual]);
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $merged = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    // merged_into_lead_id is deliberately excluded from Lead's #[Fillable]
    // list (see CLAUDE.md) — set it directly, same technique LeadMergeTest
    // itself uses for the same reason.
    DB::table('leads')->where('id', $merged->id)->update(['merged_into_lead_id' => $survivor->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('completeInitialLoad')
        ->set('selectedLeadIds', [$merged->id])
        ->call('confirmBulkDelete')
        ->set('deletePassword', 'password')
        ->call('deleteLeadConfirmed');

    // Still exists — eligibleSelectedLeads() excludes merged-away leads entirely.
    expect(Lead::find($merged->id))->not->toBeNull();
});

test('re-derivation ignores a lead id from another tenant even if injected into selectedLeadIds', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    ['tenant' => $otherTenant] = tenantWithLeadManagement();
    $foreignLead = Lead::factory()->create(['tenant_id' => $otherTenant->id, 'status_id' => leadStatus('new')->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('completeInitialLoad')
        ->set('selectedLeadIds', [$foreignLead->id])
        ->call('openBulkStatusModal')
        ->set('bulkStatusId', leadStatus('contacted')->id)
        ->call('applyBulkStatusChange');

    expect($foreignLead->fresh()->status_id)->toBe(leadStatus('new')->id);
});

test('a non-admin\'s bulk status change is scoped to their own assigned leads — an unassigned lead in the same tenant is skipped', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $ownLead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id, 'status_id' => leadStatus('new')->id]);
    $othersLead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => null, 'status_id' => leadStatus('new')->id]);

    Livewire::actingAs($rep)
        ->test(Index::class)
        ->call('completeInitialLoad')
        ->set('selectedLeadIds', [$ownLead->id, $othersLead->id])
        ->call('openBulkStatusModal')
        ->set('bulkStatusId', leadStatus('contacted')->id)
        ->call('applyBulkStatusChange');

    expect($ownLead->fresh()->status_id)->toBe(leadStatus('contacted')->id);
    expect($othersLead->fresh()->status_id)->toBe(leadStatus('new')->id);
});

test('selection is capped at 100 and clears/toasts a warning past the cap', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $ids = collect(range(1, 100))->map(fn () => (string) Str::ulid())->all();

    $component = Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('completeInitialLoad')
        ->set('selectedLeadIds', $ids)
        ->call('toggleLeadSelection', (string) Str::ulid());

    expect($component->get('selectedLeadIds'))->toHaveCount(100);
});

test('changing a filter clears the current selection', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('completeInitialLoad')
        ->set('selectedLeadIds', [$lead->id])
        ->set('status', leadStatus('contacted')->id)
        ->assertSet('selectedLeadIds', []);
});

test('changing per-page size clears the current selection', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('completeInitialLoad')
        ->set('selectedLeadIds', [$lead->id])
        ->call('setPerPage', 10)
        ->assertSet('selectedLeadIds', []);
});

test('toggleSelectAllOnPage selects every row on the current page, then clears them all on a second call', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $leads = createBulkTestLeads($tenant, 3);

    $component = Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('completeInitialLoad')
        ->call('toggleSelectAllOnPage');

    expect($component->get('selectedLeadIds'))->toHaveCount(3);

    $component->call('toggleSelectAllOnPage');
    expect($component->get('selectedLeadIds'))->toHaveCount(0);
});

test('bulk delete clears the selection and closes the lead panel if the deleted lead was open', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Manual]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('completeInitialLoad')
        ->call('viewLead', $lead->id)
        ->set('selectedLeadIds', [$lead->id])
        ->call('confirmBulkDelete')
        ->set('deletePassword', 'password')
        ->call('deleteLeadConfirmed')
        ->assertSet('showLeadPanel', false)
        ->assertSet('selectedLeadIds', []);
});
