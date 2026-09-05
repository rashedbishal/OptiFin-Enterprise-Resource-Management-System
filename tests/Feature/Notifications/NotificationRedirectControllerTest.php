<?php

use App\Models\Lead;
use App\Notifications\NewLeadNotification;

test('opening a notification marks it read and redirects to its lead in a single request', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $clientAdmin->notify(new NewLeadNotification($lead));
    $notification = $clientAdmin->notifications()->first();

    expect($notification->read_at)->toBeNull();

    $this->actingAs($clientAdmin)
        ->get(route('tenant-notifications.open', $notification->id))
        ->assertRedirect(route('tenant-leads.show', $lead->id));

    // The whole point of routing through this controller instead of a
    // separate wire:click.renderless="markAsRead(...)" alongside
    // wire:navigate: mark-as-read and the redirect are one atomic request,
    // so there's no window where the client can navigate away before the
    // read state is persisted.
    expect($notification->fresh()->read_at)->not->toBeNull();
});

test('opening a notification for a since-deleted lead still marks it read and falls back to the dashboard', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $clientAdmin->notify(new NewLeadNotification($lead));
    $notification = $clientAdmin->notifications()->first();
    $lead->delete();

    $this->actingAs($clientAdmin)
        ->get(route('tenant-notifications.open', $notification->id))
        ->assertRedirect(route('dashboard'));

    expect($notification->fresh()->read_at)->not->toBeNull();
});

test('a tenant user cannot open another user\'s notification', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $clientAdmin->notify(new NewLeadNotification($lead));
    $notification = $clientAdmin->notifications()->first();

    $this->actingAs($rep)
        ->get(route('tenant-notifications.open', $notification->id))
        ->assertNotFound();

    expect($notification->fresh()->read_at)->toBeNull();
});
