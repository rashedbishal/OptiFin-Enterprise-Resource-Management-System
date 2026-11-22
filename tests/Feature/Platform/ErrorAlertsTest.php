<?php

use App\Health\DatabaseSizeCheck;
use App\Notifications\ErrorAlertNotification;
use App\Services\ErrorAlertNotifier;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Notification::fake();
    config(['ops.alert_email' => 'ops@example.test']);
});

function reportSameError(): void
{
    report(new RuntimeException('Something broke for lead '.random_int(1, 9999)));
}

test('a new kind of error emails the alert address once, with its details', function () {
    reportSameError();

    Notification::assertSentOnDemand(ErrorAlertNotification::class, function (ErrorAlertNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool {
        return $notifiable->routes['mail'] === 'ops@example.test'
            && $notification->error['type'] === RuntimeException::class
            && $notification->error['first'] === true
            && $notification->error['occurrences'] === 1
            && count($notification->error['trace']) <= 10
            && str_contains((string) $notification->toMail($notifiable)->render(), 'A new kind of error happened');
    });
});

test('the same kind of error emails at most once a day, counting every occurrence', function () {
    foreach (range(1, 3) as $ignored) {
        reportSameError();
    }

    Notification::assertSentOnDemandTimes(ErrorAlertNotification::class, 1);

    $this->travel(25)->hours();
    reportSameError();

    Notification::assertSentOnDemandTimes(ErrorAlertNotification::class, 2);
    Notification::assertSentOnDemand(ErrorAlertNotification::class, fn (ErrorAlertNotification $n): bool => $n->error['occurrences'] === 4 && $n->error['first'] === false);
});

test('errors of a different type are a different kind', function () {
    reportSameError();
    report(new LogicException('Another problem'));

    Notification::assertSentOnDemandTimes(ErrorAlertNotification::class, 2);
});

test('errors Laravel ignores (like validation) never email', function () {
    report(ValidationException::withMessages(['name' => 'Required.']));

    Notification::assertNothingSent();
});

test('nothing is sent when no alert address or account is set', function () {
    config(['ops.alert_email' => null]);

    reportSameError();

    Notification::assertNothingSent();
});

test('an error thrown inside a library is placed at the app code that triggered it', function () {
    try {
        (new DatabaseSizeCheck)->run(); // pg_database_size() doesn't exist on the SQLite test database
    } catch (Throwable $exception) {
        expect(app(ErrorAlertNotifier::class)->location($exception))->toStartWith('app/Health/DatabaseSizeCheck.php:');

        return;
    }

    $this->fail('Expected the check to throw on SQLite.');
});
