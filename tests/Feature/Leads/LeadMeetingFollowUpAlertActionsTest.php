<?php

use App\Events\LeadUpdated;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Lead;
use App\Models\LeadDetailUpdateLog;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

test('cancelling a meeting clears the date and logs a friendly activity entry', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => today()->addDays(3)]);
    $oldDate = $lead->meeting_date;

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead, 'panel' => true])
        ->call('cancelMeeting')
        ->assertSet('leadMeetingDate', '');

    expect($lead->fresh()->meeting_date)->toBeNull();

    $log = LeadDetailUpdateLog::where('lead_id', $lead->id)->where('action', 'meeting_cancelled')->firstOrFail();
    expect($log->action_date->isSameDay($oldDate))->toBeTrue();
});

test('marking a meeting done clears the date and logs it separately from cancelling', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => today()]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead, 'panel' => true])
        ->call('markMeetingDone');

    expect($lead->fresh()->meeting_date)->toBeNull();
    expect(LeadDetailUpdateLog::where('lead_id', $lead->id)->where('action', 'meeting_done')->exists())->toBeTrue();
});

test('marking a meeting done is rejected server-side before the scheduled day, even if called directly', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => today()->addDays(2)]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead, 'panel' => true])
        ->call('markMeetingDone');

    expect($lead->fresh()->meeting_date)->not->toBeNull();
    expect(LeadDetailUpdateLog::where('lead_id', $lead->id)->where('action', 'meeting_done')->exists())->toBeFalse();
});

test('marking a meeting done is allowed once it is overdue', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => today()->subWeek()]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead, 'panel' => true])
        ->call('markMeetingDone');

    expect($lead->fresh()->meeting_date)->toBeNull();
});

test('cancelling or completing a meeting broadcasts a lead update so the shimmer stops live everywhere', function () {
    Event::fake([LeadUpdated::class]);
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => today()]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead, 'panel' => true])
        ->call('cancelMeeting');

    Event::assertDispatched(LeadUpdated::class, fn (LeadUpdated $event): bool => $event->lead->is($lead));
});

test('the follow-up cancel/done actions mirror the meeting ones against next_follow_up_at', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'next_follow_up_at' => today()]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead, 'panel' => true])
        ->call('markFollowUpDone')
        ->assertSet('leadNextFollowUpAt', '');

    expect($lead->fresh()->next_follow_up_at)->toBeNull();
    expect(LeadDetailUpdateLog::where('lead_id', $lead->id)->where('action', 'followup_done')->exists())->toBeTrue();
});

test('a user without update permission cannot cancel or complete a meeting', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view']);
    // Assigned to $rep so the 'view' Gate (needed just to mount the
    // component) passes — the permission under test is 'update', which
    // this rep deliberately lacks regardless of assignment.
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => today(), 'assigned_to' => $rep->id]);

    Livewire::actingAs($rep)
        ->test(Show::class, ['lead' => $lead, 'panel' => true])
        ->call('cancelMeeting');

    expect($lead->fresh()->meeting_date)->not->toBeNull();
});
