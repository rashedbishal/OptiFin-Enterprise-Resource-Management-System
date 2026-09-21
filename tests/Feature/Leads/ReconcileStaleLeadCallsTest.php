<?php

use App\Actions\Tenant\ClaimLeadCall;
use App\Actions\Tenant\FinalizeStaleLeadCalls;
use App\Actions\Tenant\RecordInboundCall;
use App\Actions\Tenant\RejectLeadCall;
use App\Enums\CallEndReason;
use App\Enums\CallStatus;
use App\Events\LeadCallUpdated;
use App\Jobs\SendMissedCallNotification;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Services\LeadDeletionEligibilityService;
use App\Services\LeadMergeService;
use App\Support\WhatsAppCallEvent;
use Illuminate\Cache\ArrayStore;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Queue;
use Tests\Support\LeadCallScenario as Lc;
use Tests\Support\WhatsAppCallFixtures as Wa;

/*
 * WhatsApp calling Step 6 — calls:reconcile-stale. Every transition is a
 * compare-and-set, so the race tests interleave the competing request between
 * the sweep's read and its UPDATE (a deterministic stand-in for two sessions;
 * the PostgreSQL row-lock wait itself is not exercised here).
 */

beforeEach(function () {
    Queue::fake([SendMissedCallNotification::class]);
    Event::fake([LeadCallUpdated::class]);
});

function rscSweep(): array
{
    return app(FinalizeStaleLeadCalls::class)->handle();
}

/** A Ringing call whose window closed `$secondsAgo` seconds ago. */
function rscRinging(int $secondsAgo = 300): Lc
{
    return Lc::make(['ringing_expires_at' => now()->subSeconds($secondsAgo)]);
}

/** An Answering call (pre-accepted, answer cached) whose window closed `$secondsAgo` seconds ago. */
function rscAnswering(int $secondsAgo = 300): Lc
{
    return Lc::make(['ringing_expires_at' => now()->subSeconds($secondsAgo)])->preAccepted();
}

function rscConnected(int $minutesAgo = 200): Lc
{
    $x = Lc::make(['ringing_expires_at' => now()->subMinutes($minutesAgo + 5)])->preAccepted();
    $x->call->update(['status' => CallStatus::Connected, 'connected_at' => now()->subMinutes($minutesAgo)]);

    return $x->reload();
}

function rscTerminate(Lc $x, array $overrides = []): string
{
    $item = Wa::terminate(array_merge(['id' => Lc::CALL_ID], $overrides));
    $event = WhatsAppCallEvent::fromWebhook(Wa::change([$item]), $item);

    return app(RecordInboundCall::class)->handle($event, $x->source, fn (): Lead => $x->lead, true);
}

/**
 * Runs `$action` once, right after the sweep's candidate read for `$status`
 * (the read of the OTHER rules must not trigger it).
 */
function rscInterleaveAfterRead(CallStatus $status, Closure $action): void
{
    $fired = false;

    DB::listen(function ($query) use (&$fired, $status, $action): void {
        if ($fired || ! str_starts_with($query->sql, 'select * from "lead_calls" where "status" = ?') || $query->bindings[0] !== $status->value) {
            return;
        }

        $fired = true;
        $action();
    });
}

function rscNotificationJobs(): int
{
    return Queue::pushed(SendMissedCallNotification::class)->count();
}

function rscStatusEvents(string $status): int
{
    return Event::dispatched(LeadCallUpdated::class, fn (LeadCallUpdated $event): bool => $event->status === $status)->count();
}

// ── Stale Ringing ───────────────────────────────────────────────────────────

test('a Ringing call inside the grace period is left alone', function () {
    $x = rscRinging(119);

    $summary = rscSweep();

    expect($summary['inspected'])->toBe(0)
        ->and($x->fresh()->status)->toBe(CallStatus::Ringing)
        ->and(rscNotificationJobs())->toBe(0);
});

test('a Ringing call past the grace period becomes Missed / ring_timeout, ending when its window closed', function () {
    $x = rscRinging(300);
    $windowClosedAt = $x->call->ringing_expires_at->getTimestamp();

    $summary = rscSweep();

    $call = $x->fresh();

    expect($summary['missed_ring'])->toBe(1)->and($summary['failures'])->toBe(0)
        ->and($call->status)->toBe(CallStatus::Missed)
        ->and($call->end_reason)->toBe(CallEndReason::RingTimeout)
        ->and($call->ended_at->getTimestamp())->toBe($windowClosedAt)
        ->and($call->claimed_by)->toBeNull()
        ->and($call->meta_status)->toBeNull()
        ->and($call->meta_stage)->toBeNull()
        ->and($call->meta_terminated_at)->toBeNull();
});

test('a transition drops both cached SDPs but leaves the call-id markers and the action lock alone', function () {
    $x = rscAnswering(300);
    Cache::put('wa-call-seen:'.$x->tenant->id.':'.$x->source->id.':'.Lc::CALL_ID, 1, 600);
    $lock = Cache::lock('wa-call-action:'.$x->call->id, 30);
    $lock->get();

    expect($x->offerCached())->toBeTrue()->and($x->answerCached())->toBeTrue();

    rscSweep();

    expect($x->offerCached())->toBeFalse()->and($x->answerCached())->toBeFalse()
        ->and(Cache::has('wa-call-seen:'.$x->tenant->id.':'.$x->source->id.':'.Lc::CALL_ID))->toBeTrue()
        ->and(Cache::lock('wa-call-action:'.$x->call->id, 30)->get())->toBeFalse();
});

test('a cache failure during cleanup never undoes the transition', function () {
    $x = rscRinging();

    $store = new class extends ArrayStore
    {
        public function forget($key)
        {
            throw new RuntimeException('cache down');
        }
    };
    Cache::extend('rsc-failing', fn () => Cache::repository($store));
    config(['cache.stores.rsc-failing' => ['driver' => 'rsc-failing']]);
    Cache::setDefaultDriver('rsc-failing');

    $summary = rscSweep();

    expect($summary['missed_ring'])->toBe(1)->and($summary['failures'])->toBe(0)
        ->and($x->fresh()->status)->toBe(CallStatus::Missed);
});

test('a Ringing row that carries a claim is not touched by the Ringing rule', function () {
    $x = rscRinging();
    $x->call->update(['claimed_by' => $x->agent->id]);

    expect(rscSweep()['inspected'])->toBe(0)
        ->and($x->fresh()->status)->toBe(CallStatus::Ringing);
});

test('a repeat sweep does nothing: no second transition, event or notification', function () {
    rscRinging();

    rscSweep();
    $again = rscSweep();

    expect($again['inspected'])->toBe(0)
        ->and(rscStatusEvents('missed'))->toBe(1)
        ->and(rscNotificationJobs())->toBe(1);
});

test('a Missed transition dispatches one notification job and announces the existing call.updated {id, status}', function () {
    $x = rscRinging();

    $summary = rscSweep();

    Queue::assertPushed(SendMissedCallNotification::class, fn (SendMissedCallNotification $job): bool => $job->tenantId === $x->tenant->id && $job->callId === $x->call->id);
    Event::assertDispatched(LeadCallUpdated::class, fn (LeadCallUpdated $event): bool => $event->callId === $x->call->id
        && $event->status === 'missed'
        && $event->broadcastWith() === ['id' => $x->call->id, 'status' => 'missed']
        && $event->recipientIds === [$x->agent->id, $x->other->id]);
    expect($summary['notifications_dispatched'])->toBe(1);
});

test('a claim cannot win a call the sweep is about to end', function () {
    $x = rscRinging();

    $result = app(ClaimLeadCall::class)->handle($x->agent, $x->call->id);

    expect($result->ok)->toBeFalse()->and($result->errorCode)->toBe('call_expired')
        ->and($x->fresh()->status)->toBe(CallStatus::Ringing);
});

test('a claim that lands between the sweep\'s read and its update wins the Ringing rule', function () {
    $x = rscRinging();

    Lc::interleaveAfterQuery('from "lead_calls"', fn () => $x->claimed());

    $summary = rscSweep();

    // The Ringing rule lost; the (already stale) Answering call is then judged by
    // its own rule, in the same run — one transition each, never two on one rule.
    expect($summary['lost_race'])->toBe(1)->and($summary['missed_ring'])->toBe(0)
        ->and($summary['missed_answering'])->toBe(1)
        ->and($x->fresh()->end_reason)->toBe(CallEndReason::AnswerTimeout)
        ->and($x->fresh()->claimed_by)->toBe($x->agent->id);
});

test('a reject that lands between the sweep\'s read and its update wins, and nothing is announced by the sweep', function () {
    $x = rscRinging();
    Lc::fakeMeta();

    Lc::interleaveAfterQuery('from "lead_calls"', fn () => app(RejectLeadCall::class)->handle($x->agent, $x->call->id));

    $summary = rscSweep();

    expect($summary['lost_race'])->toBe(1)->and($summary['missed_ring'])->toBe(0)
        ->and($x->fresh()->status)->toBe(CallStatus::Rejected)
        ->and($x->fresh()->end_reason)->toBe(CallEndReason::AgentRejected)
        ->and(rscNotificationJobs())->toBe(0)
        ->and(rscStatusEvents('missed'))->toBe(0);
});

test('a terminate that lands between the sweep\'s read and its update decides the status; the sweep only loses', function () {
    $x = rscRinging();

    Lc::interleaveAfterQuery('from "lead_calls"', fn () => rscTerminate($x, ['status' => 'FAILED', 'errors' => [['code' => 138001]]]));

    $summary = rscSweep();

    expect($summary['lost_race'])->toBe(1)
        ->and($x->fresh()->status)->toBe(CallStatus::Failed)
        ->and($x->fresh()->end_reason)->toBe(CallEndReason::MetaError)
        ->and(rscNotificationJobs())->toBe(0);
});

test('a terminate arriving after the sweep only fills what is empty and never changes the status or re-notifies', function () {
    $x = rscRinging();
    rscSweep();
    $swept = $x->fresh();

    rscTerminate($x, ['status' => 'COMPLETED', 'duration' => 7]);

    $call = $x->fresh();

    expect($call->status)->toBe(CallStatus::Missed)
        ->and($call->end_reason)->toBe(CallEndReason::RingTimeout)
        ->and($call->ended_at->getTimestamp())->toBe($swept->ended_at->getTimestamp())
        ->and($call->meta_terminated_at)->not->toBeNull()
        ->and($call->meta_status)->not->toBeNull()
        ->and($call->duration_seconds)->toBe(7)
        ->and(rscNotificationJobs())->toBe(1);
});

// ── Stale Answering ─────────────────────────────────────────────────────────

test('an Answering call inside the grace period is left alone', function () {
    $x = rscAnswering(119);

    expect(rscSweep()['inspected'])->toBe(0)->and($x->fresh()->status)->toBe(CallStatus::Answering);
});

test('an Answering call past the grace period becomes Missed / answer_timeout without touching the claim, stage or Meta fields', function () {
    $x = rscAnswering(300);
    $windowClosedAt = $x->call->ringing_expires_at->getTimestamp();

    $summary = rscSweep();

    $call = $x->fresh();

    expect($summary['missed_answering'])->toBe(1)
        ->and($call->status)->toBe(CallStatus::Missed)
        ->and($call->end_reason)->toBe(CallEndReason::AnswerTimeout)
        ->and($call->ended_at->getTimestamp())->toBe($windowClosedAt)
        ->and($call->claimed_by)->toBe($x->agent->id)
        ->and($call->claimed_at)->not->toBeNull()
        ->and($call->meta_stage->value)->toBe('pre_accepted')
        ->and($call->meta_status)->toBeNull()
        ->and($call->meta_terminated_at)->toBeNull()
        ->and(rscNotificationJobs())->toBe(1)
        ->and(rscStatusEvents('missed'))->toBe(1);
});

test('a repeat sweep of an Answering call is a no-op', function () {
    rscAnswering();

    rscSweep();

    expect(rscSweep()['inspected'])->toBe(0)->and(rscNotificationJobs())->toBe(1)->and(rscStatusEvents('missed'))->toBe(1);
});

test('the claimant\'s reject between the sweep\'s read and its update wins an Answering call', function () {
    $x = rscAnswering();
    Lc::fakeMeta();

    rscInterleaveAfterRead(CallStatus::Answering, fn () => app(RejectLeadCall::class)->handle($x->agent, $x->call->id));

    $summary = rscSweep();

    expect($summary['lost_race'])->toBe(1)
        ->and($x->fresh()->status)->toBe(CallStatus::Rejected)
        ->and($x->fresh()->end_reason)->toBe(CallEndReason::AgentCancelledDuringAnswer)
        ->and(rscNotificationJobs())->toBe(0);
});

test('a terminate between the sweep\'s read and its update decides an Answering call', function () {
    $x = rscAnswering();

    rscInterleaveAfterRead(CallStatus::Answering, fn () => rscTerminate($x, ['status' => 'FAILED']));

    $summary = rscSweep();

    expect($summary['lost_race'])->toBe(1)
        ->and($x->fresh()->status)->toBe(CallStatus::Missed)
        ->and($x->fresh()->end_reason)->toBe(CallEndReason::EndedBeforeConnect)
        ->and(rscNotificationJobs())->toBe(1);
});

// ── Stale Connected ─────────────────────────────────────────────────────────

test('a Connected call inside the active window is left alone', function () {
    $x = rscConnected(119);

    expect(rscSweep()['inspected'])->toBe(0)->and($x->fresh()->status)->toBe(CallStatus::Connected);
});

test('a Connected call past the active window becomes Completed / stale_no_terminate with no invented end or duration', function () {
    $x = rscConnected(121);
    $connectedAt = $x->call->connected_at->getTimestamp();

    $summary = rscSweep();

    $call = $x->fresh();

    expect($summary['completed_connected'])->toBe(1)
        ->and($call->status)->toBe(CallStatus::Completed)
        ->and($call->end_reason)->toBe(CallEndReason::StaleNoTerminate)
        ->and($call->ended_at)->toBeNull()
        ->and($call->duration_seconds)->toBeNull()
        ->and($call->connected_at->getTimestamp())->toBe($connectedAt)
        ->and($call->claimed_by)->toBe($x->agent->id)
        ->and($call->meta_terminated_at)->toBeNull()
        ->and(rscNotificationJobs())->toBe(0)
        ->and(rscStatusEvents('completed'))->toBe(1);
});

test('a late terminate fills a stale-completed call\'s missing end and duration without changing its status', function () {
    $x = rscConnected(200);
    rscSweep();

    rscTerminate($x, ['status' => 'COMPLETED', 'duration' => 3600]);

    $call = $x->fresh();

    expect($call->status)->toBe(CallStatus::Completed)
        ->and($call->end_reason)->toBe(CallEndReason::StaleNoTerminate)
        ->and($call->ended_at)->not->toBeNull()
        ->and($call->duration_seconds)->toBe(3600)
        ->and($call->meta_terminated_at)->not->toBeNull();
});

// ── Terminal calls are never touched ────────────────────────────────────────

test('a terminal call is never reconciled, however old', function (string $state) {
    $x = Lc::make();
    $x->call->delete();
    $call = LeadCall::factory()->{$state}()->create([
        'tenant_id' => $x->tenant->id,
        'lead_id' => $x->lead->id,
        'lead_source_id' => $x->source->id,
        'ringing_expires_at' => now()->subDay(),
        'connected_at' => now()->subDay(),
    ]);
    $before = $call->fresh()->only(['status', 'end_reason', 'ended_at', 'duration_seconds']);

    expect(rscSweep()['inspected'])->toBe(0)
        ->and($call->fresh()->only(['status', 'end_reason', 'ended_at', 'duration_seconds']))->toEqual($before);
})->with(['completed', 'missed', 'rejected', 'failed']);

// ── Limits, chunking, failures ──────────────────────────────────────────────

test('one run examines at most the configured batch limit and the next run finishes the rest', function () {
    $x = rscRinging();
    LeadCall::factory()->count(4)->create([
        'tenant_id' => $x->tenant->id, 'lead_id' => $x->lead->id, 'lead_source_id' => $x->source->id,
        'ringing_expires_at' => now()->subMinutes(10),
    ]);
    config(['calling.reconcile_batch_limit' => 3]);

    $first = rscSweep();
    $second = rscSweep();
    $third = rscSweep();

    expect($first['inspected'])->toBe(3)->and($second['inspected'])->toBe(2)->and($third['inspected'])->toBe(0)
        ->and(LeadCall::where('status', CallStatus::Missed->value)->count())->toBe(5);
});

test('candidates are read in id-ordered chunks of 100', function () {
    $x = rscRinging();
    LeadCall::factory()->count(249)->create([
        'tenant_id' => $x->tenant->id, 'lead_id' => $x->lead->id, 'lead_source_id' => $x->source->id,
        'ringing_expires_at' => now()->subMinutes(10),
    ]);
    $reads = [];
    DB::listen(function ($query) use (&$reads): void {
        if (str_starts_with($query->sql, 'select * from "lead_calls" where') && str_contains($query->sql, 'order by "id" asc')) {
            $reads[] = $query->sql;
        }
    });

    $summary = rscSweep();

    expect(FinalizeStaleLeadCalls::CHUNK_SIZE)->toBe(100)
        ->and($summary['missed_ring'])->toBe(250)
        ->and(collect($reads)->filter(fn (string $sql): bool => str_contains($sql, 'limit 100'))->count())->toBe(5);
});

test('a row that fails is counted, logged and skipped, the rest are still reconciled, and the command exits non-zero', function () {
    $x = rscRinging();
    LeadCall::factory()->create([
        'tenant_id' => $x->tenant->id, 'lead_id' => $x->lead->id, 'lead_source_id' => $x->source->id,
        'ringing_expires_at' => now()->subMinutes(10),
    ]);
    $logs = Lc::captureLogs();
    $thrown = false;
    DB::listen(function ($query) use (&$thrown): void {
        if (! $thrown && str_starts_with($query->sql, 'update "lead_calls"')) {
            $thrown = true;

            throw new RuntimeException('boom');
        }
    });

    $exit = Artisan::call('calls:reconcile-stale');

    expect($exit)->toBe(1)
        ->and(LeadCall::where('status', CallStatus::Missed->value)->count())->toBeGreaterThanOrEqual(1)
        ->and(Lc::logText($logs))->toContain('WA CALL RECONCILE FAILED')->toContain('"failures":1');
});

test('the command exits zero and logs a safe run summary', function () {
    rscRinging();
    $logs = Lc::captureLogs();

    expect(Artisan::call('calls:reconcile-stale'))->toBe(0);

    $summary = collect($logs->getRecords())->first(fn ($record): bool => $record->message === 'WA CALL RECONCILE RUN');

    expect($summary->context)->toHaveKeys(['inspected', 'missed_ring', 'missed_answering', 'completed_connected', 'lost_race', 'notifications_dispatched', 'failures', 'duration_ms'])
        ->and($summary->context['missed_ring'])->toBe(1);
});

test('a notification dispatch failure never fails or undoes the sweep', function () {
    $x = rscRinging();
    // Back to the real queue manager, pointed at a connection that does not exist.
    app()->forgetInstance('queue');
    Facade::clearResolvedInstance('queue');
    config(['queue.default' => 'rsc-unconfigured']);
    $logs = Lc::captureLogs();

    $summary = rscSweep();

    expect($summary['missed_ring'])->toBe(1)->and($summary['failures'])->toBe(0)->and($summary['notifications_dispatched'])->toBe(0)
        ->and($x->fresh()->status)->toBe(CallStatus::Missed)
        ->and(Lc::logText($logs))->toContain('WA CALL MISSED NOTIFICATION DISPATCH FAILED');
});

test('nothing sensitive reaches the logs', function () {
    $x = rscAnswering();
    $x->lead->update(['phone' => Wa::CALLER]);
    $logs = Lc::captureLogs();

    Artisan::call('calls:reconcile-stale');

    $text = Lc::logText($logs);

    expect($text)->toContain($x->call->id)->toContain('reconciled')
        ->not->toContain(Wa::SDP_SENTINEL)->not->toContain(Lc::ANSWER_SENTINEL)->not->toContain(Lc::TOKEN)
        ->not->toContain(Wa::APP_SECRET)->not->toContain(Wa::CALLER)->not->toContain('wacid.');
});

test('the sweep is tenant-safe: every tenant\'s stale calls are ended, each notification names its own tenant', function () {
    $a = rscRinging();
    $b = Lc::make(['ringing_expires_at' => now()->subMinutes(10), 'external_call_id' => 'wacid.OTHER-TENANT']);

    rscSweep();

    expect($a->fresh()->status)->toBe(CallStatus::Missed)->and($b->fresh()->status)->toBe(CallStatus::Missed);
    Queue::assertPushed(SendMissedCallNotification::class, fn ($job): bool => $job->callId === $a->call->id && $job->tenantId === $a->tenant->id);
    Queue::assertPushed(SendMissedCallNotification::class, fn ($job): bool => $job->callId === $b->call->id && $job->tenantId === $b->tenant->id);
});

// ── Lead delete / merge ─────────────────────────────────────────────────────

test('a stale active call keeps blocking the lead\'s deletion until it is reconciled', function () {
    $x = rscRinging();
    $eligibility = app(LeadDeletionEligibilityService::class);

    expect($eligibility->evaluate($x->lead)->eligible)->toBeFalse();

    rscSweep();

    expect($eligibility->evaluate($x->lead->fresh())->eligible)->toBeTrue();
});

test('a call reconciled after a merge stays attached to the surviving lead', function () {
    $x = rscRinging();
    $survivor = Lead::factory()->create(['tenant_id' => $x->tenant->id, 'email' => 'dup@example.com']);
    $x->lead->update(['email' => 'dup@example.com']);

    app(LeadMergeService::class)->merge($survivor, $x->lead, $x->admin, []);
    rscSweep();

    $call = $x->fresh();

    expect($call->status)->toBe(CallStatus::Missed)->and($call->lead_id)->toBe($survivor->id);
});

// ── Scheduler ───────────────────────────────────────────────────────────────

test('calls:reconcile-stale is scheduled every minute with a 10 minute overlap mutex', function () {
    Artisan::call('schedule:list');

    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_ends_with((string) $event->command, 'calls:reconcile-stale'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(10);
});
