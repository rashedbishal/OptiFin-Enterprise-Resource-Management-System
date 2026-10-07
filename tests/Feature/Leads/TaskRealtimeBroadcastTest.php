<?php

use App\Enums\LeadPriority;
use App\Enums\TaskStatus;
use App\Events\LeadUpdated;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Lead;
use App\Models\Task;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

/*
 * Task actions previously never broadcast anything — someone else already
 * viewing the same lead (another tab, another user) never saw a task
 * appear/change/disappear without reloading. This reuses the lead's own
 * existing lead.{lead} channel/LeadUpdated event/refreshLead() listener
 * (same convention as LeadStatusAssignmentMergedFormTest's dispatch
 * assertions and LeadShowListenerTest's refreshLead() assertions) rather
 * than inventing a Task-specific channel or event.
 */

test('saving a new task broadcasts LeadUpdated for its lead', function () {
    Event::fake([LeadUpdated::class]);
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $clientAdmin->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('createTask')
        ->set('taskTitle', 'Send proposal')
        ->set('taskPriority', LeadPriority::Medium->value)
        ->call('saveTask')
        ->assertHasNoErrors();

    Event::assertDispatched(LeadUpdated::class, fn (LeadUpdated $event): bool => $event->lead->is($lead));
});

test('changing a task\'s status broadcasts LeadUpdated for its lead', function () {
    Event::fake([LeadUpdated::class]);
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $clientAdmin->id]);
    $task = Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('changeTaskStatus', $task->id, 'completed');

    Event::assertDispatched(LeadUpdated::class, fn (LeadUpdated $event): bool => $event->lead->is($lead));
});

test('deleting a task broadcasts LeadUpdated for its lead', function () {
    Event::fake([LeadUpdated::class]);
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $clientAdmin->id]);
    $task = Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('confirmDeleteTask', $task->id)
        ->call('deleteTaskConfirmed');

    Event::assertDispatched(LeadUpdated::class, fn (LeadUpdated $event): bool => $event->lead->is($lead));
});

test('refreshLead (the echo-triggered handler) picks up a task someone else just created on this lead', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $clientAdmin->id]);

    $component = Livewire::actingAs($clientAdmin)->test(Show::class, ['lead' => $lead])->call('completeInitialLoad');

    // Simulates another user's browser creating the task directly — this
    // component's own `tasks` computed property was already memoized
    // without it, same setup as LeadShowListenerTest's follow-up-message
    // scenario.
    Task::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'status' => TaskStatus::Pending,
        'title' => 'Created by someone else',
    ]);

    $component->call('refreshLead')
        ->assertSee('Created by someone else');
});
