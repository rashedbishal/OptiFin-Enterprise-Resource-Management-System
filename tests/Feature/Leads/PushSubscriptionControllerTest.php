<?php

use NotificationChannels\WebPush\PushSubscription;

/*
 * WhatsApp inbound calling — registers/unregisters this browser's Web Push
 * subscription for the logged-in TenantUser (resources/js/softphone/push.js).
 */

test('a tenant user can register a push subscription', function () {
    ['clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $this->actingAs($clientAdmin)
        ->postJson(route('tenant.push-subscriptions.store'), [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
            'keys' => ['p256dh' => 'p256dh-value', 'auth' => 'auth-value'],
        ])
        ->assertOk();

    $subscription = PushSubscription::where('endpoint', 'https://fcm.googleapis.com/fcm/send/abc123')->first();
    expect($subscription)->not->toBeNull();
    expect($subscription->subscribable_id)->toBe($clientAdmin->id);
    expect($subscription->subscribable_type)->toBe($clientAdmin->getMorphClass());
    expect($subscription->public_key)->toBe('p256dh-value');
    expect($subscription->auth_token)->toBe('auth-value');
});

test('registering the same endpoint twice updates it rather than duplicating it', function () {
    ['clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $payload = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123', 'keys' => ['p256dh' => 'first', 'auth' => 'auth-value']];
    $this->actingAs($clientAdmin)->postJson(route('tenant.push-subscriptions.store'), $payload)->assertOk();

    $payload['keys']['p256dh'] = 'second';
    $this->actingAs($clientAdmin)->postJson(route('tenant.push-subscriptions.store'), $payload)->assertOk();

    expect(PushSubscription::where('endpoint', 'https://fcm.googleapis.com/fcm/send/abc123')->count())->toBe(1);
    expect(PushSubscription::where('endpoint', 'https://fcm.googleapis.com/fcm/send/abc123')->first()->public_key)->toBe('second');
});

test('endpoint and keys are required', function () {
    ['clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $this->actingAs($clientAdmin)
        ->postJson(route('tenant.push-subscriptions.store'), [])
        ->assertJsonValidationErrors(['endpoint', 'keys.p256dh', 'keys.auth']);
});

test('a tenant user can remove their own push subscription', function () {
    ['clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $clientAdmin->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc123', 'p256dh-value', 'auth-value');

    $this->actingAs($clientAdmin)
        ->deleteJson(route('tenant.push-subscriptions.destroy'), ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123'])
        ->assertOk();

    expect(PushSubscription::where('endpoint', 'https://fcm.googleapis.com/fcm/send/abc123')->exists())->toBeFalse();
});

test('a user cannot remove another user\'s subscription by guessing their endpoint', function () {
    ['clientAdmin' => $ownerAdmin] = tenantWithLeadManagement();
    ['clientAdmin' => $otherAdmin] = tenantWithLeadManagement();

    $ownerAdmin->updatePushSubscription('https://fcm.googleapis.com/fcm/send/owned-by-owner', 'p256dh-value', 'auth-value');

    $this->actingAs($otherAdmin)
        ->deleteJson(route('tenant.push-subscriptions.destroy'), ['endpoint' => 'https://fcm.googleapis.com/fcm/send/owned-by-owner'])
        ->assertOk();

    // deletePushSubscription() only ever deletes from $this->pushSubscriptions()
    // (a MorphMany scoped to the acting user), so another user's row survives.
    expect(PushSubscription::where('endpoint', 'https://fcm.googleapis.com/fcm/send/owned-by-owner')->exists())->toBeTrue();
});

test('guests are redirected to login', function () {
    $this->postJson(route('tenant.push-subscriptions.store'), [])->assertUnauthorized();
});
