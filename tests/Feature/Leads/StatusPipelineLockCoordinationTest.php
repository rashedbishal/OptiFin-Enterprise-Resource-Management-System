<?php

use App\Actions\Tenant\CreateLeadFromImport;
use App\Actions\Tenant\CreateManualLead;
use App\Enums\LeadPriority;
use App\Enums\LeadSourceType;
use App\Events\LeadReceived;
use App\Livewire\Platform\Clients\LeadSources;
use App\Livewire\Tenant\Leads\Index;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Lead;
use App\Models\LeadImport;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\LeadStatusLog;
use App\Models\PlatformUser;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

/**
 * Regression coverage for the toggleLeadStatus() TOCTOU / silent
 * pipeline-stranding fix — see that method's own docblock in
 * App\Livewire\Platform\Clients\LeadSources for the full readers/writer
 * protocol these tests exercise.
 *
 * PostgreSQL's actual FOR SHARE / FOR UPDATE blocking semantics (multiple
 * FOR SHARE holders never block each other; FOR UPDATE blocks until every
 * outstanding FOR SHARE commits; a new FOR SHARE request queues behind a
 * pending FOR UPDATE) were independently verified against two real,
 * concurrent PDO connections to this app's own database — confirmed via a
 * statement_timeout-based proof, not sleep/timing races. SQLite (this
 * suite's test driver) cannot reproduce that verification: its query
 * grammar silently no-ops any lock clause
 * (Illuminate\Database\Query\Grammars\SQLiteGrammar::compileLock() always
 * returns an empty string, so a locked and an unlocked read of the same row
 * are textually indistinguishable here), so a single-connection test in
 * this file proves the *application-level* lock-then-resolve statement
 * ordering and the safe fall-through/refusal behavior on each side of the
 * race, never the lock's own cross-connection blocking guarantee — the
 * same documented limitation already carried by every prior TOCTOU fix in
 * this module (e.g. the deleteStatus()/deleteCurrency()/LeadCustomFields
 * closures' own test docblocks).
 *
 * Every capturedSql() call below wraps only the single Livewire action (or
 * action-equivalent) under test, never any setup step — Livewire's own
 * per-request model-property rehydration (e.g. LeadSources's public
 * Tenant $tenant) issues its own "tenants" query on every round trip,
 * indistinguishable by SQL text from this fix's own coordination-lock
 * query, so lumping setup calls into the same capture would make any
 * query-count/ordering assertion meaningless.
 *
 * @return array<int, string>
 */
function capturedSql(callable $action): array
{
    $queries = [];

    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $action();

    return $queries;
}

/**
 * @return array<int, int>
 */
function indexesTouching(array $queries, string $table): array
{
    $indexes = [];

    foreach ($queries as $index => $sql) {
        if (str_contains($sql, "\"{$table}\"")) {
            $indexes[] = $index;
        }
    }

    return $indexes;
}

function firstIndexTouching(array $queries, string $table): ?int
{
    return indexesTouching($queries, $table)[0] ?? null;
}

/**
 * Indexes of queries against the tenant's leadStatuses() BelongsToMany
 * relation specifically (Tenant → tenant_lead_statuses → lead_statuses) —
 * distinct from a plain query against the tenant_lead_statuses pivot table
 * alone (e.g. sync()'s own internal reads/writes, or a
 * Rule::exists('tenant_lead_statuses', ...) validation check), both of
 * which also reference "tenant_lead_statuses" but never "lead_statuses" as
 * their own FROM table.
 *
 * @return array<int, int>
 */
function indexesResolvingPipeline(array $queries): array
{
    $indexes = [];

    foreach ($queries as $index => $sql) {
        if (str_starts_with($sql, 'select "lead_statuses"')) {
            $indexes[] = $index;
        }
    }

    return $indexes;
}

function firstIndexMatching(array $queries, string $needle): ?int
{
    foreach ($queries as $index => $sql) {
        if (str_starts_with($sql, $needle)) {
            return $index;
        }
    }

    return null;
}

// --- A: toggleLeadStatus() removal branch ----------------------------------

test('toggleLeadStatus() removal re-validates the pipeline under the coordination lock, after acquiring it', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();

    $component = Livewire::actingAs($motherAdmin, 'platform')->test(LeadSources::class, ['tenant' => $tenant]);

    $queries = capturedSql(fn () => $component->call('toggleLeadStatus', leadStatus('negotiation')->id));

    // At least two pipeline reads: an unlocked pre-check deciding add vs.
    // remove, and — only for a removal — a locked re-check under the
    // coordination lock, which is what actually gates the sync() removal
    // below it (a third, later one is Livewire's own post-action re-render
    // of the picker UI, harmless and irrelevant to lock ordering). The lock
    // query must sit strictly between the first two: never before the
    // first (it has nothing to protect yet), never after the second (it
    // would arrive too late to protect the re-check it's meant to guard).
    $pipelineIndexes = indexesResolvingPipeline($queries);
    expect(count($pipelineIndexes))->toBeGreaterThanOrEqual(2);

    $tenantsBetween = array_filter(
        indexesTouching($queries, 'tenants'),
        fn (int $i): bool => $i > $pipelineIndexes[0] && $i < $pipelineIndexes[1],
    );

    expect($tenantsBetween)->not->toBeEmpty();

    $deleteIndex = firstIndexMatching($queries, 'delete from "tenant_lead_statuses"');
    expect($deleteIndex)->not->toBeNull()->and($deleteIndex)->toBeGreaterThan($pipelineIndexes[1]);

    expect($tenant->fresh()->leadStatuses->pluck('slug')->all())->not->toContain('negotiation');
});

test('only the removal branch of toggleLeadStatus() issues an extra tenant-row query for its coordination lock', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $custom = LeadStatus::factory()->create(['name' => 'Custom Stage']);

    $component = Livewire::actingAs($motherAdmin, 'platform')->test(LeadSources::class, ['tenant' => $tenant]);

    // Addition: no coordination lock is needed (see toggleLeadStatus()'s
    // own docblock) — the only "tenants" query expected here is Livewire's
    // own per-request model-property rehydration.
    $additionQueries = capturedSql(fn () => $component->call('toggleLeadStatus', $custom->id));
    $additionTenantsCount = count(indexesTouching($additionQueries, 'tenants'));

    // Removal: the same per-request rehydration, PLUS this fix's own
    // explicit coordination lock — exactly one more "tenants" query.
    $removalQueries = capturedSql(fn () => $component->call('toggleLeadStatus', leadStatus('negotiation')->id));
    $removalTenantsCount = count(indexesTouching($removalQueries, 'tenants'));

    expect($removalTenantsCount)->toBe($additionTenantsCount + 1);

    expect($tenant->fresh()->leadStatuses->pluck('id')->contains($custom->id))->toBeTrue();
    expect($tenant->fresh()->leadStatuses->pluck('slug')->all())->not->toContain('negotiation');
});

test('a lead already sitting in the target status still refuses removal after the lock fix', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('qualified')->id]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('toggleLeadStatus', leadStatus('qualified')->id)
        ->assertDispatched('toast-show');

    expect($tenant->fresh()->leadStatuses->pluck('slug')->all())->toContain('qualified');
});

// --- B: a representative real writer (Index::moveLead()) -------------------

test('moveLead() acquires the tenant lock before resolving the target status', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id, 'assigned_to' => $rep->id]);

    $component = Livewire::actingAs($rep)->test(Index::class)->call('completeInitialLoad');

    $queries = capturedSql(fn () => $component->call('moveLead', $lead->id, leadStatus('contacted')->id));

    $tenantsIndex = firstIndexTouching($queries, 'tenants');
    $pipelineIndex = firstIndexTouching($queries, 'tenant_lead_statuses');

    expect($tenantsIndex)->not->toBeNull()
        ->and($pipelineIndex)->not->toBeNull()
        ->and($tenantsIndex)->toBeLessThan($pipelineIndex);

    expect($lead->fresh()->status_id)->toBe(leadStatus('contacted')->id);
});

test('moveLead() targeting a status no longer in the pipeline safely no-ops instead of stranding the lead', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id, 'assigned_to' => $rep->id]);
    $foreignStatus = LeadStatus::factory()->create();

    Livewire::actingAs($rep)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('moveLead', $lead->id, $foreignStatus->id);

    expect($lead->fresh()->status_id)->toBe(leadStatus('new')->id);
});

// --- C: protocol participation for the remaining writers -------------------

test('Show::updateStatus() acquires the tenant lock before resolving the target status', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id]);

    $component = Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->set('status', leadStatus('contacted')->id);

    $queries = capturedSql(fn () => $component->call('updateStatus'));

    $tenantsIndex = firstIndexTouching($queries, 'tenants');
    $pipelineIndex = firstIndexTouching($queries, 'tenant_lead_statuses');

    expect($tenantsIndex)->not->toBeNull()
        ->and($pipelineIndex)->not->toBeNull()
        ->and($tenantsIndex)->toBeLessThan($pipelineIndex);

    expect($lead->fresh()->status_id)->toBe(leadStatus('contacted')->id);
});

test('Index::applyBulkStatusChange() acquires the tenant lock once, before resolving the target status', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $leads = Lead::factory()->count(3)->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id]);

    $component = Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('completeInitialLoad')
        ->set('selectedLeadIds', collect($leads)->pluck('id')->all())
        ->call('openBulkStatusModal')
        ->set('bulkStatusId', leadStatus('contacted')->id);

    $queries = capturedSql(fn () => $component->call('applyBulkStatusChange'));

    $tenantsIndexes = indexesTouching($queries, 'tenants');
    // Deliberately the leadStatuses()-relation resolution, not a bare
    // "tenant_lead_statuses" substring match — the Rule::exists('tenant_lead_statuses', ...)
    // validation rule this method runs first also touches that table, but
    // is not the resolution this fix's lock is protecting.
    $pipelineIndex = indexesResolvingPipeline($queries)[0] ?? null;

    expect($tenantsIndexes)->not->toBeEmpty()
        ->and($pipelineIndex)->not->toBeNull();

    // Exactly one "tenants" touch precedes the pipeline resolution — this
    // fix's own coordination lock, acquired once for the whole bounded
    // batch, not per lead (the approved architecture's special requirement
    // for this writer). Later "tenants" touches (there are more, one per
    // eligible lead) come from this method's own pre-existing, unrelated
    // $lead->tenant->initialLeadStatus() bookkeeping inside the loop —
    // unaffected by this fix, not what this assertion is about.
    $tenantsBeforePipeline = array_filter($tenantsIndexes, fn (int $i): bool => $i < $pipelineIndex);

    expect($tenantsBeforePipeline)->toHaveCount(1);

    foreach ($leads as $lead) {
        expect($lead->fresh()->status_id)->toBe(leadStatus('contacted')->id);
    }
});

test('applyBulkStatusChange() isolates one lead\'s failure via a nested savepoint without rolling back the whole batch or the tenant lock', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $leadA = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id]);
    $leadB = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id]);
    $leadC = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id]);

    // Deterministic failure injection, the same technique this module's
    // own prior TOCTOU closures use (a real, non-queued Eloquent model
    // event listener that deliberately throws) — fires strictly after
    // lead B's own $lead->update() has already executed inside its
    // nested transaction, proving the savepoint rolls back a write that
    // genuinely already happened, not merely a write that never ran.
    LeadStatusLog::created(function (LeadStatusLog $log) use ($leadB): void {
        if ($log->lead_id === $leadB->id) {
            throw new RuntimeException('Simulated per-lead failure for lead B.');
        }
    });

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('completeInitialLoad')
        ->set('selectedLeadIds', [$leadA->id, $leadB->id, $leadC->id])
        ->call('openBulkStatusModal')
        ->set('bulkStatusId', leadStatus('contacted')->id)
        ->call('applyBulkStatusChange')
        ->assertDispatched('toast-show');

    // Lead A: committed before the failure, stays committed — the tenant
    // lock/outer transaction was never rolled back by B's failure.
    expect($leadA->fresh()->status_id)->toBe(leadStatus('contacted')->id);
    expect(LeadStatusLog::where('lead_id', $leadA->id)->exists())->toBeTrue();

    // Lead B: its own nested transaction/savepoint rolled back completely
    // — both the status write and the log row it had already produced are
    // gone, proving a real savepoint rollback, not a partial one.
    expect($leadB->fresh()->status_id)->toBe(leadStatus('new')->id);
    expect(LeadStatusLog::where('lead_id', $leadB->id)->exists())->toBeFalse();

    // Lead C: the loop continued past B's isolated failure and still
    // succeeded — the improvement over the original (uncaught-exception,
    // whole-loop-aborting) behavior explicitly approved for this
    // correction.
    expect($leadC->fresh()->status_id)->toBe(leadStatus('contacted')->id);
    expect(LeadStatusLog::where('lead_id', $leadC->id)->exists())->toBeTrue();
});

test('CreateManualLead acquires the tenant lock before writing the lead', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $component = Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('createLead')
        ->set('leadName', 'Query Order Test')
        ->set('leadPhone', '+8801711111111')
        ->set('leadStatus', leadStatus('new')->id)
        ->set('leadPriority', 'medium');

    $queries = capturedSql(fn () => $component->call('saveLead')->assertHasNoErrors());

    $tenantsIndex = firstIndexTouching($queries, 'tenants');
    $leadsInsertIndex = firstIndexMatching($queries, 'insert into "leads"');

    expect($tenantsIndex)->not->toBeNull()
        ->and($leadsInsertIndex)->not->toBeNull()
        ->and($tenantsIndex)->toBeLessThan($leadsInsertIndex);

    $lead = Lead::where('tenant_id', $tenant->id)->where('name', 'Query Order Test')->firstOrFail();
    expect($lead->status_id)->toBe(leadStatus('new')->id);
});

test('CreateManualLead falls back to the pipeline\'s first stage instead of stranding the lead if the supplied status is no longer valid', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $foreignStatus = LeadStatus::factory()->create();

    // Bypasses the Livewire form's own validation (LeadValidationRules::statusId(),
    // which would normally reject a foreign status before this Action is
    // ever reached) to directly exercise the Action's own re-check — the
    // only way this branch is reachable is a genuine race, per this
    // finding's own analysis.
    $lead = app(CreateManualLead::class)->handle($tenant, [
        'name' => 'Fallback Test',
        'company' => null,
        'website' => null,
        'industry' => null,
        'location' => null,
        'service_interest' => null,
        'lead_source' => null,
        'email' => null,
        'phone' => '+8801711111112',
        'message' => null,
        'status_id' => $foreignStatus->id,
        'priority' => LeadPriority::Medium,
        'estimated_budget' => null,
        'deal_value' => null,
        'win_probability' => null,
        'next_follow_up_at' => null,
    ], $clientAdmin, null);

    expect($lead->status_id)->toBe($tenant->initialLeadStatus()->id);
});

test('CreateLeadFromImport resolves status inside the transaction after the lock', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::CsvImport, 'name' => 'CSV Import']);
    $import = LeadImport::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'imported_by' => $clientAdmin->id]);

    $queries = capturedSql(fn () => app(CreateLeadFromImport::class)->handle($import, $tenant, $source, $clientAdmin, 1, [
        'name' => 'Import Query Order Test',
        'email' => 'import-order-test@example.com',
        'status' => 'Contacted',
    ]));

    $tenantsIndex = firstIndexTouching($queries, 'tenants');
    $leadsInsertIndex = firstIndexMatching($queries, 'insert into "leads"');

    expect($tenantsIndex)->not->toBeNull()
        ->and($leadsInsertIndex)->not->toBeNull()
        ->and($tenantsIndex)->toBeLessThan($leadsInsertIndex);

    $lead = Lead::where('tenant_id', $tenant->id)->where('email', 'import-order-test@example.com')->firstOrFail();
    expect($lead->status_id)->toBe(leadStatus('contacted')->id);
});

test('LeadWebhookController::createLead() acquires the tenant lock before resolving the default status', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '123', 'verify_token' => 'secret-token'],
    ]);

    $payload = [
        'entry' => [[
            'messaging' => [[
                'sender' => ['id' => 'psid-lock-order-test'],
                'message' => ['text' => 'Hello there'],
            ]],
        ]],
    ];

    Event::fake([LeadReceived::class]);

    $queries = capturedSql(fn () => $this->postJson(route('api.webhook.facebook', $tenant->tenant_code), $payload)->assertOk());

    $tenantsIndex = firstIndexTouching($queries, 'tenants');
    $leadsInsertIndex = firstIndexMatching($queries, 'insert into "leads"');

    expect($tenantsIndex)->not->toBeNull()
        ->and($leadsInsertIndex)->not->toBeNull()
        ->and($tenantsIndex)->toBeLessThan($leadsInsertIndex);

    expect(Lead::where('tenant_id', $tenant->id)->where('source_data->facebook_user_id', 'psid-lock-order-test')->exists())->toBeTrue();
});
