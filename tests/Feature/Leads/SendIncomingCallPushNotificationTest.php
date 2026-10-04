<?php

use App\Actions\Tenant\RecordInboundCall;
use App\Enums\CallStatus;
use App\Enums\TenantStatus;
use App\Jobs\SendIncomingCallPushNotification;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Notifications\IncomingWhatsAppCallNotification;
use App\Support\LeadCallPushNotifier;
use App\Support\WhatsAppCallEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\LeadCallScenario as Lc;
use Tests\Support\WhatsAppCallFixtures as Wa;

/*
 * WhatsApp inbound calling — Web Push for a fresh Ringing call. Mirrors
 * MissedCallNotificationTest.php's structure: recipients, idempotent-enough
 * behavior on a bad state, and that only a Ringing transition dispatches.
 * Lc::make() defaults routed_user_ids to [agent, other] — NOT the admin.
 */

function sipnRun(Lc $x): void
{
    (new SendIncomingCallPushNotification($x->tenant->id, $x->call->id))->handle();
}

// ── Recipients ──────────────────────────────────────────────────────────────

test('only the routed recipients are notified, never the admin or another tenant', function () {
    Notification::fake();
    $x = Lc::make(); // status defaults to Ringing; routed to [agent, other]
    $otherTenant = tenantWithLeadManagement();

    sipnRun($x);

    Notification::assertSentTo($x->agent, IncomingWhatsAppCallNotification::class);
    Notification::assertSentTo($x->other, IncomingWhatsAppCallNotification::class);
    Notification::assertNotSentTo($x->admin, IncomingWhatsAppCallNotification::class);
    Notification::assertNotSentTo($otherTenant['clientAdmin'], IncomingWhatsAppCallNotification::class);
});

test('an inactive routed recipient is skipped', function () {
    Notification::fake();
    $x = Lc::make();
    $x->agent->update(['is_active' => false]);

    sipnRun($x);

    Notification::assertNotSentTo($x->agent, IncomingWhatsAppCallNotification::class);
    Notification::assertSentTo($x->other, IncomingWhatsAppCallNotification::class);
});

test('a call nobody is routed to notifies nobody', function () {
    Notification::fake();
    $x = Lc::make(['routed_user_ids' => []]);

    sipnRun($x);

    Notification::assertNothingSent();
});

test('the notification carries the lead\'s click-through url, name and phone', function () {
    Notification::fake();
    $x = Lc::make();
    $x->lead->update(['name' => 'Jane Caller', 'phone' => '15551234567']);

    sipnRun($x);

    Notification::assertSentTo($x->agent, function (IncomingWhatsAppCallNotification $notification) use ($x) {
        $message = $notification->toWebPush($x->agent, $notification);

        expect($message->toArray())->toMatchArray([
            'title' => 'Incoming WhatsApp call',
            'body' => 'Jane Caller',
            'tag' => 'whatsapp-call-'.$x->call->id,
            'renotify' => true,
            'requireInteraction' => true,
            'vibrate' => [400, 200, 400, 200, 400, 200, 400],
            'data' => ['url' => route('tenant-leads.show', $x->lead->id)],
        ]);

        return true;
    });
});

test('falls back to the phone number when there is no caller name, and to a generic label when there is neither', function () {
    // leads.name is NOT NULL in this app (a lead always gets a name at
    // creation — see e.g. LeadWebhookController's own "Facebook User {id}"
    // fallback), so the notification's own null-name fallback is only
    // reachable by constructing it directly, not through a real Lead row.
    $withPhone = new IncomingWhatsAppCallNotification('call-1', 'lead-1', null, '15551234567');
    $withNeither = new IncomingWhatsAppCallNotification('call-1', 'lead-1', null, null);
    $notifiable = new stdClass;

    expect($withPhone->toWebPush($notifiable, $withPhone)->toArray()['body'])->toBe('15551234567')
        ->and($withNeither->toWebPush($notifiable, $withNeither)->toArray()['body'])->toBe('Unknown caller');
});

// ── The job trusts only the call's identity ─────────────────────────────────

test('does nothing for a call that is no longer Ringing, gone, or in another tenant', function () {
    Notification::fake();
    $x = Lc::make();
    $other = tenantWithLeadManagement();
    $x->call->update(['status' => CallStatus::Missed]);

    sipnRun($x);
    (new SendIncomingCallPushNotification($x->tenant->id, '01JNOSUCHCALL0000000000000'))->handle();
    (new SendIncomingCallPushNotification($other['tenant']->id, $x->call->id))->handle();

    Notification::assertNothingSent();
});

test('a suspended or inactive tenant is not notified', function (TenantStatus $status) {
    Notification::fake();
    $x = Lc::make();
    $x->tenant->update(['status' => $status]);

    sipnRun($x);

    Notification::assertNothingSent();
})->with([TenantStatus::Suspended, TenantStatus::Inactive]);

test('does nothing when the lead is missing', function () {
    Notification::fake();
    $x = Lc::make();
    Lead::whereKey($x->lead->id)->delete();

    sipnRun($x);

    Notification::assertNothingSent();
});

// ── Only a Ringing status dispatches ────────────────────────────────────────

test('the notifier queues one job for a Ringing call and refuses every other status', function (CallStatus $status, int $expected) {
    Queue::fake([SendIncomingCallPushNotification::class]);
    $x = Lc::make(['status' => $status]);

    $dispatched = LeadCallPushNotifier::ring($x->call);

    expect($dispatched)->toBe($expected === 1);
    Queue::assertPushed(SendIncomingCallPushNotification::class, $expected);
})->with([
    'ringing' => [CallStatus::Ringing, 1],
    'missed' => [CallStatus::Missed, 0],
    'failed' => [CallStatus::Failed, 0],
    'rejected' => [CallStatus::Rejected, 0],
    'completed' => [CallStatus::Completed, 0],
]);

test('a dispatch failure never fails the webhook', function () {
    $x = Lc::make();
    app()->forgetInstance('queue');
    Facade::clearResolvedInstance('queue');
    config(['queue.default' => 'sipn-unconfigured']);
    $logs = Lc::captureLogs();

    $result = LeadCallPushNotifier::ring($x->call);

    expect($result)->toBeFalse()
        ->and(Lc::logText($logs))->toContain('WA CALL PUSH NOTIFICATION DISPATCH FAILED');
});

test('the job is a queued job, retried with a backoff', function () {
    $job = new SendIncomingCallPushNotification('t', 'c');

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job->tries)->toBe(3)
        ->and($job->backoff)->not->toBeEmpty();
});

// ── End-to-end through the real webhook path ────────────────────────────────

test('a fresh inbound connect that starts ringing pushes whoever CallRouter actually routed it to', function () {
    Notification::fake();
    $x = Lc::make();
    $x->call->delete();
    $x->lead->update(['phone' => Wa::CALLER]);

    $item = Wa::connect(['id' => 'wacid.FRESH-RING']);
    $event = WhatsAppCallEvent::fromWebhook(Wa::change([$item]), $item);

    app(RecordInboundCall::class)->handle($event, $x->source, fn (): Lead => $x->lead, true);

    $call = LeadCall::where('external_call_id', 'wacid.FRESH-RING')->sole();
    expect($call->status)->toBe(CallStatus::Ringing);

    // The lead has no assignee and neither agent nor other is flagged a
    // backup receiver, so CallRouter falls all the way to AdminFallback —
    // routed_user_ids is computed fresh for this new call, never reused
    // from Lc::make()'s own default call (which this test deleted).
    expect($call->routed_user_ids)->toBe([$x->admin->id]);
    Notification::assertSentTo($x->admin, IncomingWhatsAppCallNotification::class);
    Notification::assertNotSentTo($x->agent, IncomingWhatsAppCallNotification::class);
});
