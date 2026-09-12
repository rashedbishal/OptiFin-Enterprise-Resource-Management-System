<?php

use App\Enums\TenantStatus;
use App\Models\Lead;
use App\Models\Task;
use App\Notifications\TaskAlertNotification;

test('a suspended tenant is skipped entirely, even with alerts enabled and a due task', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['task_alert_enabled' => true, 'task_alert_days_before' => 2, 'status' => TenantStatus::Suspended]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'due_date' => now()->addDays(2)->toDateString()]);

    $this->artisan('tasks:send-alert-notifications')->assertSuccessful();

    expect($clientAdmin->notifications()->count())->toBe(0);
});

test('a client admin is notified once a task\'s due-date alert window opens', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['task_alert_enabled' => true, 'task_alert_days_before' => 2]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'due_date' => now()->addDays(2)->toDateString()]);

    $this->artisan('tasks:send-alert-notifications')->assertSuccessful();

    expect($clientAdmin->notifications()->where('type', TaskAlertNotification::class)->count())->toBe(1);
});

test('a task outside its alert window does not notify anyone', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['task_alert_enabled' => true, 'task_alert_days_before' => 2]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'due_date' => now()->addDays(10)->toDateString()]);

    $this->artisan('tasks:send-alert-notifications');

    expect($clientAdmin->notifications()->count())->toBe(0);
});

test('a disabled task alert setting sends nothing even for a due task', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['task_alert_enabled' => false, 'task_alert_days_before' => 2]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'due_date' => now()->toDateString()]);

    $this->artisan('tasks:send-alert-notifications');

    expect($clientAdmin->notifications()->count())->toBe(0);
});

test('only the task\'s own assignee and admins are notified, not the lead\'s unrelated assignee', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['task_alert_enabled' => true, 'task_alert_days_before' => 1]);
    $leadAssignee = clientUserWithLeadPermission($tenant);
    $taskAssignee = clientUserWithLeadPermission($tenant);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $leadAssignee->id]);
    Task::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'due_date' => now()->addDay()->toDateString(),
        'assigned_to' => $taskAssignee->id,
    ]);

    $this->artisan('tasks:send-alert-notifications');

    expect($clientAdmin->notifications()->where('type', TaskAlertNotification::class)->count())->toBe(1);
    expect($taskAssignee->notifications()->where('type', TaskAlertNotification::class)->count())->toBe(1);
    expect($leadAssignee->notifications()->count())->toBe(0);
});

test('running the sweep twice in the same day does not duplicate a notification', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['task_alert_enabled' => true, 'task_alert_days_before' => 0]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'due_date' => now()->toDateString()]);

    $this->artisan('tasks:send-alert-notifications');
    $this->artisan('tasks:send-alert-notifications');

    expect($clientAdmin->notifications()->where('type', TaskAlertNotification::class)->count())->toBe(1);
});

test('a task with no due date is never alerted on', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['task_alert_enabled' => true, 'task_alert_days_before' => 5]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'due_date' => null]);

    $this->artisan('tasks:send-alert-notifications');

    expect($clientAdmin->notifications()->count())->toBe(0);
});

test('a completed task is never alerted on even with a past-due date', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['task_alert_enabled' => true, 'task_alert_days_before' => 5]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    Task::factory()->completed()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'due_date' => now()->subDays(3)->toDateString()]);

    $this->artisan('tasks:send-alert-notifications');

    expect($clientAdmin->notifications()->count())->toBe(0);
});

test('an overdue pending task keeps alerting, not just on the exact due day', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['task_alert_enabled' => true, 'task_alert_days_before' => 0]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'due_date' => now()->subDays(3)->toDateString()]);

    $this->artisan('tasks:send-alert-notifications');

    expect($clientAdmin->notifications()->where('type', TaskAlertNotification::class)->count())->toBe(1);
});
