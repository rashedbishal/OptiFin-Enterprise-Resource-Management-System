<?php

use App\Actions\Tenant\RecordLeadConsent;
use App\Enums\ConsentSource;
use App\Enums\ConsentStatus;
use App\Enums\ConsentType;
use App\Enums\LeadDuplicateLinkStatus;
use App\Enums\LeadSourceType;
use App\Livewire\Tenant\Leads\Index;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Lead;
use App\Models\LeadDeletionAudit;
use App\Models\LeadDuplicateLink;
use App\Models\LeadNote;
use App\Models\LeadSource;
use App\Models\Task;
use App\Services\LeadDeletionEligibilityService;
use App\Services\LeadMergeService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * PROD-1 — a merged cluster is permanently protected from deletion, on every
 * deletion path, with no escape hatch:
 *   - a merged LOSER (merged_into_lead_id set) can never be deleted, and
 *   - a SURVIVOR that still has a merged loser can never be deleted either.
 * Ordinary leads (including ones that only ever had a Pending/Dismissed
 * duplicate link) delete exactly as before.
 *
 * Every refusal is asserted the same strong way: exactly the expected danger
 * toast, and every row of both leads (and their history, consent, links)
 * byte-identical afterwards, with no LeadDeletionAudit written.
 */

const MERGED_DELETION_LOSER_MESSAGE = 'This lead has been merged and can no longer be changed.';
const MERGED_DELETION_SURVIVOR_MESSAGE = 'This lead has merged leads and can no longer be deleted.';

/**
 * A survivor and a loser (both on a deletable Manual source), merged the same
 * way a Client Admin's merge does, with history on both sides so a wrongful
 * cascade would be visible.
 *
 * @return array<string, mixed>
 */
function mergedDeletionScenario(LeadSourceType $sourceType = LeadSourceType::Manual): array
{
    ['tenant' => $tenant, 'clientAdmin' => $admin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => $sourceType]);
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'name' => 'Survivor Lead']);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'name' => 'Loser Lead']);

    LeadNote::create(['lead_id' => $survivor->id, 'tenant_user_id' => $admin->id, 'note' => 'survivor note']);
    LeadNote::create(['lead_id' => $loser->id, 'tenant_user_id' => $admin->id, 'note' => 'loser note']);
    Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $loser->id, 'title' => 'loser task']);
    app(RecordLeadConsent::class)->handle(lead: $loser, type: ConsentType::Privacy, status: ConsentStatus::Granted, source: ConsentSource::Manual, changedBy: $admin->id);

    app(LeadMergeService::class)->merge($survivor->fresh(), $loser->fresh(), $admin, []);

    return compact('tenant', 'admin', 'source', 'survivor', 'loser');
}

/**
 * A merge chain A → B → C: A is merged into B, then B (itself now a survivor)
 * is merged into C. The current merge model allows this.
 *
 * @return array<string, mixed>
 */
function mergedDeletionChain(): array
{
    ['tenant' => $tenant, 'clientAdmin' => $admin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Manual]);
    [$a, $b, $c] = collect(['Chain A', 'Chain B', 'Chain C'])
        ->map(fn (string $name): Lead => Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'name' => $name]))
        ->all();

    app(LeadMergeService::class)->merge($b->fresh(), $a->fresh(), $admin, []);
    app(LeadMergeService::class)->merge($c->fresh(), $b->fresh(), $admin, []);

    return compact('tenant', 'admin', 'a', 'b', 'c');
}

/**
 * Every row of every table a wrongful deletion (or cascade) would touch, for
 * the given leads, plus the global counters a stray side effect would move.
 *
 * @param  array<int, string>  $leadIds
 * @return array<string, mixed>
 */
function mergedDeletionFingerprint(array $leadIds): array
{
    $rows = fn ($query): array => $query->get()->map(fn ($row): array => (array) $row)->all();

    $fingerprint = ['leads' => $rows(DB::table('leads')->whereIn('id', $leadIds)->orderBy('id'))];

    foreach (['lead_notes', 'lead_lead_tag', 'tasks', 'deals', 'lead_consents', 'lead_consent_events', 'lead_status_logs', 'lead_assignments', 'lead_detail_update_logs'] as $table) {
        $fingerprint[$table] = $rows(DB::table($table)->whereIn('lead_id', $leadIds));
    }

    $fingerprint['lead_duplicate_links'] = $rows(DB::table('lead_duplicate_links'));
    $fingerprint['lead_deletion_audits'] = DB::table('lead_deletion_audits')->count();

    return $fingerprint;
}

/**
 * @return array<int, array{0: ?string, 1: ?string}> [variant, text] of every toast the last request dispatched
 */
function mergedDeletionToasts($component): array
{
    return collect($component->effects['dispatches'] ?? [])
        ->where('name', 'toast-show')
        ->map(fn (array $dispatch): array => [$dispatch['params']['dataset']['variant'] ?? null, $dispatch['params']['slots']['text'] ?? null])
        ->values()
        ->all();
}

function mergedDeletionIndex(array $scenario)
{
    return Livewire::actingAs($scenario['admin'])->test(Index::class)->call('completeInitialLoad');
}

// ── Merged loser ────────────────────────────────────────────────────────────

test('Index::delete refuses a merged loser and changes nothing', function () {
    $x = mergedDeletionScenario();
    $before = mergedDeletionFingerprint([$x['loser']->id, $x['survivor']->id]);

    $component = mergedDeletionIndex($x)->call('delete', $x['loser']->id);

    expect(mergedDeletionToasts($component))->toBe([['danger', MERGED_DELETION_LOSER_MESSAGE]]);
    expect(mergedDeletionFingerprint([$x['loser']->id, $x['survivor']->id]))->toBe($before);
    expect(LeadDeletionAudit::count())->toBe(0);
    expect(LeadDuplicateLink::count())->toBe(1);
});

test('the password-confirmation path refuses a merged loser too', function () {
    $x = mergedDeletionScenario();
    $before = mergedDeletionFingerprint([$x['loser']->id, $x['survivor']->id]);

    $component = mergedDeletionIndex($x)
        ->set('confirmingDeleteLeadId', $x['loser']->id)
        ->set('deletePassword', 'password')
        ->call('deleteLeadConfirmed');

    expect(mergedDeletionToasts($component))->toBe([['danger', MERGED_DELETION_LOSER_MESSAGE]]);
    expect($component->get('showDeleteLeadModal'))->toBeFalse();
    expect(mergedDeletionFingerprint([$x['loser']->id, $x['survivor']->id]))->toBe($before);
});

test('Index::confirmDeleteLead refuses early for a merged loser instead of opening the password modal', function () {
    $x = mergedDeletionScenario();

    $component = mergedDeletionIndex($x)->call('confirmDeleteLead', $x['loser']->id);

    expect(mergedDeletionToasts($component))->toBe([['danger', MERGED_DELETION_LOSER_MESSAGE]]);
    expect($component->get('showDeleteLeadModal'))->toBeFalse();
    expect($component->get('confirmingDeleteLeadId'))->toBeNull();
});

test('a merged loser forced into a bulk selection is excluded, not deleted, and gets no survivor toast', function () {
    $x = mergedDeletionScenario();
    $before = mergedDeletionFingerprint([$x['loser']->id, $x['survivor']->id]);

    $component = mergedDeletionIndex($x)
        ->set('selectedLeadIds', [$x['loser']->id])
        ->call('confirmBulkDelete')
        ->set('deletePassword', 'password')
        ->call('deleteLeadConfirmed');

    expect(mergedDeletionToasts($component))->toBe([['warning', '0 of 1 Leads deleted. 1 skipped.']]);
    expect(mergedDeletionFingerprint([$x['loser']->id, $x['survivor']->id]))->toBe($before);
});

// ── Merged survivor ─────────────────────────────────────────────────────────

test('Index::delete refuses a merged survivor and changes nothing — the loser stays merged, hidden and intact', function () {
    $x = mergedDeletionScenario();
    $before = mergedDeletionFingerprint([$x['loser']->id, $x['survivor']->id]);

    $component = mergedDeletionIndex($x)->call('delete', $x['survivor']->id);

    expect(mergedDeletionToasts($component))->toBe([['danger', MERGED_DELETION_SURVIVOR_MESSAGE]]);
    expect(mergedDeletionFingerprint([$x['loser']->id, $x['survivor']->id]))->toBe($before);
    expect(Lead::whereKey($x['loser']->id)->value('merged_into_lead_id'))->toBe($x['survivor']->id);
    expect(LeadDeletionAudit::count())->toBe(0);
    expect(str_contains($component->html(), 'Loser Lead'))->toBeFalse();
});

test('the password-confirmation path refuses a merged survivor too', function () {
    $x = mergedDeletionScenario();
    $before = mergedDeletionFingerprint([$x['loser']->id, $x['survivor']->id]);

    $component = mergedDeletionIndex($x)
        ->set('confirmingDeleteLeadId', $x['survivor']->id)
        ->set('deletePassword', 'password')
        ->call('deleteLeadConfirmed');

    expect(mergedDeletionToasts($component))->toBe([['danger', MERGED_DELETION_SURVIVOR_MESSAGE]]);
    expect(mergedDeletionFingerprint([$x['loser']->id, $x['survivor']->id]))->toBe($before);
});

test('Index::confirmDeleteLead refuses early for a merged survivor instead of opening the password modal', function () {
    $x = mergedDeletionScenario();

    $component = mergedDeletionIndex($x)->call('confirmDeleteLead', $x['survivor']->id);

    expect(mergedDeletionToasts($component))->toBe([['danger', MERGED_DELETION_SURVIVOR_MESSAGE]]);
    expect($component->get('showDeleteLeadModal'))->toBeFalse();
});

test('Show refuses a merged survivor: no delete, no redirect, no lead-panel-deleted, nothing changed', function (bool $panel, Closure $act) {
    $x = mergedDeletionScenario();

    // Mounting Show legitimately stamps update_seen_at, so the fingerprint is
    // taken after the mount and before the action under test.
    $mounted = Livewire::actingAs($x['admin'])->test(Show::class, ['lead' => $x['survivor']->fresh(), 'panel' => $panel])->call('completeInitialLoad');
    $before = mergedDeletionFingerprint([$x['loser']->id, $x['survivor']->id]);

    $component = $act($mounted);

    expect(mergedDeletionToasts($component))->toBe([['danger', MERGED_DELETION_SURVIVOR_MESSAGE]]);
    expect($component->effects['redirect'] ?? null)->toBeNull();
    expect(collect($component->effects['dispatches'] ?? [])->pluck('name')->all())->not->toContain('lead-panel-deleted');
    expect(mergedDeletionFingerprint([$x['loser']->id, $x['survivor']->id]))->toBe($before);
})->with([
    'page: delete' => [false, fn ($c) => $c->call('delete')],
    'page: deleteConfirmed with the right password' => [false, fn ($c) => $c->set('deletePassword', 'password')->call('deleteConfirmed')],
    'panel: delete' => [true, fn ($c) => $c->call('delete')],
    'panel: deleteConfirmed with the right password' => [true, fn ($c) => $c->set('deletePassword', 'password')->call('deleteConfirmed')],
]);

test('Show::confirmDelete refuses early for a merged survivor instead of opening the password modal', function () {
    $x = mergedDeletionScenario();

    $component = Livewire::actingAs($x['admin'])->test(Show::class, ['lead' => $x['survivor']->fresh()])
        ->call('completeInitialLoad')
        ->call('confirmDelete');

    expect(mergedDeletionToasts($component))->toBe([['danger', MERGED_DELETION_SURVIVOR_MESSAGE]]);
    expect($component->get('showDeleteModal'))->toBeFalse();
});

// ── Bulk ────────────────────────────────────────────────────────────────────

test('bulk delete of a survivor alone reports zero deleted, one skipped, plus the survivor danger toast', function () {
    $x = mergedDeletionScenario();
    $before = mergedDeletionFingerprint([$x['loser']->id, $x['survivor']->id]);

    $component = mergedDeletionIndex($x)
        ->set('selectedLeadIds', [$x['survivor']->id])
        ->call('confirmBulkDelete')
        ->set('deletePassword', 'password')
        ->call('deleteLeadConfirmed');

    expect(mergedDeletionToasts($component))->toBe([
        ['warning', '0 of 1 Leads deleted. 1 skipped.'],
        ['danger', '1 selected lead has merged leads and cannot be deleted.'],
    ]);
    expect(mergedDeletionFingerprint([$x['loser']->id, $x['survivor']->id]))->toBe($before);
});

test('a mixed bulk selection deletes only the normal lead and skips the survivor', function () {
    $x = mergedDeletionScenario();
    $normal = Lead::factory()->create(['tenant_id' => $x['tenant']->id, 'source_id' => $x['source']->id, 'name' => 'Normal Lead']);
    $before = mergedDeletionFingerprint([$x['loser']->id, $x['survivor']->id]);

    $component = mergedDeletionIndex($x)
        ->set('selectedLeadIds', [$x['survivor']->id, $normal->id])
        ->call('confirmBulkDelete')
        ->set('deletePassword', 'password')
        ->call('deleteLeadConfirmed');

    expect(mergedDeletionToasts($component))->toBe([
        ['warning', '1 of 2 Leads deleted. 1 skipped.'],
        ['danger', '1 selected lead has merged leads and cannot be deleted.'],
    ]);
    expect(Lead::find($normal->id))->toBeNull();
    expect(LeadDeletionAudit::count())->toBe(1);
    expect(LeadDeletionAudit::first()->lead_name)->toBe('Normal Lead');

    // Everything except the one legitimate audit row (the normal lead's) is
    // exactly as it was.
    $after = mergedDeletionFingerprint([$x['loser']->id, $x['survivor']->id]);
    $after['lead_deletion_audits'] = $before['lead_deletion_audits'] = 0;
    expect($after)->toBe($before);
});

test('several refused survivors are reported with the plural toast', function () {
    $one = mergedDeletionScenario();
    $twoSurvivor = Lead::factory()->create(['tenant_id' => $one['tenant']->id, 'source_id' => $one['source']->id, 'name' => 'Second Survivor']);
    $twoLoser = Lead::factory()->create(['tenant_id' => $one['tenant']->id, 'source_id' => $one['source']->id, 'name' => 'Second Loser']);
    app(LeadMergeService::class)->merge($twoSurvivor->fresh(), $twoLoser->fresh(), $one['admin'], []);

    $component = mergedDeletionIndex($one)
        ->set('selectedLeadIds', [$one['survivor']->id, $twoSurvivor->id])
        ->call('confirmBulkDelete')
        ->set('deletePassword', 'password')
        ->call('deleteLeadConfirmed');

    expect(mergedDeletionToasts($component))->toBe([
        ['warning', '0 of 2 Leads deleted. 2 skipped.'],
        ['danger', '2 selected leads have merged leads and cannot be deleted.'],
    ]);
});

// ── Merge chains (A → B → C) ────────────────────────────────────────────────

test('every node of a merge chain is protected: A and B as losers, C as a survivor — and nothing is deleted', function () {
    $x = mergedDeletionChain();
    $ids = [$x['a']->id, $x['b']->id, $x['c']->id];
    $before = mergedDeletionFingerprint($ids);
    $index = mergedDeletionIndex($x);

    expect(mergedDeletionToasts($index->call('delete', $x['a']->id)))->toBe([['danger', MERGED_DELETION_LOSER_MESSAGE]]);
    expect(mergedDeletionToasts($index->call('delete', $x['b']->id)))->toBe([['danger', MERGED_DELETION_LOSER_MESSAGE]]);
    expect(mergedDeletionToasts($index->call('delete', $x['c']->id)))->toBe([['danger', MERGED_DELETION_SURVIVOR_MESSAGE]]);

    expect(mergedDeletionFingerprint($ids))->toBe($before);
    expect(LeadDeletionAudit::count())->toBe(0);
});

test('bulk delete of the chain: the top survivor is refused and both losers are excluded', function () {
    $x = mergedDeletionChain();
    $ids = [$x['a']->id, $x['b']->id, $x['c']->id];
    $before = mergedDeletionFingerprint($ids);

    $component = mergedDeletionIndex($x)
        ->set('selectedLeadIds', $ids)
        ->call('confirmBulkDelete')
        ->set('deletePassword', 'password')
        ->call('deleteLeadConfirmed');

    expect(mergedDeletionToasts($component))->toBe([
        ['warning', '0 of 3 Leads deleted. 3 skipped.'],
        ['danger', '1 selected lead has merged leads and cannot be deleted.'],
    ]);
    expect(mergedDeletionFingerprint($ids))->toBe($before);
});

// ── Controls: ordinary deletion is unchanged ────────────────────────────────

test('a normal lead still deletes through Show', function () {
    ['tenant' => $tenant, 'clientAdmin' => $admin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Manual]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    $component = Livewire::actingAs($admin)->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')->call('delete');

    expect($component->effects['redirect'] ?? null)->not->toBeNull();
    expect(Lead::find($lead->id))->toBeNull();
    expect(LeadDeletionAudit::count())->toBe(1);
});

test('a normal lead still deletes through Index, and its confirm opener still opens', function () {
    ['tenant' => $tenant, 'clientAdmin' => $admin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Manual]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $scenario = ['admin' => $admin];

    $opened = mergedDeletionIndex($scenario)->call('confirmDeleteLead', $lead->id);
    expect($opened->get('showDeleteLeadModal'))->toBeTrue();
    expect(mergedDeletionToasts($opened))->toBe([]);

    $deleted = mergedDeletionIndex($scenario)->call('delete', $lead->id);
    expect(mergedDeletionToasts($deleted))->toBe([['success', 'Lead deleted.']]);
    expect(Lead::find($lead->id))->toBeNull();
    expect(LeadDeletionAudit::count())->toBe(1);
});

test('Show::confirmDelete still opens for a normal lead', function () {
    ['tenant' => $tenant, 'clientAdmin' => $admin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Manual]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    $component = Livewire::actingAs($admin)->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')->call('confirmDelete');

    expect($component->get('showDeleteModal'))->toBeTrue();
    expect(mergedDeletionToasts($component))->toBe([]);
});

test('a normal lead still deletes through bulk delete with no survivor toast', function () {
    ['tenant' => $tenant, 'clientAdmin' => $admin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Manual]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    $component = mergedDeletionIndex(['admin' => $admin])
        ->set('selectedLeadIds', [$lead->id])
        ->call('confirmBulkDelete')
        ->set('deletePassword', 'password')
        ->call('deleteLeadConfirmed');

    expect(mergedDeletionToasts($component))->toBe([['success', '1 of 1 Leads deleted.']]);
    expect(Lead::find($lead->id))->toBeNull();
});

test('a lead that only has a Pending or Dismissed duplicate link — no merged losers — is still deletable', function (LeadDuplicateLinkStatus $status) {
    ['tenant' => $tenant, 'clientAdmin' => $admin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Manual]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $other = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    LeadDuplicateLink::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => $status,
        ...array_combine(['lead_id', 'duplicate_lead_id'], LeadDuplicateLink::pairKeys($lead, $other)),
    ]);

    $component = mergedDeletionIndex(['admin' => $admin])->call('delete', $lead->id);

    expect(mergedDeletionToasts($component))->toBe([['success', 'Lead deleted.']]);
    expect(Lead::find($lead->id))->toBeNull();
})->with([
    'pending' => [LeadDuplicateLinkStatus::Pending],
    'dismissed' => [LeadDuplicateLinkStatus::Dismissed],
]);

// ── Authorization and ordering ──────────────────────────────────────────────

test('an actor without delete permission still gets the permission denial, not a merged-lead message', function (string $which) {
    $x = mergedDeletionScenario();
    $rep = clientUserWithLeadPermission($x['tenant'], ['view', 'update']);
    $before = mergedDeletionFingerprint([$x['loser']->id, $x['survivor']->id]);

    $component = Livewire::actingAs($rep)->test(Index::class)->call('completeInitialLoad')->call('delete', $x[$which]->id);

    expect(mergedDeletionToasts($component))->toBe([['danger', "You don't have permission to delete this lead."]]);
    expect(mergedDeletionFingerprint([$x['loser']->id, $x['survivor']->id]))->toBe($before);
})->with(['loser', 'survivor']);

test('the individual-deletion source-type restriction still wins over the merged-lead rule', function () {
    $x = mergedDeletionScenario(LeadSourceType::Whatsapp);

    $component = mergedDeletionIndex($x)->call('delete', $x['survivor']->id);

    expect(mergedDeletionToasts($component))->toBe([['danger', "This lead's source type cannot be deleted individually."]]);
});

test('tenant scope is still enforced: another tenant\'s admin cannot reach a merged lead', function () {
    $x = mergedDeletionScenario();
    ['clientAdmin' => $otherAdmin] = tenantWithLeadManagement();

    expect(fn () => Livewire::actingAs($otherAdmin)->test(Index::class)->call('completeInitialLoad')->call('delete', $x['survivor']->id))
        ->toThrow(ModelNotFoundException::class);

    expect(Lead::find($x['survivor']->id))->not->toBeNull();
});

// ── The evaluator itself ────────────────────────────────────────────────────

test('the evaluator: a normal lead is eligible, a merged loser and a survivor are not', function () {
    $x = mergedDeletionScenario();
    $normal = Lead::factory()->create(['tenant_id' => $x['tenant']->id, 'source_id' => $x['source']->id]);
    $service = app(LeadDeletionEligibilityService::class);

    expect($service->evaluate($normal)->eligible)->toBeTrue();

    $loser = $service->evaluate($x['loser']);
    expect($loser->eligible)->toBeFalse();
    expect($loser->reason)->toBe(MERGED_DELETION_LOSER_MESSAGE);

    $survivor = $service->evaluate($x['survivor']);
    expect($survivor->eligible)->toBeFalse();
    expect($survivor->reason)->toBe(MERGED_DELETION_SURVIVOR_MESSAGE);

    // The advisory (unlocked) form gives the same verdicts.
    expect($service->evaluate($x['survivor'], lock: false)->eligible)->toBeFalse();
    expect($service->evaluate($normal, lock: false)->eligible)->toBeTrue();
});

test('the evaluator treats a row that is already gone as eligible, leaving that case to RecordLeadDeletionAudit', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Manual]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    DB::table('leads')->where('id', $lead->id)->delete();

    expect(app(LeadDeletionEligibilityService::class)->evaluate($lead)->eligible)->toBeTrue();
});

test('the evaluator\'s survivor lookup is tenant-scoped', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Manual]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    ['tenant' => $foreignTenant] = tenantWithLeadManagement();

    // Unreachable through the app (a merge is always same-tenant), forced
    // here only to prove the lookup can never see another tenant's rows.
    Lead::factory()->create(['tenant_id' => $foreignTenant->id, 'merged_into_lead_id' => $lead->id]);

    expect(app(LeadDeletionEligibilityService::class)->evaluate($lead)->eligible)->toBeTrue();
});
