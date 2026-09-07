<?php

use App\Enums\LeadSourceType;
use App\Events\LeadUpdated;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Lead;
use App\Models\LeadDetailUpdateLog;
use App\Models\LeadSource;
use App\Models\TenantUser;
use App\Notifications\FollowUpCancelledNotification;
use App\Notifications\MeetingCancelledNotification;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

test('the cancel meeting modal confirms before the date is actually cleared', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => today()->addDays(3)]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead, 'panel' => true])->call('completeInitialLoad')
        ->call('confirmCancelMeeting')
        ->assertSet('showCancelMeetingModal', true);

    expect($lead->fresh()->meeting_date)->not->toBeNull();

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead, 'panel' => true])->call('completeInitialLoad')
        ->call('confirmCancelMeeting')
        ->call('cancelMeetingConfirmed')
        ->assertSet('showCancelMeetingModal', false);

    expect($lead->fresh()->meeting_date)->toBeNull();
});

test('the cancel follow-up modal confirms before the date is actually cleared', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'next_follow_up_at' => today()->addDays(3)]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead, 'panel' => true])->call('completeInitialLoad')
        ->call('confirmCancelFollowUp')
        ->assertSet('showCancelFollowUpModal', true);

    expect($lead->fresh()->next_follow_up_at)->not->toBeNull();

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead, 'panel' => true])->call('completeInitialLoad')
        ->call('confirmCancelFollowUp')
        ->call('cancelFollowUpConfirmed')
        ->assertSet('showCancelFollowUpModal', false);

    expect($lead->fresh()->next_follow_up_at)->toBeNull();
});

test('cancelling a meeting clears the date and time and logs a friendly activity entry', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => today()->addDays(3), 'meeting_time' => '14:30']);
    $oldDate = $lead->meeting_date;

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead, 'panel' => true])->call('completeInitialLoad')
        ->call('cancelMeeting')
        ->assertSet('leadMeetingDate', '');

    expect($lead->fresh()->meeting_date)->toBeNull();
    expect($lead->fresh()->meeting_time)->toBeNull();

    $log = LeadDetailUpdateLog::where('lead_id', $lead->id)->where('action', 'meeting_cancelled')->firstOrFail();
    expect($log->action_date->isSameDay($oldDate))->toBeTrue();
});

test('marking a meeting done clears the date and time, and logs it separately from cancelling', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => today(), 'meeting_time' => '09:00']);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead, 'panel' => true])->call('completeInitialLoad')
        ->call('markMeetingDone');

    expect($lead->fresh()->meeting_date)->toBeNull();
    expect($lead->fresh()->meeting_time)->toBeNull();
    expect(LeadDetailUpdateLog::where('lead_id', $lead->id)->where('action', 'meeting_done')->exists())->toBeTrue();
});

test('marking a meeting done is rejected server-side before the scheduled day, even if called directly', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => today()->addDays(2)]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead, 'panel' => true])->call('completeInitialLoad')
        ->call('markMeetingDone');

    expect($lead->fresh()->meeting_date)->not->toBeNull();
    expect(LeadDetailUpdateLog::where('lead_id', $lead->id)->where('action', 'meeting_done')->exists())->toBeFalse();
});

test('marking a meeting done is allowed once it is overdue', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => today()->subWeek()]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead, 'panel' => true])->call('completeInitialLoad')
        ->call('markMeetingDone');

    expect($lead->fresh()->meeting_date)->toBeNull();
});

test('cancelling or completing a meeting broadcasts a lead update so the shimmer stops live everywhere', function () {
    Event::fake([LeadUpdated::class]);
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => today()]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead, 'panel' => true])->call('completeInitialLoad')
        ->call('cancelMeeting');

    Event::assertDispatched(LeadUpdated::class, fn (LeadUpdated $event): bool => $event->lead->is($lead));
});

test('the follow-up cancel/done actions mirror the meeting ones against next_follow_up_at', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'next_follow_up_at' => today()]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead, 'panel' => true])->call('completeInitialLoad')
        ->call('markFollowUpDone')
        ->assertSet('leadNextFollowUpAt', '');

    expect($lead->fresh()->next_follow_up_at)->toBeNull();
    expect(LeadDetailUpdateLog::where('lead_id', $lead->id)->where('action', 'followup_done')->exists())->toBeTrue();
});

test('cancelling a meeting notifies every other client admin, but not the admin who cancelled it', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $otherAdmin = TenantUser::factory()->clientAdmin()->create(['tenant_id' => $tenant->id]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => today()]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead, 'panel' => true])->call('completeInitialLoad')
        ->call('cancelMeeting');

    expect($clientAdmin->notifications()->where('type', MeetingCancelledNotification::class)->count())->toBe(0);
    expect($otherAdmin->notifications()->where('type', MeetingCancelledNotification::class)->count())->toBe(1);
});

test('the assigned rep cancelling a meeting notifies the client admin (never themselves)', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => today(), 'assigned_to' => $rep->id]);

    Livewire::actingAs($rep)
        ->test(Show::class, ['lead' => $lead, 'panel' => true])->call('completeInitialLoad')
        ->call('cancelMeeting');

    expect($clientAdmin->notifications()->where('type', MeetingCancelledNotification::class)->count())->toBe(1);
    expect($rep->notifications()->where('type', MeetingCancelledNotification::class)->count())->toBe(0);
});

test('cancelling a follow-up mirrors the meeting notification behavior', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $otherAdmin = TenantUser::factory()->clientAdmin()->create(['tenant_id' => $tenant->id]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'next_follow_up_at' => today()]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead, 'panel' => true])->call('completeInitialLoad')
        ->call('cancelFollowUp');

    expect($clientAdmin->notifications()->where('type', FollowUpCancelledNotification::class)->count())->toBe(0);
    expect($otherAdmin->notifications()->where('type', FollowUpCancelledNotification::class)->count())->toBe(1);
});

test('marking a meeting or follow-up done sends no cancellation notification', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $otherAdmin = TenantUser::factory()->clientAdmin()->create(['tenant_id' => $tenant->id]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => today(), 'next_follow_up_at' => today()]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead, 'panel' => true])->call('completeInitialLoad')
        ->call('markMeetingDone')
        ->call('markFollowUpDone');

    expect($otherAdmin->notifications()->count())->toBe(0);
});

test('the full page (not just the flyout) can cancel a meeting for a non-manual lead, confirm modal included', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => today()->addDays(3), 'meeting_time' => '14:30']);
    expect($lead->source->type->value)->not->toBe('manual');

    $component = Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->assertSeeHtml('wire:click="confirmCancelMeeting"')
        ->call('confirmCancelMeeting')
        ->assertSet('showCancelMeetingModal', true)
        ->call('cancelMeetingConfirmed')
        ->assertSet('showCancelMeetingModal', false);

    expect($lead->fresh()->meeting_date)->toBeNull();

    $component->set('activeTab', 'activity');
    $component->assertSee('Meeting scheduled for')->assertSee('was cancelled');
});

test('the full page can mark a meeting done for a non-manual lead', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => today()]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->assertSeeHtml('wire:click="markMeetingDone"')
        ->call('markMeetingDone');

    expect($lead->fresh()->meeting_date)->toBeNull();
});

test('the full page can cancel a follow-up for a non-manual lead, confirm modal included', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'next_follow_up_at' => today()->addDays(3)]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->assertSeeHtml('wire:click="confirmCancelFollowUp"')
        ->call('confirmCancelFollowUp')
        ->assertSet('showCancelFollowUpModal', true)
        ->call('cancelFollowUpConfirmed')
        ->assertSet('showCancelFollowUpModal', false);

    expect($lead->fresh()->next_follow_up_at)->toBeNull();
});

test('the full page hides Cancel/Mark done meeting controls from a viewer without update permission', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => today(), 'assigned_to' => $rep->id]);

    Livewire::actingAs($rep)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->assertDontSeeHtml('wire:click="confirmCancelMeeting"')
        ->assertDontSeeHtml('wire:click="markMeetingDone"');
});

test('a manual lead\'s Deal card offers Cancel meeting/follow-up without needing to click Edit first', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $manualSource = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Manual]);
    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'source_id' => $manualSource->id,
        'meeting_date' => today()->addDays(3),
        'next_follow_up_at' => today()->addDays(3),
    ]);

    $component = Livewire::actingAs($clientAdmin)->test(Show::class, ['lead' => $lead])->call('completeInitialLoad');

    // Read-only by default (never clicked Edit) — the buttons must already
    // be there, not gated behind entering edit mode.
    $component->assertDontSeeHtml('wire:submit="updateDeal"')
        ->assertSeeHtml('wire:click="confirmCancelMeeting"')
        ->assertSeeHtml('wire:click="confirmCancelFollowUp"');

    $component->call('confirmCancelMeeting')->call('cancelMeetingConfirmed');
    expect($lead->fresh()->meeting_date)->toBeNull();

    $component->call('confirmCancelFollowUp')->call('cancelFollowUpConfirmed');
    expect($lead->fresh()->next_follow_up_at)->toBeNull();
});

test('a user without update permission cannot cancel or complete a meeting', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view']);
    // Assigned to $rep so the 'view' Gate (needed just to mount the
    // component) passes — the permission under test is 'update', which
    // this rep deliberately lacks regardless of assignment.
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => today(), 'assigned_to' => $rep->id]);

    Livewire::actingAs($rep)
        ->test(Show::class, ['lead' => $lead, 'panel' => true])->call('completeInitialLoad')
        ->call('cancelMeeting');

    expect($lead->fresh()->meeting_date)->not->toBeNull();
});
