<?php

use App\Actions\Tenant\RecordInboundCall;
use App\Enums\CallStatus;
use App\Enums\TenantStatus;
use App\Jobs\SendMissedCallNotification;
use App\Livewire\Tenant\NotificationBell;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Models\TenantUser;
use App\Notifications\MissedCallNotification;
use App\Support\LeadCallMissedNotifier;
use App\Support\WhatsAppCallEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\LeadCallScenario as Lc;
use Tests\Support\WhatsAppCallFixtures as Wa;

/*
 * WhatsApp calling Step 6 — the Missed-call bell notification: who gets it, that
 * a queue retry never duplicates it, that it follows the call's CURRENT lead, and
 * that it is dispatched exactly once per Missed transition (and never for Failed
 * or Rejected).
 */

/** A scenario whose call has already ended Missed, with the lead carrying a phone number the payload must not leak. */
function mcnMissed(): Lc
{
    $x = Lc::make(['status' => CallStatus::Missed, 'ringing_expires_at' => null]);
    $x->lead->update(['name' => 'Jane Caller', 'phone' => '15551234567']);

    return $x->reload();
}

function mcnRun(Lc $x): void
{
    (new SendMissedCallNotification($x->tenant->id, $x->call->id))->handle();
}

/**
 * @return Collection<int, DatabaseNotification>
 */
function mcnFor(TenantUser $user): Collection
{
    return $user->notifications()->where('type', MissedCallNotification::class)->get();
}

function mcnEvent(string $kind, array $call = []): WhatsAppCallEvent
{
    $item = $kind === 'connect' ? Wa::connect($call) : Wa::terminate($call);
    $event = WhatsAppCallEvent::fromWebhook(Wa::change([$item]), $item);

    expect($event)->toBeInstanceOf(WhatsAppCallEvent::class);

    return $event;
}

function mcnHandle(Lc $x, WhatsAppCallEvent $event): string
{
    return app(RecordInboundCall::class)->handle($event, $x->source, fn (): Lead => $x->lead, true);
}

// ── Recipients ──────────────────────────────────────────────────────────────

test('every active Client Admin of the call\'s tenant is notified, and nobody else', function () {
    $x = mcnMissed();
    $secondAdmin = TenantUser::factory()->clientAdmin()->create(['tenant_id' => $x->tenant->id]);
    $inactiveAdmin = TenantUser::factory()->clientAdmin()->inactive()->create(['tenant_id' => $x->tenant->id]);
    $otherTenant = tenantWithLeadManagement();

    mcnRun($x);

    expect(mcnFor($x->admin))->toHaveCount(1)
        ->and(mcnFor($secondAdmin))->toHaveCount(1)
        ->and(mcnFor($inactiveAdmin))->toHaveCount(0)
        ->and(mcnFor($x->agent))->toHaveCount(0)
        ->and(mcnFor($x->other))->toHaveCount(0)
        ->and(mcnFor($otherTenant['clientAdmin']))->toHaveCount(0);
});

test('a call nobody was routed to still reaches the tenant\'s admins', function () {
    $x = mcnMissed();
    $x->call->update(['routed_user_ids' => [], 'routing_mode' => 'none']);

    mcnRun($x);

    expect(mcnFor($x->admin))->toHaveCount(1);
});

test('the payload is only the call id, the lead id and the lead name', function () {
    $x = mcnMissed();

    mcnRun($x);

    $notification = mcnFor($x->admin)->first();

    expect($notification->data)->toBe(['call_id' => $x->call->id, 'lead_id' => $x->lead->id, 'name' => 'Jane Caller'])
        ->and(json_encode($notification->data))->not->toContain('15551234567')->not->toContain('wacid');
});

test('a suspended or inactive tenant is not notified', function (TenantStatus $status) {
    $x = mcnMissed();
    $x->tenant->update(['status' => $status]);

    mcnRun($x);

    expect(mcnFor($x->admin))->toHaveCount(0);
})->with([TenantStatus::Suspended, TenantStatus::Inactive]);

// ── Idempotency ─────────────────────────────────────────────────────────────

test('a retried job never duplicates a notification', function () {
    $x = mcnMissed();

    mcnRun($x);
    mcnRun($x);

    expect(mcnFor($x->admin))->toHaveCount(1);
});

test('a retry after a partial run only sends what is missing', function () {
    $x = mcnMissed();
    $secondAdmin = TenantUser::factory()->clientAdmin()->create(['tenant_id' => $x->tenant->id]);
    $x->admin->notify(new MissedCallNotification($x->call->id, $x->lead->id, 'Jane Caller'));

    mcnRun($x);

    expect(mcnFor($x->admin))->toHaveCount(1)->and(mcnFor($secondAdmin))->toHaveCount(1);
});

test('two different calls each notify', function () {
    $x = mcnMissed();
    $second = LeadCall::factory()->missed()->create(['tenant_id' => $x->tenant->id, 'lead_id' => $x->lead->id, 'lead_source_id' => $x->source->id]);

    mcnRun($x);
    (new SendMissedCallNotification($x->tenant->id, $second->id))->handle();

    expect(mcnFor($x->admin))->toHaveCount(2);
});

// ── The job trusts only the call's identity ─────────────────────────────────

test('the job follows the call\'s current lead after a merge', function () {
    $x = mcnMissed();
    $survivor = Lead::factory()->create(['tenant_id' => $x->tenant->id, 'name' => 'Survivor']);
    $x->call->update(['lead_id' => $survivor->id]);

    mcnRun($x);

    expect(mcnFor($x->admin)->first()->data)->toMatchArray(['lead_id' => $survivor->id, 'name' => 'Survivor']);
});

test('the job does nothing for a call that is no longer Missed, gone, or in another tenant', function () {
    $x = mcnMissed();
    $other = tenantWithLeadManagement();
    $x->call->update(['status' => CallStatus::Failed]);

    mcnRun($x);
    (new SendMissedCallNotification($x->tenant->id, '01JNOSUCHCALL0000000000000'))->handle();
    (new SendMissedCallNotification($other['tenant']->id, $x->call->id))->handle();

    expect(mcnFor($x->admin))->toHaveCount(0)->and(mcnFor($other['clientAdmin']))->toHaveCount(0);
});

test('the job is safe when the lead was deleted or the tenant has no active admin', function () {
    $x = mcnMissed();
    TenantUser::query()->where('tenant_id', $x->tenant->id)->update(['is_active' => false]);

    mcnRun($x);
    expect(mcnFor($x->admin))->toHaveCount(0);

    $x->lead->delete();
    mcnRun($x);

    expect(LeadCall::whereKey($x->call->id)->exists())->toBeFalse();
});

// ── Only a Missed transition dispatches ─────────────────────────────────────

test('the notifier queues one job for a Missed call and refuses every other status', function (CallStatus $status, int $expected) {
    Queue::fake([SendMissedCallNotification::class]);
    $x = Lc::make(['status' => $status]);

    $dispatched = LeadCallMissedNotifier::dispatch($x->call);

    expect($dispatched)->toBe($expected === 1);
    Queue::assertPushed(SendMissedCallNotification::class, $expected);
})->with([
    'missed' => [CallStatus::Missed, 1],
    'failed' => [CallStatus::Failed, 0],
    'rejected' => [CallStatus::Rejected, 0],
    'completed' => [CallStatus::Completed, 0],
    'ringing' => [CallStatus::Ringing, 0],
]);

test('a terminate that moves a Ringing call to Missed dispatches once, and its redelivery does not', function () {
    Queue::fake([SendMissedCallNotification::class]);
    $x = Lc::make();

    mcnHandle($x, mcnEvent('terminate', ['id' => Lc::CALL_ID, 'status' => 'FAILED']));
    mcnHandle($x, mcnEvent('terminate', ['id' => Lc::CALL_ID, 'status' => 'FAILED']));

    expect($x->fresh()->status)->toBe(CallStatus::Missed);
    Queue::assertPushed(SendMissedCallNotification::class, 1);
});

test('a terminate that fails a call, or fills an already-terminal one, dispatches nothing', function () {
    Queue::fake([SendMissedCallNotification::class]);
    $failing = Lc::make();
    $rejected = Lc::make(['status' => CallStatus::Rejected, 'external_call_id' => 'wacid.REJECTED']);

    mcnHandle($failing, mcnEvent('terminate', ['id' => Lc::CALL_ID, 'status' => 'FAILED', 'errors' => [['code' => 1]]]));
    mcnHandle($rejected, mcnEvent('terminate', ['id' => 'wacid.REJECTED', 'status' => 'FAILED']));

    expect($failing->fresh()->status)->toBe(CallStatus::Failed)->and($rejected->fresh()->status)->toBe(CallStatus::Rejected);
    Queue::assertNothingPushed();
});

test('a call recorded already Missed dispatches once: a terminate that arrives first, and a stale connect', function () {
    Queue::fake([SendMissedCallNotification::class]);
    $x = Lc::make();
    $x->call->delete();
    $x->lead->update(['phone' => Wa::CALLER]);

    mcnHandle($x, mcnEvent('terminate', ['id' => 'wacid.TERMINATE-FIRST']));
    mcnHandle($x, mcnEvent('connect', ['id' => 'wacid.STALE', 'timestamp' => (string) now()->subMinutes(10)->getTimestamp()]));

    expect(LeadCall::where('status', CallStatus::Missed->value)->count())->toBe(2);
    Queue::assertPushed(SendMissedCallNotification::class, 2);
});

test('a fresh connect that starts ringing dispatches nothing', function () {
    Queue::fake([SendMissedCallNotification::class]);
    $x = Lc::make();
    $x->call->delete();
    $x->lead->update(['phone' => Wa::CALLER]);

    mcnHandle($x, mcnEvent('connect', ['id' => 'wacid.RINGING']));

    expect(LeadCall::where('external_call_id', 'wacid.RINGING')->first()->status)->toBe(CallStatus::Ringing);
    Queue::assertNothingPushed();
});

test('a notification dispatch failure never fails the webhook', function () {
    $x = Lc::make();
    app()->forgetInstance('queue');
    Facade::clearResolvedInstance('queue');
    config(['queue.default' => 'mcn-unconfigured']);
    $logs = Lc::captureLogs();

    $outcome = mcnHandle($x, mcnEvent('terminate', ['id' => Lc::CALL_ID, 'status' => 'FAILED']));

    expect($outcome)->toBe(RecordInboundCall::OUTCOME_TERMINATE_APPLIED)
        ->and($x->fresh()->status)->toBe(CallStatus::Missed)
        ->and(Lc::logText($logs))->toContain('WA CALL MISSED NOTIFICATION DISPATCH FAILED');
});

test('the job is a queued job, retried with a backoff', function () {
    $job = new SendMissedCallNotification('t', 'c');

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job->tries)->toBe(3)
        ->and($job->backoff)->not->toBeEmpty();
});

// ── The bell ────────────────────────────────────────────────────────────────

test('the bell shows a missed call and its click-through opens the lead', function () {
    $x = mcnMissed();
    mcnRun($x);

    $bell = Livewire::actingAs($x->admin)->test(NotificationBell::class)->call('openNotifications');
    $notification = $bell->get('notifications')[0];

    expect($notification['title'])->toBe('Missed call')
        ->and($notification['message'])->toContain('Jane Caller')->not->toContain('15551234567')
        ->and($notification['icon'])->toBe('phone-x-mark')
        ->and($notification['url'])->toBe(route('tenant-notifications.open', $notification['id']));

    $this->actingAs($x->admin)
        ->get($notification['url'])
        ->assertRedirect(route('tenant-leads.show', $x->lead->id));
});
