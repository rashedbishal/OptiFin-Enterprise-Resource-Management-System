<?php

use App\Livewire\Tenant\NotificationBell;
use App\Models\Lead;
use App\Notifications\FollowUpAlertNotification;
use App\Notifications\FollowUpCancelledNotification;
use App\Notifications\FollowUpUpdatedNotification;
use App\Notifications\LeadAssignedNotification;
use App\Notifications\MeetingAlertNotification;
use App\Notifications\MeetingCancelledNotification;
use App\Notifications\MeetingUpdatedNotification;
use App\Notifications\NewLeadNotification;
use Livewire\Livewire;

test('the bell appears on a tenant page load', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $this->actingAs($clientAdmin)
        ->get(route('tenant-leads.index'))
        ->assertOk()
        ->assertSeeLivewire(NotificationBell::class);
});

test('the bell mounts exactly once per page, not once for desktop and once for mobile', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $response = $this->actingAs($clientAdmin)->get(route('tenant-leads.index'));

    $response->assertOk();

    // Two live instances would each run their own unread-count query and
    // hold their own Echo subscription for a bell only one of which is
    // ever visible at a given viewport — counting the button's own
    // aria-label (rendered once per instance) catches a regression back to
    // that even if the surrounding layout markup changes.
    expect(substr_count($response->getContent(), 'aria-label="Notifications"'))->toBe(1);
});

test('the mobile notification panel stays within padded viewport bounds', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $response = $this->actingAs($clientAdmin)->get(route('tenant-leads.index'));

    $response->assertOk()
        ->assertSee('inset-x-4 top-14 max-h-[min(32rem,calc(100dvh-6rem))]', false)
        ->assertSee('flex-1 min-h-0 overflow-y-auto sm:max-h-80', false);
});

test('a client user (sales rep) also sees the bell on their page load', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant);

    $this->actingAs($rep)
        ->get(route('tenant-leads.index'))
        ->assertOk()
        ->assertSeeLivewire(NotificationBell::class);
});

test('the bell listens for real-time notifications on its own private channel', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $listeners = Livewire::actingAs($clientAdmin)->test(NotificationBell::class)->call('openNotifications')->instance()->getListeners();

    expect($listeners)->toHaveKey('echo-private:tenant-user.'.$clientAdmin->id.',.notification.created', 'refresh');
});

test('the bell is a global layout element, not scoped to the Leads pages', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $this->actingAs($clientAdmin)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeLivewire(NotificationBell::class);
});

test('the bell shows the unread count and marks a notification as read without navigating away', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Jane Doe']);
    $clientAdmin->notify(new NewLeadNotification($lead));

    $component = Livewire::actingAs($clientAdmin)->test(NotificationBell::class)->call('openNotifications');

    expect($component->get('unreadCount'))->toBe(1);
    $notification = $component->get('notifications')[0];
    expect($notification['read'])->toBeFalse();
    expect($notification['title'])->toBe('New lead received');
    expect($notification['url'])->toBe(route('tenant-notifications.open', $notification['id']));

    $component->call('markAsRead', $notification['id']);

    expect($component->get('unreadCount'))->toBe(0);
    expect($component->get('notifications')[0]['read'])->toBeTrue();
});

test('a client user\'s notification redirects to the Kanban board with the lead panel query param, not the admin\'s full page', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id]);
    $rep->notify(new LeadAssignedNotification($lead, null));

    $notification = Livewire::actingAs($rep)->test(NotificationBell::class)->call('openNotifications')->get('notifications')[0];

    expect($notification['url'])->toBe(route('tenant-notifications.open', $notification['id']));

    $this->actingAs($rep)
        ->get($notification['url'])
        ->assertRedirect(route('tenant-leads.index', ['lead' => $lead->id]));
});

test('a client admin\'s notification still redirects to the full lead page, not the Kanban board', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $clientAdmin->notify(new NewLeadNotification($lead));

    $notification = Livewire::actingAs($clientAdmin)->test(NotificationBell::class)->call('openNotifications')->get('notifications')[0];

    $this->actingAs($clientAdmin)
        ->get($notification['url'])
        ->assertRedirect(route('tenant-leads.show', $lead->id));
});

test('mark all as read clears the unread count for every notification', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $leadOne = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $leadTwo = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $clientAdmin->notify(new NewLeadNotification($leadOne));
    $clientAdmin->notify(new NewLeadNotification($leadTwo));

    $component = Livewire::actingAs($clientAdmin)->test(NotificationBell::class)->call('openNotifications');
    expect($component->get('unreadCount'))->toBe(2);

    $component->call('markAllAsRead');

    expect($component->get('unreadCount'))->toBe(0);
    expect(collect($component->get('notifications'))->every(fn (array $n): bool => $n['read']))->toBeTrue();
});

test('clear all deletes every notification for the current user only', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $clientAdmin->notify(new NewLeadNotification($lead));
    $rep->notify(new LeadAssignedNotification($lead, $clientAdmin));

    Livewire::actingAs($clientAdmin)->test(NotificationBell::class)->call('openNotifications')->call('clearAll');

    expect($clientAdmin->notifications()->count())->toBe(0);
    expect($rep->notifications()->count())->toBe(1);
});

test('clearing all goes through the confirm modal', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $clientAdmin->notify(new NewLeadNotification($lead));

    Livewire::actingAs($clientAdmin)
        ->test(NotificationBell::class)->call('openNotifications')
        ->call('confirmClearAll')
        ->assertSet('showClearAllModal', true)
        ->call('clearAllConfirmed')
        ->assertSet('showClearAllModal', false);

    expect($clientAdmin->notifications()->count())->toBe(0);
});

test('a notification for a since-deleted lead renders without a link', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $clientAdmin->notify(new NewLeadNotification($lead));
    $lead->delete();

    $notification = Livewire::actingAs($clientAdmin)->test(NotificationBell::class)->call('openNotifications')->get('notifications')[0];

    expect($notification['url'])->toBeNull();
});

test('each notification type renders in the open panel with its own icon and message', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Jane Doe', 'assigned_to' => $rep->id]);
    $lead->update(['meeting_date' => now()->addDay(), 'next_follow_up_at' => now()->addDay()]);

    $rep->notify(new LeadAssignedNotification($lead, $clientAdmin));
    $rep->notify(new MeetingAlertNotification($lead));
    $rep->notify(new FollowUpAlertNotification($lead));
    $rep->notify(new MeetingCancelledNotification($lead, $clientAdmin));
    $rep->notify(new FollowUpCancelledNotification($lead, $clientAdmin));
    $rep->notify(new MeetingUpdatedNotification($lead, $clientAdmin, rescheduled: false));
    $rep->notify(new FollowUpUpdatedNotification($lead, $clientAdmin, rescheduled: true));

    $component = Livewire::actingAs($rep)->test(NotificationBell::class)->call('openNotifications');

    expect(collect($component->get('notifications'))->pluck('title'))->toContain(
        __(':assignedBy assigned you a lead', ['assignedBy' => $clientAdmin->name]),
        __('Meeting reminder'),
        __('Follow-up reminder'),
        __('Meeting cancelled'),
        __('Follow-up cancelled'),
        __('Meeting scheduled'),
        __('Follow-up rescheduled'),
    );

    // Forces a full render of the open panel (icons, empty state, per-row
    // markup) rather than just the computed data above, so an invalid Flux
    // icon name or a Blade error in that branch would actually surface here.
    $component->call('openNotifications')
        ->assertOk()
        ->assertSee(__(':assignedBy assigned you a lead', ['assignedBy' => $clientAdmin->name]))
        ->assertSee(__('Meeting reminder'))
        ->assertSee(__('Follow-up reminder'))
        ->assertSee(__('Meeting cancelled'))
        ->assertSee(__('Meeting scheduled'))
        ->assertSee(__('Follow-up rescheduled'))
        ->assertSee(__('Follow-up cancelled'));
});

test('a meeting scheduled/rescheduled notification includes the time, not just the date', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Jane Doe',
        'meeting_date' => '2026-09-10',
        'meeting_time' => '14:30:00',
    ]);

    $clientAdmin->notify(new MeetingUpdatedNotification($lead, $clientAdmin, rescheduled: false));

    $component = Livewire::actingAs($clientAdmin)->test(NotificationBell::class)->call('openNotifications');
    $notification = $component->get('notifications')[0];

    expect($notification['message'])->toContain('2:30 PM');

    $component->call('openNotifications')->assertOk()->assertSee('2:30 PM');
});

test('a follow-up scheduled notification has no time component to show', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Jane Doe', 'next_follow_up_at' => '2026-09-10']);

    $clientAdmin->notify(new FollowUpUpdatedNotification($lead, $clientAdmin, rescheduled: false));

    $message = Livewire::actingAs($clientAdmin)->test(NotificationBell::class)->call('openNotifications')->get('notifications')[0]['message'];

    expect($message)->not->toContain('at ');
});

test('the empty state renders when there are no notifications', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)
        ->test(NotificationBell::class)
        ->call('openNotifications')
        ->assertOk()
        ->assertSee(__('No notifications yet.'));
});
