<?php

use App\Enums\TaskStatus;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Lead;
use App\Models\Task;
use Livewire\Livewire;

/*
 * Task Due-Date Alerts previously had no visible sign on the lead detail
 * page at all — only a once-a-day bell notification. This is the live
 * counterpart on the Tasks card: the same border-shimmer treatment as the
 * Kanban card's meeting/follow-up alert (.lead-alert-shimmer, reused rather
 * than duplicated — see LeadAlertShimmerRenderTest's identical convention),
 * driven by the same Task::alertActive() the daily sweep uses.
 */

test('a pending task inside the tenant\'s due-date alert window shows the shimmer class', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['task_alert_enabled' => true, 'task_alert_days_before' => 3]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $clientAdmin->id]);
    Task::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'status' => TaskStatus::Pending,
        'due_date' => today()->addDay(),
    ]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->assertSeeHtml('lead-alert-shimmer');
});

test('a pending task outside the due-date alert window shows no shimmer', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['task_alert_enabled' => true, 'task_alert_days_before' => 3]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $clientAdmin->id]);
    Task::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'status' => TaskStatus::Pending,
        'due_date' => today()->addDays(10),
    ]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->assertDontSeeHtml('lead-alert-shimmer');
});

test('a task in the alert window shows no shimmer while the tenant setting is disabled', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $clientAdmin->id]);
    Task::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'status' => TaskStatus::Pending,
        'due_date' => today(),
    ]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->assertDontSeeHtml('lead-alert-shimmer');
});

test('a completed task in the alert window shows no shimmer', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['task_alert_enabled' => true, 'task_alert_days_before' => 3]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $clientAdmin->id]);
    Task::factory()->completed()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'due_date' => today(),
    ]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->assertDontSeeHtml('lead-alert-shimmer');
});
