<?php

use App\Events\UserNotified;
use App\Models\Lead;
use App\Notifications\NewLeadNotification;
use Illuminate\Support\Facades\Event;

test('sending a database notification to a tenant user broadcasts UserNotified on their own channel', function () {
    Event::fake([UserNotified::class]);

    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    $clientAdmin->notify(new NewLeadNotification($lead));

    Event::assertDispatched(UserNotified::class, fn (UserNotified $event): bool => $event->tenantUser->is($clientAdmin));
});

test('a second tenant user is not pinged for a notification sent to someone else', function () {
    Event::fake([UserNotified::class]);

    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    $clientAdmin->notify(new NewLeadNotification($lead));

    Event::assertNotDispatched(UserNotified::class, fn (UserNotified $event): bool => $event->tenantUser->is($rep));
});

test('UserNotified broadcasts privately to the recipient\'s own channel under a short event name', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $event = new UserNotified($clientAdmin);
    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(1);
    expect($channels[0]->name)->toBe('private-tenant-user.'.$clientAdmin->id);
    expect($event->broadcastAs())->toBe('notification.created');
});
