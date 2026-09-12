<?php

use App\Enums\TaskStatus;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Task;
use App\Models\TaskStatusLog;
use App\Models\TenantUser;
use App\Services\LeadMergeService;
use Illuminate\Support\Str;

test('merging reparents every one of the loser\'s tasks onto the survivor', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);

    $survivorTask = Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $survivor->id, 'title' => 'Survivor Task']);
    $loserTaskA = Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $loser->id, 'title' => 'Loser Task A']);
    $loserTaskB = Task::factory()->completed()->create(['tenant_id' => $tenant->id, 'lead_id' => $loser->id, 'title' => 'Loser Task B']);

    app(LeadMergeService::class)->merge($survivor, $loser, $clientAdmin, []);

    expect($survivorTask->fresh()->lead_id)->toBe($survivor->id);
    expect($loserTaskA->fresh()->lead_id)->toBe($survivor->id);
    expect($loserTaskB->fresh()->lead_id)->toBe($survivor->id);
    expect($survivor->fresh()->tasks)->toHaveCount(3);
});

test('task ids are unchanged by a merge', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $loserTask = Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $loser->id]);
    $originalId = $loserTask->id;

    app(LeadMergeService::class)->merge($survivor, $loser, $clientAdmin, []);

    expect($loserTask->fresh()->id)->toBe($originalId);
});

test('task status log history remains attached to its task after a merge, with no reparenting statement of its own', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $loserTask = Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $loser->id]);

    $log = TaskStatusLog::factory()->create([
        'task_id' => $loserTask->id,
        'old_status' => TaskStatus::Pending,
        'new_status' => TaskStatus::Completed,
    ]);

    app(LeadMergeService::class)->merge($survivor, $loser, $clientAdmin, []);

    $log->refresh();
    expect($log->task_id)->toBe($loserTask->id);
    expect($log->task->lead_id)->toBe($survivor->id);
    expect(TaskStatusLog::where('task_id', $loserTask->id)->count())->toBe(1);
});

test('a task\'s deal_id remains unchanged and still valid after a merge, since its Deal is reparented to the same survivor', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $loserDeal = Deal::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $loser->id]);
    $loserTask = Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $loser->id, 'deal_id' => $loserDeal->id]);
    $originalDealId = $loserDeal->id;

    app(LeadMergeService::class)->merge($survivor, $loser, $clientAdmin, []);

    $loserTask->refresh();
    $loserDeal->refresh();
    expect($loserTask->deal_id)->toBe($originalDealId);
    expect($loserDeal->id)->toBe($originalDealId);
    expect($loserDeal->lead_id)->toBe($survivor->id);
    expect($loserTask->lead_id)->toBe($survivor->id);
    // The Task -> Deal relationship stays consistent: both now belong to
    // the same survivor Lead.
    expect($loserTask->deal->lead_id)->toBe($loserTask->lead_id);
});

test('a merge with no tasks on either lead succeeds without error', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);

    $result = app(LeadMergeService::class)->merge($survivor, $loser, $clientAdmin, []);

    expect($result->tasks)->toHaveCount(0);
});

test('the survivor keeps its own tasks and gains the loser\'s — none are lost, none are duplicated', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);

    Task::factory()->count(2)->create(['tenant_id' => $tenant->id, 'lead_id' => $survivor->id]);
    Task::factory()->count(3)->create(['tenant_id' => $tenant->id, 'lead_id' => $loser->id]);

    app(LeadMergeService::class)->merge($survivor, $loser, $clientAdmin, []);

    expect(Task::where('lead_id', $survivor->id)->count())->toBe(5);
    expect(Task::where('lead_id', $loser->id)->count())->toBe(0);
    expect(Task::count())->toBe(5);
});

test('a failure partway through a merge rolls back task reparenting too', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $loserTask = Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $loser->id]);

    // Same fault-injection technique as LeadMergeTest's own rollback test: a
    // TenantUser built but never persisted trips the LeadDuplicateLink.
    // resolved_by foreign key on the merge's very last write — AFTER Task
    // reparenting has already run earlier in the SAME transaction.
    $neverPersisted = TenantUser::factory()->make(['id' => (string) Str::ulid(), 'tenant_id' => $tenant->id]);

    expect(fn () => app(LeadMergeService::class)->merge($survivor, $loser, $neverPersisted, []))
        ->toThrow(Exception::class);

    expect($loserTask->fresh()->lead_id)->toBe($loser->id);
    expect($loser->fresh()->merged_into_lead_id)->toBeNull();
});
