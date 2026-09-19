<?php

use App\Enums\LeadSourceType;
use App\Jobs\ProcessEmailLeadSource;
use App\Models\FailedEmailMessage;
use App\Models\LeadSource;
use App\Notifications\OpsAlertNotification;
use App\Services\ImapConnectionHealthMonitor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * OPS-1 — persistent IMAP connection failures become observable through one
 * throttled alert per failure window. The connection failure itself is real:
 * an email source pointed at a closed local port makes imap_open() fail exactly
 * as an unreachable or rejecting mailbox does, so no mocking of the extension
 * is needed.
 */
function unreachableEmailSource(array $overrides = []): LeadSource
{
    ['tenant' => $tenant] = tenantWithLeadManagement();

    return LeadSource::factory()->create(array_merge([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Email,
        'last_synced_at' => null,
        'config' => [
            'email_address' => 'inbox@tenant.test',
            'imap_host' => '127.0.0.1',
            'imap_port' => 1,
            'imap_username' => 'secret-user@tenant.test',
            'imap_password' => 'hunter2-secret',
        ],
    ], $overrides));
}

beforeEach(function () {
    config([
        'ops.alert_email' => 'ops@example.test',
        'ops.imap_failure_threshold' => 3,
        'ops.imap_failure_window_minutes' => 1440,
    ]);

    Cache::flush();
    Notification::fake();
});

test('a real IMAP connection failure is counted, and the threshold-th consecutive failure raises exactly one alert', function () {
    $source = unreachableEmailSource();
    Log::spy();

    foreach (range(1, 5) as $ignored) {
        (new ProcessEmailLeadSource($source))->handle();
    }

    Log::shouldHaveReceived('critical')->once()->withArgs(fn (string $message, array $context): bool => $context['lead_source_id'] === $source->id && $context['consecutive_failures'] === 3);
    Notification::assertSentOnDemandTimes(OpsAlertNotification::class, 1);
});

test('failures below the threshold raise no alert', function () {
    $source = unreachableEmailSource();
    Log::spy();

    foreach (range(1, 2) as $ignored) {
        (new ProcessEmailLeadSource($source))->handle();
    }

    Log::shouldNotHaveReceived('critical');
    Notification::assertNothingSent();
});

test('the alert carries no credentials, host, tenant lead data or IMAP error text', function () {
    $source = unreachableEmailSource();

    foreach (range(1, 3) as $ignored) {
        (new ProcessEmailLeadSource($source))->handle();
    }

    Notification::assertSentOnDemand(OpsAlertNotification::class, function ($notification, $channels, $notifiable) use ($source): bool {
        $body = (string) $notification->toMail($notifiable)->render();

        foreach (['hunter2-secret', 'secret-user@tenant.test', '127.0.0.1', 'inbox@tenant.test'] as $secret) {
            expect($body)->not->toContain($secret);
        }

        expect($body)->toContain($source->id)->toContain('consecutive_failures: 3');

        return true;
    });
});

test('many failures in a row are deduplicated to a single alert', function () {
    $source = unreachableEmailSource();
    $monitor = app(ImapConnectionHealthMonitor::class);

    foreach (range(1, 50) as $ignored) {
        $monitor->recordFailure($source);
    }

    Notification::assertSentOnDemandTimes(OpsAlertNotification::class, 1);
});

test('a successful connection resets the failure state, so the next outage alerts again', function () {
    $source = unreachableEmailSource();
    $monitor = app(ImapConnectionHealthMonitor::class);
    Log::spy();

    foreach (range(1, 3) as $ignored) {
        $monitor->recordFailure($source);
    }

    Notification::assertSentOnDemandTimes(OpsAlertNotification::class, 1);

    $monitor->recordSuccess($source);

    Log::shouldHaveReceived('info')->once()->withArgs(fn (string $message): bool => str_contains($message, 'recovered after 3 consecutive failures'));

    // Two failures after recovery is below the threshold again ...
    $monitor->recordFailure($source);
    $monitor->recordFailure($source);
    Notification::assertSentOnDemandTimes(OpsAlertNotification::class, 1);

    // ... and the third is a fresh outage.
    $monitor->recordFailure($source);
    Notification::assertSentOnDemandTimes(OpsAlertNotification::class, 2);
});

test('an intermittent source that recovers between failures never alerts', function () {
    $source = unreachableEmailSource();
    $monitor = app(ImapConnectionHealthMonitor::class);

    foreach (range(1, 10) as $ignored) {
        $monitor->recordFailure($source);
        $monitor->recordFailure($source);
        $monitor->recordSuccess($source);
    }

    Notification::assertNothingSent();
});

test('sources are counted independently', function () {
    $first = unreachableEmailSource();
    $second = unreachableEmailSource();
    $monitor = app(ImapConnectionHealthMonitor::class);

    $monitor->recordFailure($first);
    $monitor->recordFailure($second);
    $monitor->recordFailure($first);
    $monitor->recordFailure($second);
    Notification::assertNothingSent();

    $monitor->recordFailure($first);
    Notification::assertSentOnDemandTimes(OpsAlertNotification::class, 1);

    $monitor->recordFailure($second);
    Notification::assertSentOnDemandTimes(OpsAlertNotification::class, 2);
});

test('a source that stays broken re-alerts once per failure window, not every minute', function () {
    $source = unreachableEmailSource();
    $monitor = app(ImapConnectionHealthMonitor::class);

    foreach (range(1, 20) as $ignored) {
        $monitor->recordFailure($source);
    }

    Notification::assertSentOnDemandTimes(OpsAlertNotification::class, 1);

    $this->travel(1441)->minutes();

    foreach (range(1, 20) as $ignored) {
        $monitor->recordFailure($source);
    }

    Notification::assertSentOnDemandTimes(OpsAlertNotification::class, 2);
});

test('with OPS_ALERT_EMAIL unset the outage is still logged critically, once, and nothing breaks', function () {
    config(['ops.alert_email' => null]);
    $source = unreachableEmailSource();
    Log::spy();

    foreach (range(1, 6) as $ignored) {
        (new ProcessEmailLeadSource($source))->handle();
    }

    Log::shouldHaveReceived('critical')->once();
    Notification::assertNothingSent();
});

test('a cache or mail failure inside the monitor never propagates into the job', function () {
    $source = unreachableEmailSource();
    Log::spy();

    Cache::shouldReceive('add')->andThrow(new RuntimeException('cache store down'));
    Cache::shouldReceive('pull')->andThrow(new RuntimeException('cache store down'));

    $monitor = app(ImapConnectionHealthMonitor::class);
    $monitor->recordFailure($source);
    $monitor->recordSuccess($source);

    Log::shouldHaveReceived('warning')->twice()->withArgs(fn (string $message, array $context): bool => $context === ['exception' => RuntimeException::class]);
});

test('the connection-failure path itself is unchanged: it returns normally, does not touch last_synced_at, and records no message failures', function () {
    $source = unreachableEmailSource();

    (new ProcessEmailLeadSource($source))->handle();

    expect($source->fresh()->last_synced_at)->toBeNull();
    expect(FailedEmailMessage::count())->toBe(0);
});

test('a retry run against an unreachable mailbox counts toward the same failure state', function () {
    $source = unreachableEmailSource();

    (new ProcessEmailLeadSource($source))->handle();
    (new ProcessEmailLeadSource($source, ['some-failed-message-id']))->handle();
    (new ProcessEmailLeadSource($source))->handle();

    Notification::assertSentOnDemandTimes(OpsAlertNotification::class, 1);
});

test('further failures never extend the window: it is fixed at the first failure, so the re-alert is not postponed indefinitely', function () {
    $source = unreachableEmailSource();
    $monitor = app(ImapConnectionHealthMonitor::class);

    foreach (range(1, 3) as $ignored) {
        $monitor->recordFailure($source);
    }

    Notification::assertSentOnDemandTimes(OpsAlertNotification::class, 1);

    // Keep failing every minute for 23h59m — a sliding TTL would now be ~24h from the LAST failure.
    foreach (range(1, 1439) as $ignored) {
        $this->travel(1)->minutes();
        $monitor->recordFailure($source);
    }

    Notification::assertSentOnDemandTimes(OpsAlertNotification::class, 1);

    // Just past 24h from the FIRST failure the counter is gone, and the very next failures start a new window.
    $this->travel(2)->minutes();
    foreach (range(1, 3) as $ignored) {
        $monitor->recordFailure($source);
    }

    Notification::assertSentOnDemandTimes(OpsAlertNotification::class, 2);
});

test('the counter walks 1, 2, 3, 4, 5 and raises its single alert exactly on the threshold, nothing before or after', function () {
    config(['ops.imap_failure_threshold' => 5]);
    $source = unreachableEmailSource();
    $monitor = app(ImapConnectionHealthMonitor::class);

    foreach (range(1, 4) as $ignored) {
        $monitor->recordFailure($source);
    }

    Notification::assertNothingSent();

    $monitor->recordFailure($source);
    Notification::assertSentOnDemandTimes(OpsAlertNotification::class, 1);

    foreach (range(6, 12) as $ignored) {
        $monitor->recordFailure($source);
    }

    Notification::assertSentOnDemandTimes(OpsAlertNotification::class, 1);
});

test('a cache reset mid-outage is safe: counting simply restarts and alerts again at the threshold', function () {
    $source = unreachableEmailSource();
    $monitor = app(ImapConnectionHealthMonitor::class);

    foreach (range(1, 3) as $ignored) {
        $monitor->recordFailure($source);
    }

    Cache::flush();

    $monitor->recordFailure($source);
    $monitor->recordFailure($source);
    Notification::assertSentOnDemandTimes(OpsAlertNotification::class, 1);

    $monitor->recordFailure($source);
    Notification::assertSentOnDemandTimes(OpsAlertNotification::class, 2);
});
