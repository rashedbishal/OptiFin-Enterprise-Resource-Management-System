<?php

use App\Enums\TaskStatus;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Lead;
use App\Models\Task;
use App\Models\TaskStatusLog;
use App\Models\TenantUser;
use Illuminate\Support\Str;
use Livewire\Livewire;

test('a task moves from pending to completed and records a status log entry, setting closed_at', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $task = Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('changeTaskStatus', $task->id, 'completed')
        ->assertHasNoErrors();

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Completed);
    expect($task->closed_at)->not->toBeNull();

    $log = TaskStatusLog::where('task_id', $task->id)->latest('created_at')->firstOrFail();
    expect($log->old_status)->toBe(TaskStatus::Pending);
    expect($log->new_status)->toBe(TaskStatus::Completed);
    expect($log->changed_by)->toBe($clientAdmin->id);
});

test('a task moves from pending to cancelled and records a status log entry, setting closed_at', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $task = Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('changeTaskStatus', $task->id, 'cancelled');

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Cancelled);
    expect($task->closed_at)->not->toBeNull();
    expect(TaskStatusLog::where('task_id', $task->id)->where('new_status', TaskStatus::Cancelled->value)->exists())->toBeTrue();
});

test('a failure partway through changeTaskStatus() rolls back both the task update and its status log', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $task = Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);

    // A TenantUser with the identical tenant_id/role_id/user_type as the
    // real, already-authorized $clientAdmin, but never actually persisted:
    // Gate::denies('update', $lead) passes, but TaskStatusLog.changed_by's
    // foreign key to tenant_users then fails on the write immediately after
    // $task->update() — mirroring the same technique used for
    // updateStatus()/changeDealStatus() and the established pattern in
    // LeadMergeTest.php/LeadAssignmentTest.php.
    $neverPersisted = TenantUser::factory()->make([
        'id' => (string) Str::ulid(),
        'tenant_id' => $clientAdmin->tenant_id,
        'role_id' => $clientAdmin->role_id,
        'user_type' => $clientAdmin->user_type,
    ]);

    expect(fn () => Livewire::actingAs($neverPersisted)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('changeTaskStatus', $task->id, 'completed'))
        ->toThrow(Exception::class);

    // Nothing persisted: the task's status/closed_at change made by
    // $task->update() inside the same transaction was rolled back, and no
    // TaskStatusLog row exists.
    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Pending);
    expect($task->closed_at)->toBeNull();
    expect(TaskStatusLog::where('task_id', $task->id)->exists())->toBeFalse();
});

test('a completed task can be reopened, clearing its closed_at', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $task = Task::factory()->completed()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('changeTaskStatus', $task->id, 'pending')
        ->assertHasNoErrors();

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Pending);
    expect($task->closed_at)->toBeNull();

    $log = TaskStatusLog::where('task_id', $task->id)->latest('created_at')->firstOrFail();
    expect($log->old_status)->toBe(TaskStatus::Completed);
    expect($log->new_status)->toBe(TaskStatus::Pending);
});

test('a cancelled task can be reopened, clearing its closed_at', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $task = Task::factory()->cancelled()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('changeTaskStatus', $task->id, 'pending');

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Pending);
    expect($task->closed_at)->toBeNull();
});

test('a completed task cannot transition directly to cancelled', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $task = Task::factory()->completed()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('changeTaskStatus', $task->id, 'cancelled');

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Completed);
    expect(TaskStatusLog::where('task_id', $task->id)->where('new_status', TaskStatus::Cancelled->value)->exists())->toBeFalse();
    // The rejected transition must not touch closed_at either.
    expect($task->closed_at)->not->toBeNull();
});

test('a cancelled task cannot transition directly to completed', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $task = Task::factory()->cancelled()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('changeTaskStatus', $task->id, 'completed');

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Cancelled);
    expect(TaskStatusLog::where('task_id', $task->id)->where('new_status', TaskStatus::Completed->value)->exists())->toBeFalse();
    expect($task->closed_at)->not->toBeNull();
});

test('no status log is written when a transition is rejected', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $task = Task::factory()->completed()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('changeTaskStatus', $task->id, 'cancelled');

    expect(TaskStatusLog::where('task_id', $task->id)->count())->toBe(0);
});

test('a task status change never touches the lead\'s own status', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id]);
    $task = Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('changeTaskStatus', $task->id, 'completed');

    expect($lead->fresh()->status_id)->toBe(leadStatus('new')->id);
});

test('due_date is never touched by a status change, only closed_at', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $dueDate = now()->addDays(5)->toDateString();
    $task = Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'due_date' => $dueDate]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('changeTaskStatus', $task->id, 'completed');

    expect($task->fresh()->due_date?->toDateString())->toBe($dueDate);
});

test('task status transitions appear in the lead\'s activity feed', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $task = Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'title' => 'Follow-up Call']);

    $component = Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('changeTaskStatus', $task->id, 'completed');

    $event = collect($component->instance()->activity())->firstWhere('type', 'task_status_change');
    expect($event)->not->toBeNull();
    expect($event['task_title'])->toBe('Follow-up Call');
    expect($event['new_status'])->toBe('Completed');
});

test('task creation and reassignment are not logged to the activity timeline', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    $component = Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('createTask')
        ->set('taskTitle', 'New Task')
        ->call('saveTask');

    $task = Task::where('lead_id', $lead->id)->firstOrFail();

    $component->call('editTask', $task->id)
        ->set('taskAssignedTo', (string) $rep->id)
        ->call('saveTask');

    $events = collect($component->instance()->activity())->pluck('type');
    expect($events)->not->toContain('task_created');
    expect($events)->not->toContain('task_reassigned');
});

test('an assigned client user without update permission cannot change a task\'s status', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id]);
    $task = Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);

    Livewire::actingAs($rep)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('changeTaskStatus', $task->id, 'completed');

    expect($task->fresh()->status)->toBe(TaskStatus::Pending);
});
