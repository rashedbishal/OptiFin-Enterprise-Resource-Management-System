<?php

use App\Enums\LeadPriority;
use App\Enums\TaskStatus;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleModulePermission;
use App\Models\Task;
use App\Models\TaskStatusLog;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Services\LeadAssignmentService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * A Client Admin whose role is deliberately scoped to lead-management's
 * view/create/update permissions but NOT delete — the counterpart to
 * clientUserWithLeadPermission() (tests/Pest.php), which only ever builds
 * Client Users. Needed to prove Task deletion's admin path checks the
 * "delete" module permission specifically (Gate::allows('delete', $lead)),
 * not just "update" — isClientAdmin() alone must not be enough.
 */
function clientAdminWithoutDeletePermission(Tenant $tenant): TenantUser
{
    $module = Module::where('slug', 'lead-management')->firstOrFail();

    $role = Role::create([
        'tenant_id' => $tenant->id,
        'name' => 'Restricted Admin '.Str::random(6),
        'slug' => 'restricted-admin-'.Str::random(6),
    ]);

    $role->modules()->attach($module->id);

    RoleModulePermission::query()->insert(
        Permission::whereIn('slug', ['view', 'create', 'update'])->pluck('id')->map(fn (string $permissionId): array => [
            'role_id' => $role->id,
            'module_id' => $module->id,
            'permission_id' => $permissionId,
        ])->all()
    );

    return TenantUser::factory()->clientAdmin()->create([
        'tenant_id' => $tenant->id,
        'role_id' => $role->id,
    ]);
}

test('a client admin can create a task with default priority and assignee', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $clientAdmin->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('createTask')
        ->assertSet('taskPriority', LeadPriority::Medium->value)
        ->assertSet('taskAssignedTo', (string) $clientAdmin->id)
        ->set('taskTitle', 'Send proposal')
        ->set('taskDescription', 'Follow up with pricing')
        ->set('taskDueDate', now()->addDays(3)->toDateString())
        ->set('taskDueTime', '14:30')
        ->call('saveTask')
        ->assertHasNoErrors();

    $task = Task::where('lead_id', $lead->id)->firstOrFail();
    expect($task->tenant_id)->toBe($tenant->id);
    expect($task->title)->toBe('Send proposal');
    expect($task->description)->toBe('Follow up with pricing');
    expect($task->priority)->toBe(LeadPriority::Medium);
    expect($task->status)->toBe(TaskStatus::Pending);
    expect($task->assigned_to)->toBe($clientAdmin->id);
    expect($task->created_by)->toBe($clientAdmin->id);
    expect($task->due_date?->toDateString())->toBe(now()->addDays(3)->toDateString());
});

test('a lead can have multiple tasks simultaneously', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);
    Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);

    $component = Livewire::actingAs($clientAdmin)->test(Show::class, ['lead' => $lead])->call('completeInitialLoad');

    expect($component->get('tasks'))->toHaveCount(2);
});

test('rendering the task list does not N+1 per-task assignee/deal lookups', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $deal = Deal::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);
    $assignees = collect(range(1, 4))->map(fn () => clientUserWithLeadPermission($tenant));

    foreach ($assignees as $assignee) {
        Task::factory()->create([
            'tenant_id' => $tenant->id,
            'lead_id' => $lead->id,
            'deal_id' => $deal->id,
            'assigned_to' => $assignee->id,
        ]);
    }

    $component = Livewire::actingAs($clientAdmin)->test(Show::class, ['lead' => $lead])->call('completeInitialLoad');

    DB::flushQueryLog();
    DB::enableQueryLog();
    $tasks = $component->instance()->tasks();
    // Force the lazy assignee/deal relations to actually resolve, the same
    // way the Blade card does — without eager loading this would trigger
    // one extra query per task per relation (2 x 4 = 8 more), not the flat
    // handful an eager-loaded fetch costs regardless of task count.
    $tasks->each(fn ($task) => [$task->assignee?->name, $task->deal?->name]);
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queryCount)->toBeLessThanOrEqual(3);
});

test('a client admin can edit a task', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $task = Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'title' => 'Original']);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('editTask', $task->id)
        ->assertSet('taskTitle', 'Original')
        ->set('taskTitle', 'Renamed')
        ->call('saveTask')
        ->assertHasNoErrors();

    expect($task->fresh()->title)->toBe('Renamed');
});

// --- Deal linkage ----------------------------------------------------------

test('a task can be linked to one of its own lead\'s deals', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $deal = Deal::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('createTask')
        ->set('taskTitle', 'Prepare contract')
        ->set('taskDealId', (string) $deal->id)
        ->call('saveTask')
        ->assertHasNoErrors();

    expect(Task::where('lead_id', $lead->id)->firstOrFail()->deal_id)->toBe($deal->id);
});

test('a task can exist with no deal link at all', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('createTask')
        ->set('taskTitle', 'No deal task')
        ->call('saveTask')
        ->assertHasNoErrors();

    expect(Task::where('lead_id', $lead->id)->firstOrFail()->deal_id)->toBeNull();
});

test('deleting a deal nulls out any task\'s deal_id while preserving the task itself', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $deal = Deal::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);
    $task = Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'deal_id' => $deal->id, 'title' => 'Linked to deal']);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('confirmDeleteDeal', $deal->id)
        ->call('deleteDealConfirmed');

    expect(Deal::find($deal->id))->toBeNull();
    // The task survives, untouched apart from its now-cleared deal_id — no
    // cascade-delete, matching the approved architecture exactly.
    $task->refresh();
    expect($task)->not->toBeNull();
    expect($task->deal_id)->toBeNull();
    expect($task->title)->toBe('Linked to deal');
    expect($task->lead_id)->toBe($lead->id);
});

test('a deal belonging to a different lead is rejected even in the same tenant', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $otherLead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $dealOnOtherLead = Deal::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $otherLead->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('createTask')
        ->set('taskTitle', 'Cross-lead deal attempt')
        ->set('taskDealId', (string) $dealOnOtherLead->id)
        ->call('saveTask')
        ->assertHasErrors('taskDealId');

    expect(Task::where('lead_id', $lead->id)->exists())->toBeFalse();
});

test('a deal belonging to another tenant is rejected', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    ['tenant' => $otherTenant] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $otherLeadForForeignDeal = Lead::factory()->create(['tenant_id' => $otherTenant->id]);
    $foreignDeal = Deal::factory()->create(['tenant_id' => $otherTenant->id, 'lead_id' => $otherLeadForForeignDeal->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('createTask')
        ->set('taskTitle', 'Cross-tenant deal attempt')
        ->set('taskDealId', (string) $foreignDeal->id)
        ->call('saveTask')
        ->assertHasErrors('taskDealId');

    expect(Task::where('lead_id', $lead->id)->exists())->toBeFalse();
});

// --- Authorization -----------------------------------------------------

test('an assigned client user with update permission can create and edit tasks', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id]);

    Livewire::actingAs($rep)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('createTask')
        ->set('taskTitle', 'Rep Task')
        ->call('saveTask')
        ->assertHasNoErrors();

    expect(Task::where('lead_id', $lead->id)->exists())->toBeTrue();
});

test('an assigned client user without update permission cannot create a task', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id]);

    Livewire::actingAs($rep)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->set('taskTitle', 'Should Not Save')
        ->call('saveTask');

    expect(Task::where('lead_id', $lead->id)->exists())->toBeFalse();
});

// --- Deletion authorization (the approved, non-Deal-mirroring model) -------

test('a client admin can delete any task within an authorized lead, regardless of assignee', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $task = Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'assigned_to' => $rep->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('confirmDeleteTask', $task->id)
        ->call('deleteTaskConfirmed');

    expect(Task::find($task->id))->toBeNull();
});

test('a client admin whose role lacks the delete permission cannot delete a task, even though they can create/edit one', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $restrictedAdmin = clientAdminWithoutDeletePermission($tenant);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $task = Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);

    $component = Livewire::actingAs($restrictedAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad');

    // Sanity check: this admin genuinely has update rights (can create a
    // task), proving the denial below is specifically about "delete", not
    // a broader authorization failure.
    $component->call('createTask')
        ->set('taskTitle', 'Proves update rights exist')
        ->call('saveTask')
        ->assertHasNoErrors();

    $component->call('confirmDeleteTask', $task->id)->call('deleteTaskConfirmed');

    expect(Task::find($task->id))->not->toBeNull();
});

test('an assigned client user can delete their own assigned task', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id]);
    $task = Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'assigned_to' => $rep->id]);

    Livewire::actingAs($rep)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('confirmDeleteTask', $task->id)
        ->call('deleteTaskConfirmed');

    expect(Task::find($task->id))->toBeNull();
});

test('a non-admin cannot delete a task assigned to a different user', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $otherRep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id]);
    $task = Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'assigned_to' => $otherRep->id]);

    Livewire::actingAs($rep)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('confirmDeleteTask', $task->id)
        ->call('deleteTaskConfirmed');

    expect(Task::find($task->id))->not->toBeNull();
});

test('a non-admin cannot delete an unassigned task either', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id]);
    $task = Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'assigned_to' => null]);

    Livewire::actingAs($rep)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('confirmDeleteTask', $task->id)
        ->call('deleteTaskConfirmed');

    expect(Task::find($task->id))->not->toBeNull();
});

test('deletion still requires the base lead update permission — cannot bypass it just by owning the task', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    // A rep with only "view" is technically the task's assignee, but has no
    // update rights on the lead at all — deletion must still be denied.
    $rep = clientUserWithLeadPermission($tenant, ['view']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id]);
    $task = Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'assigned_to' => $rep->id]);

    Livewire::actingAs($rep)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('confirmDeleteTask', $task->id)
        ->call('deleteTaskConfirmed');

    expect(Task::find($task->id))->not->toBeNull();
});

test('deleting a task cascades its status log rows', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $task = Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('changeTaskStatus', $task->id, 'completed');

    expect($task->statusLogs()->count())->toBe(1);

    $task->delete();

    expect(TaskStatusLog::where('task_id', $task->id)->count())->toBe(0);
});

test('deleting the assigned/creating user nulls out the task\'s assigned_to/created_by, without deleting the task', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $assignee = clientUserWithLeadPermission($tenant);
    $creator = clientUserWithLeadPermission($tenant);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $task = Task::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'assigned_to' => $assignee->id,
        'created_by' => $creator->id,
    ]);

    $assignee->delete();
    $creator->delete();

    $task->refresh();
    expect($task)->not->toBeNull();
    expect($task->assigned_to)->toBeNull();
    expect($task->created_by)->toBeNull();
});

// --- Tenant isolation ------------------------------------------------------

test('a task id belonging to another lead cannot be edited through a different lead\'s page', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $leadA = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $leadB = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $taskOnB = Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $leadB->id]);

    $component = Livewire::actingAs($clientAdmin)->test(Show::class, ['lead' => $leadA])->call('completeInitialLoad');

    expect(fn () => $component->call('editTask', $taskOnB->id))->toThrow(ModelNotFoundException::class);
});

test('a task belonging to another tenant can never be accessed even with a known task id', function () {
    ['tenant' => $tenantA, 'clientAdmin' => $adminA] = tenantWithLeadManagement();
    ['tenant' => $tenantB] = tenantWithLeadManagement();
    $leadA = Lead::factory()->create(['tenant_id' => $tenantA->id]);
    $leadB = Lead::factory()->create(['tenant_id' => $tenantB->id]);
    $foreignTask = Task::factory()->create(['tenant_id' => $tenantB->id, 'lead_id' => $leadB->id]);

    $component = Livewire::actingAs($adminA)->test(Show::class, ['lead' => $leadA])->call('completeInitialLoad');

    expect(fn () => $component->call('editTask', $foreignTask->id))->toThrow(ModelNotFoundException::class);
    expect(fn () => $component->call('changeTaskStatus', $foreignTask->id, 'completed'))->toThrow(ModelNotFoundException::class);
    expect(fn () => $component->call('confirmDeleteTask', $foreignTask->id)->call('deleteTaskConfirmed'))
        ->toThrow(ModelNotFoundException::class);

    expect(Task::find($foreignTask->id))->not->toBeNull();
});

// --- Ownership ---------------------------------------------------------

test('a new task defaults its assignee to the lead\'s current assignee', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('createTask')
        ->set('taskTitle', 'Assignee Default Test')
        ->call('saveTask');

    $task = Task::where('lead_id', $lead->id)->firstOrFail();
    expect($task->assigned_to)->toBe($rep->id);
});

test('reassigning the lead does not overwrite an already-existing task\'s assignee', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $otherRep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id]);
    $task = Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'assigned_to' => $rep->id]);

    app(LeadAssignmentService::class)->assign($lead, $otherRep, $clientAdmin);

    expect($task->fresh()->assigned_to)->toBe($rep->id);
});
