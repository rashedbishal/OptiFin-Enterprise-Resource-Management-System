<?php

use App\Events\UserNotified;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Lead;
use App\Models\Task;
use App\Notifications\TaskAssignedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

test('creating a task with an assignee notifies that assignee', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    Event::fake([UserNotified::class]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('createTask')
        ->set('taskTitle', 'Call the client')
        ->set('taskAssignedTo', (string) $rep->id)
        ->call('saveTask');

    $notification = $rep->notifications()->where('type', TaskAssignedNotification::class)->firstOrFail();
    expect($notification->data['assigned_by'])->toBe($clientAdmin->name);
    expect($notification->data['name'])->toBe('Call the client');
    expect($notification->data['lead_id'])->toBe($lead->id);

    Event::assertDispatched(UserNotified::class, fn (UserNotified $event): bool => $event->tenantUser->is($rep));
});

test('creating a task with no assignee notifies nobody', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('createTask')
        ->set('taskTitle', 'Unassigned task')
        ->set('taskAssignedTo', '')
        ->call('saveTask');

    $task = Task::where('lead_id', $lead->id)->firstOrFail();
    expect($task->assigned_to)->toBeNull();
    expect(DB::table('notifications')->where('type', TaskAssignedNotification::class)->count())->toBe(0);
});

test('reassigning an existing task notifies the new assignee', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $originalRep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $newRep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $task = Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'assigned_to' => $originalRep->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('editTask', $task->id)
        ->set('taskAssignedTo', (string) $newRep->id)
        ->call('saveTask');

    expect($newRep->notifications()->where('type', TaskAssignedNotification::class)->count())->toBe(1);
    expect($originalRep->notifications()->where('type', TaskAssignedNotification::class)->count())->toBe(0);
});

test('re-saving a task without changing its assignee does not re-notify them', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $task = Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'assigned_to' => $rep->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('editTask', $task->id)
        ->set('taskTitle', 'Updated title, same assignee')
        ->call('saveTask');

    expect($rep->notifications()->where('type', TaskAssignedNotification::class)->count())->toBe(0);
});

test('clearing a task\'s assignee does not send a notification', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $task = Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'assigned_to' => $rep->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('editTask', $task->id)
        ->set('taskAssignedTo', '')
        ->call('saveTask');

    expect($task->fresh()->assigned_to)->toBeNull();
    expect($rep->notifications()->where('type', TaskAssignedNotification::class)->count())->toBe(0);
});
