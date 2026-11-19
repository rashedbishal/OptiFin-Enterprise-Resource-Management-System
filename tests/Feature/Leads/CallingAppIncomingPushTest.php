<?php

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Models\CallingAppDevice;
use App\Models\PlatformUser;
use App\Notifications\CallingAppCallPush;
use App\Notifications\IncomingWhatsAppCallNotification;
use App\Services\CallingAppFirebase;
use App\Services\SoftphonePresence;
use App\Support\LeadCallBroadcaster;
use App\Support\LeadCallPushNotifier;
use Illuminate\Support\Facades\Notification;
use Tests\Support\LeadCallScenario as Lc;

/*
 * WhatsApp calling companion app — inbound calls: the app registers its FCM
 * token, a paired app with a token counts as available, a fresh Ringing call
 * pushes `incoming_call` to every routed user's paired phone (alongside the
 * unchanged browser Web Push), and leaving Ringing pushes `call_ended` to the
 * other routed phones so they stop ringing.
 */

/**
 * A syntactically valid service account for the calling app's Firebase
 * project — never used to reach Firebase (Notification::fake / no sends).
 *
 * @return array<string, string>
 */
function ciServiceAccount(string $projectId = 'optifin-calling'): array
{
    return [
        'type' => 'service_account',
        'project_id' => $projectId,
        'private_key_id' => 'test',
        'private_key' => "-----BEGIN PRIVATE KEY-----\nTEST\n-----END PRIVATE KEY-----\n",
        'client_email' => "firebase-adminsdk@{$projectId}.iam.gserviceaccount.com",
        'client_id' => '1',
    ];
}

function ciConfigureFirebase(): void
{
    app(CallingAppFirebase::class)->store(ciServiceAccount(), PlatformUser::factory()->motherAdmin()->create());
}

function ciDevice(Lc $x, string $userKey, ?string $token): CallingAppDevice
{
    return CallingAppDevice::factory()->create(['tenant_user_id' => $x->{$userKey}->id, 'fcm_token' => $token]);
}

test('the app registers its push token on its own paired device', function () {
    $x = Lc::make();
    $device = ciDevice($x, 'agent', null);
    $token = $x->agent->createToken('Calling app', ['calling-app'])->plainTextToken;
    $device->update(['personal_access_token_id' => $x->agent->tokens()->latest('id')->first()->id]);

    $this->withToken($token)->postJson(route('api.calling-app.push-token'), ['token' => 'fcm-token-1'])->assertOk();

    expect($device->fresh()->fcm_token)->toBe('fcm-token-1')
        ->and($device->fresh()->fcm_token_updated_at)->not->toBeNull();
});

test('registering a push token requires the token with 422', function () {
    $x = Lc::make();
    $device = ciDevice($x, 'agent', null);
    $token = $x->agent->createToken('Calling app', ['calling-app'])->plainTextToken;
    $device->update(['personal_access_token_id' => $x->agent->tokens()->latest('id')->first()->id]);

    $this->withToken($token)->postJson(route('api.calling-app.push-token'), [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['token' => 'The token field is required.']);
});

test('a user whose paired app has a push token counts as available even with a stale browser heartbeat', function () {
    ciConfigureFirebase();
    config(['calling.reverb_presence_enabled' => false]);
    $x = Lc::make();
    $x->agent->forceFill(['softphone_last_seen_at' => now()->subDay()])->save();

    expect(app(SoftphonePresence::class)->isAvailable($x->agent->fresh()))->toBeFalse();

    ciDevice($x, 'agent', 'fcm-token-1');

    expect(app(SoftphonePresence::class)->isAvailable($x->agent->fresh()))->toBeTrue();
});

test('a paired app without a push token does not change availability', function () {
    config(['calling.reverb_presence_enabled' => false]);
    $x = Lc::make();
    $x->agent->forceFill(['softphone_last_seen_at' => now()->subDay()])->save();
    ciDevice($x, 'agent', null);

    expect(app(SoftphonePresence::class)->isAvailable($x->agent->fresh()))->toBeFalse();
});

test('a ringing call pushes incoming_call to every routed paired phone, alongside the browser web push', function () {
    ciConfigureFirebase();
    Notification::fake();
    $x = Lc::make();
    $agentPhone = ciDevice($x, 'agent', 'fcm-agent');
    $otherPhone = ciDevice($x, 'other', 'fcm-other');

    LeadCallPushNotifier::ring($x->call);

    foreach ([$agentPhone, $otherPhone] as $phone) {
        Notification::assertSentTo($phone, CallingAppCallPush::class, function (CallingAppCallPush $push) use ($x): bool {
            $message = $push->toFcm($push);

            return $push->type === CallingAppCallPush::INCOMING_CALL
                && $message->data['call_id'] === $x->call->id
                && $message->data['lead_name'] === (string) $x->lead->name
                && (int) $message->data['ring_seconds'] > 0
                && $message->custom['android']['priority'] === 'high';
        });
    }

    Notification::assertSentTo($x->agent, IncomingWhatsAppCallNotification::class);
});

test('a routed user whose phone has no push token gets no app push', function () {
    ciConfigureFirebase();
    Notification::fake();
    $x = Lc::make();
    $phone = ciDevice($x, 'agent', null);

    LeadCallPushNotifier::ring($x->call);

    Notification::assertNotSentTo($phone, CallingAppCallPush::class);
});

test('a phone paired by a user the call was not routed to is never pushed', function () {
    ciConfigureFirebase();
    Notification::fake();
    $x = Lc::make();
    $outsider = clientUserWithLeadPermission($x->tenant, ['view', 'update']);
    $phone = CallingAppDevice::factory()->create(['tenant_user_id' => $outsider->id, 'fcm_token' => 'fcm-outsider']);

    LeadCallPushNotifier::ring($x->call);

    Notification::assertNotSentTo($phone, CallingAppCallPush::class);
});

test('once one agent claims the call, the other routed phones are told to stop ringing — not the claimant\'s', function () {
    ciConfigureFirebase();
    Notification::fake();
    $x = Lc::make();
    $agentPhone = ciDevice($x, 'agent', 'fcm-agent');
    $otherPhone = ciDevice($x, 'other', 'fcm-other');

    $this->actingAs($x->agent)->postJson(route('tenant-lead-calls.claim', $x->call->id))->assertOk();

    Notification::assertSentTo($otherPhone, CallingAppCallPush::class, fn (CallingAppCallPush $push): bool => $push->type === CallingAppCallPush::CALL_ENDED
        && $push->toFcm($push)->data['call_id'] === $x->call->id);
    Notification::assertNotSentTo($agentPhone, CallingAppCallPush::class);
});

test('outbound calls never push to the app', function () {
    ciConfigureFirebase();
    Notification::fake();
    $x = Lc::make(['direction' => CallDirection::Outbound]);
    $phone = ciDevice($x, 'agent', 'fcm-agent');

    LeadCallPushNotifier::ring($x->call);
    LeadCallBroadcaster::updated($x->call, CallStatus::Failed);

    Notification::assertNotSentTo($phone, CallingAppCallPush::class);
});

test('without the platform Firebase key, a paired app neither counts as available nor gets pushed', function () {
    Notification::fake();
    config(['calling.reverb_presence_enabled' => false]);
    $x = Lc::make();
    $x->agent->forceFill(['softphone_last_seen_at' => now()->subDay()])->save();
    $phone = ciDevice($x, 'agent', 'fcm-agent');

    LeadCallPushNotifier::ring($x->call);

    Notification::assertNotSentTo($phone, CallingAppCallPush::class);
    expect(app(SoftphonePresence::class)->isAvailable($x->agent->fresh()))->toBeFalse();
});
