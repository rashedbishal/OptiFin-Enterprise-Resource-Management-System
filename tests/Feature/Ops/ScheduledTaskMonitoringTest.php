<?php

use App\Enums\LeadSourceType;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Notifications\OpsAlertNotification;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * OPS-1 — every scheduled command reports its own failure, and
 * leads:fetch-emails alone carries the optional liveness heartbeat. Events are
 * driven through Event::finish() — the exact call schedule:run makes after a
 * command process ends — so no artisan subprocess is spawned.
 */
const OPS_SCHEDULED_COMMANDS = [
    'leads:fetch-emails',
    'leads:retry-failed-emails',
    'leads:cleanup-attachments',
    'leads:send-alert-notifications',
    'leads:recalculate-scores',
    'leads:detect-duplicates',
    'tasks:send-alert-notifications',
    'leads:nurture-inactive-leads',
    'calls:reconcile-stale',
];

/**
 * @return array<string, Event>
 */
function scheduledEventsByCommand(): array
{
    Artisan::call('schedule:list'); // makes sure routes/console.php has been loaded

    $events = [];

    foreach (app(Schedule::class)->events() as $event) {
        $events[trim(substr($event->command, (int) strrpos($event->command, ' ')))] = $event;
    }

    return $events;
}

beforeEach(function () {
    config([
        'ops.alert_email' => 'ops@example.test',
        'ops.scheduler_heartbeat_url' => null,
        'ops.scheduler_alert_throttle_minutes' => 60,
    ]);

    Notification::fake();
});

test('exactly the 9 approved commands are scheduled, and every one of them reports its own failure', function () {
    $events = scheduledEventsByCommand();

    expect(array_keys($events))->toEqualCanonicalizing(OPS_SCHEDULED_COMMANDS);

    foreach ($events as $event) {
        $event->finish(app(), 1);
    }

    foreach (OPS_SCHEDULED_COMMANDS as $command) {
        Notification::assertSentOnDemand(
            OpsAlertNotification::class,
            fn ($notification, $channels, $notifiable) => str_contains($notification->toMail($notifiable)->subject, $command),
        );
    }

    Notification::assertSentOnDemandTimes(OpsAlertNotification::class, 9);
});

// F5 remediation (forensic audit Batch C) — leads:cleanup-attachments,
// leads:recalculate-scores and leads:detect-duplicates previously had no
// overlap protection at all, unlike every other command in routes/console.php.
// $monitored()'s own docblock explains why a run skipped by
// withoutOverlapping() is never reported to ScheduledTaskMonitor as a
// failure — that behavior is already covered above ("a successful run
// sends no failure alert..." doesn't call finish() for a skipped run at
// all, since schedule:run never invokes Event::finish() for one). These
// two tests verify only the schedule DEFINITION itself: the attribute is
// actually set on exactly the intended commands, and deliberately not on
// the two commands the remediation plan explicitly excluded.
test('F5: cleanup-attachments, recalculate-scores and detect-duplicates now guard against overlapping runs', function () {
    $events = scheduledEventsByCommand();

    foreach (['leads:cleanup-attachments', 'leads:recalculate-scores', 'leads:detect-duplicates'] as $command) {
        expect($events[$command]->withoutOverlapping)->toBeTrue();
        expect($events[$command]->expiresAt)->toBe(1440);
    }
});

test('F5: leads:send-alert-notifications and tasks:send-alert-notifications remain deliberately without an overlap guard', function () {
    $events = scheduledEventsByCommand();

    foreach (['leads:send-alert-notifications', 'tasks:send-alert-notifications'] as $command) {
        expect($events[$command]->withoutOverlapping)->toBeFalse();
    }
});

test('a failure is logged with only the command name, exit code and a timestamp', function () {
    Log::spy();

    scheduledEventsByCommand()['leads:retry-failed-emails']->finish(app(), 3);

    Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context): bool {
        return $message === 'Scheduled command [leads:retry-failed-emails] failed with exit code [3].'
            && array_keys($context) === ['command', 'exit_code', 'failed_at']
            && $context['command'] === 'leads:retry-failed-emails'
            && $context['exit_code'] === 3;
    });
});

test('the alert email carries no tenant data, credentials or command output', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Email,
        'config' => ['imap_host' => 'imap.secret-host.test', 'imap_username' => 'secret-user@tenant.test', 'imap_password' => 'hunter2-secret'],
    ]);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'name' => 'Confidential Lead Name', 'email' => 'private-lead@tenant.test']);

    scheduledEventsByCommand()['leads:fetch-emails']->finish(app(), 1);

    Notification::assertSentOnDemand(OpsAlertNotification::class, function ($notification, $channels, $notifiable): bool {
        $body = (string) $notification->toMail($notifiable)->render();

        foreach (['Confidential Lead Name', 'private-lead@tenant.test', 'hunter2-secret', 'secret-user@tenant.test', 'imap.secret-host.test'] as $secret) {
            expect($body)->not->toContain($secret);
        }

        expect($body)->toContain('leads:fetch-emails')->toContain('exit_code: 1');

        return true;
    });
});

test('the alert goes to the configured OPS_ALERT_EMAIL recipient', function () {
    config(['ops.alert_email' => 'operator@example.test']);

    scheduledEventsByCommand()['leads:detect-duplicates']->finish(app(), 1);

    Notification::assertSentOnDemand(
        OpsAlertNotification::class,
        fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'operator@example.test',
    );
});

test('with OPS_ALERT_EMAIL unset (or invalid) the failure is still logged and nothing breaks', function (mixed $value) {
    config(['ops.alert_email' => $value]);
    Log::spy();

    scheduledEventsByCommand()['leads:recalculate-scores']->finish(app(), 1);

    Log::shouldHaveReceived('error')->once();
    Notification::assertNothingSent();
})->with([
    'unset' => [null],
    'empty' => [''],
    'not an email address' => ['not-an-email'],
]);

test('a successful run sends no failure alert and logs no error', function () {
    Log::spy();

    foreach (scheduledEventsByCommand() as $event) {
        $event->finish(app(), 0);
    }

    Notification::assertNothingSent();
    Log::shouldNotHaveReceived('error');
});

test('a command failing repeatedly emails once per throttle window per command, but is logged every time', function () {
    Log::spy();
    $events = scheduledEventsByCommand();

    $events['leads:fetch-emails']->finish(app(), 1);
    $events['leads:fetch-emails']->finish(app(), 1);
    $events['leads:fetch-emails']->finish(app(), 1);
    $events['leads:cleanup-attachments']->finish(app(), 1);

    Log::shouldHaveReceived('error')->times(4);
    Notification::assertSentOnDemandTimes(OpsAlertNotification::class, 2);

    $this->travel(61)->minutes();
    $events['leads:fetch-emails']->finish(app(), 1);

    Notification::assertSentOnDemandTimes(OpsAlertNotification::class, 3);
});

test('a mail delivery failure never propagates, is logged without its message, and cannot cause a per-minute retry storm', function () {
    Log::spy();
    $events = scheduledEventsByCommand();

    $attempts = new ArrayObject(['count' => 0]);

    Notification::swap(new readonly class($attempts)
    {
        public function __construct(private ArrayObject $attempts) {}

        public function sendNow(): never
        {
            $this->attempts['count']++;

            throw new RuntimeException('smtp://user:password@mail.internal refused');
        }
    });

    // A broken mailer with a command failing every minute for two hours.
    foreach (range(1, 120) as $ignored) {
        $events['leads:fetch-emails']->finish(app(), 1);
        $this->travel(1)->minutes();
    }

    // Every failure was logged; delivery was attempted only once per throttle window (60 min).
    Log::shouldHaveReceived('error')->times(120);
    expect($attempts['count'])->toBe(2);
    Log::shouldHaveReceived('warning')->times(2)->withArgs(fn (string $message, array $context): bool => $context === ['exception' => RuntimeException::class]);

});

test('leads:fetch-emails pings the configured heartbeat URL with a bare GET and no payload', function () {
    config(['ops.scheduler_heartbeat_url' => 'https://monitor.example.test/ping/abc123']);
    Http::fake();

    scheduledEventsByCommand()['leads:fetch-emails']->finish(app(), 0);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'GET'
        && $request->url() === 'https://monitor.example.test/ping/abc123'
        && $request->body() === '');
});

test('no other command, and no failed run, ever pings the heartbeat', function () {
    config(['ops.scheduler_heartbeat_url' => 'https://monitor.example.test/ping/abc123']);
    Http::fake();
    $events = scheduledEventsByCommand();

    foreach (array_diff_key($events, ['leads:fetch-emails' => true]) as $event) {
        $event->finish(app(), 0);
    }

    $events['leads:fetch-emails']->finish(app(), 1);

    Http::assertNothingSent();
});

test('the heartbeat is disabled when OPS_SCHEDULER_HEARTBEAT_URL is unset or empty', function (mixed $value) {
    config(['ops.scheduler_heartbeat_url' => $value]);
    Http::fake();

    scheduledEventsByCommand()['leads:fetch-emails']->finish(app(), 0);

    Http::assertNothingSent();
})->with([[null], ['']]);

test('a heartbeat that cannot be delivered never fails the command and never logs the URL', function (Closure $response) {
    config(['ops.scheduler_heartbeat_url' => 'https://monitor.example.test/ping/secret-token']);
    Http::fake(['*' => $response]);
    Log::spy();

    scheduledEventsByCommand()['leads:fetch-emails']->finish(app(), 0);

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => ! str_contains($message.json_encode($context), 'secret-token'));
    Log::shouldNotHaveReceived('error');
    Notification::assertNothingSent();
})->with([
    'connection error' => [fn () => throw new ConnectionException('cURL error 6: could not resolve host monitor.example.test/secret-token')],
    'server error' => [fn () => Http::response('down', 500)],
    'malformed url' => [fn () => throw new InvalidArgumentException('Malformed URL https://monitor.example.test/ping/secret-token')],
]);
