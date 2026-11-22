<?php

use App\Livewire\Platform\Settings\Email;
use App\Mail\EmailSettingsTest;
use App\Models\PlatformSetting;
use App\Models\PlatformUser;
use App\Notifications\HealthCheckFailedNotification;
use App\Notifications\OpsAlertNotification;
use App\Services\MailSettings;
use App\Services\OpsAlertNotifier;
use App\Support\HealthAlertNotifiable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * @return array<string, mixed>
 */
function gmailAccount(array $overrides = []): array
{
    return [
        'host' => 'smtp.gmail.com',
        'port' => 587,
        'encryption' => 'tls',
        'username' => 'optifin.alerts@gmail.com',
        'password' => 'abcd efgh ijkl mnop',
        'from_address' => 'optifin.alerts@gmail.com',
        'from_name' => 'OptiFin',
        ...$overrides,
    ];
}

function saveUserAccount(PlatformUser $motherAdmin, array $overrides = []): void
{
    $component = Livewire::actingAs($motherAdmin, 'platform')->test(Email::class);

    foreach (gmailAccount($overrides) as $field => $value) {
        $component->set("user.{$field}", $value);
    }

    $component->call('saveUser')->assertHasNoErrors();
}

function saveAlertsAccount(PlatformUser $motherAdmin, string $recipients = "ops@example.test\nboss@example.test"): void
{
    $component = Livewire::actingAs($motherAdmin, 'platform')->test(Email::class);

    foreach (gmailAccount() as $field => $value) {
        $component->set("alerts.{$field}", $value);
    }

    $component->set('alerts.recipients', $recipients)->call('saveAlerts')->assertHasNoErrors();
}

// ── Access ─────────────────────────────────────────────────────────────

test('only the Mother Admin can open Email settings', function () {
    $this->get(route('admin.settings.email'))->assertRedirect(route('admin.login'));
    $this->actingAs(PlatformUser::factory()->create(), 'platform')->get(route('admin.settings.email'))->assertForbidden();
    $this->actingAs(PlatformUser::factory()->motherAdmin()->create(), 'platform')->get(route('admin.settings.email'))->assertOk();
});

test('the Settings sidebar group holds Email settings and Calling app, for the Mother Admin only', function () {
    $this->actingAs(PlatformUser::factory()->motherAdmin()->create(), 'platform')->get(route('admin.dashboard'))
        ->assertSeeInOrder(['Settings', route('admin.settings.email'), route('admin.settings.calling-app')]);

    $this->actingAs(PlatformUser::factory()->create(), 'platform')->get(route('admin.dashboard'))
        ->assertDontSee(route('admin.settings.email'))
        ->assertDontSee(route('admin.settings.calling-app'));
});

// ── Saving ─────────────────────────────────────────────────────────────

test('saving stores the account encrypted, without spaces in the app password, and never shows it again', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    saveUserAccount($motherAdmin);

    $raw = DB::table('platform_settings')->where('key', 'mail.user_emails')->value('value');
    expect($raw)->not->toContain('abcd');
    expect(app(MailSettings::class)->account(MailSettings::USER_EMAILS)['password'])->toBe('abcdefghijklmnop');

    Livewire::actingAs($motherAdmin, 'platform')->test(Email::class)
        ->assertSet('user.password', '')
        ->assertSet('user.username', 'optifin.alerts@gmail.com')
        ->assertDontSee('abcdefghijklmnop');
});

test('leaving the password empty keeps the saved one', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    saveUserAccount($motherAdmin);

    Livewire::actingAs($motherAdmin, 'platform')->test(Email::class)
        ->set('user.from_name', 'OptiFin Support')
        ->call('saveUser')
        ->assertHasNoErrors();

    $account = app(MailSettings::class)->account(MailSettings::USER_EMAILS);
    expect($account['from_name'])->toBe('OptiFin Support')
        ->and($account['password'])->toBe('abcdefghijklmnop');
});

test('a password is required the first time, and alert recipients must be valid addresses', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    Livewire::actingAs($motherAdmin, 'platform')->test(Email::class)
        ->set('user.username', 'x@gmail.com')
        ->set('user.from_address', 'x@gmail.com')
        ->call('saveUser')
        ->assertHasErrors('user.password');

    $component = Livewire::actingAs($motherAdmin, 'platform')->test(Email::class);
    foreach (gmailAccount() as $field => $value) {
        $component->set("alerts.{$field}", $value);
    }
    $component->set('alerts.recipients', 'ops@example.test, not-an-email')->call('saveAlerts')->assertHasErrors('alerts.recipients');

    expect(PlatformSetting::count())->toBe(0);
});

// ── Effect on sending ─────────────────────────────────────────────────

test('with nothing saved, the server\'s mail setting stays the default', function () {
    config(['mail.default' => 'log']);

    app(MailSettings::class)->applyToConfig();

    expect(config('mail.default'))->toBe('log');
});

test('a saved User emails account becomes the default mailer and sender', function () {
    saveUserAccount(PlatformUser::factory()->motherAdmin()->create());

    app(MailSettings::class)->applyToConfig();

    expect(config('mail.default'))->toBe(MailSettings::USER_MAILER)
        ->and(config('mail.mailers.'.MailSettings::USER_MAILER.'.host'))->toBe('smtp.gmail.com')
        ->and(config('mail.from.address'))->toBe('optifin.alerts@gmail.com');
});

test('saving only Monitoring alerts never changes the default mailer (no borrowing)', function () {
    config(['mail.default' => 'log']);
    saveAlertsAccount(PlatformUser::factory()->motherAdmin()->create());

    app(MailSettings::class)->applyToConfig();

    expect(config('mail.default'))->toBe('log')
        ->and(config('mail.mailers.'.MailSettings::ALERTS_MAILER.'.host'))->toBe('smtp.gmail.com');
});

test('ops alerts go to every saved alert recipient through the alerts account', function () {
    Notification::fake();
    config(['ops.alert_email' => 'old@example.test']);
    saveAlertsAccount(PlatformUser::factory()->motherAdmin()->create());

    expect(app(OpsAlertNotifier::class)->send('Scheduler failed', ['command' => 'leads:fetch-emails']))->toBeTrue();

    Notification::assertSentOnDemand(OpsAlertNotification::class, function (OpsAlertNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool {
        return $notifiable->routes['mail'] === ['ops@example.test', 'boss@example.test']
            && $notification->toMail($notifiable)->mailer === MailSettings::ALERTS_MAILER;
    });
});

test('without a saved alerts account, ops alerts still use OPS_ALERT_EMAIL and the default mailer', function () {
    Notification::fake();
    config(['ops.alert_email' => 'old@example.test']);

    app(OpsAlertNotifier::class)->send('Scheduler failed', []);

    Notification::assertSentOnDemand(OpsAlertNotification::class, fn (OpsAlertNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'old@example.test'
        && $notification->toMail($notifiable)->mailer === null);
});

test('Health alerts follow the same recipients and account', function () {
    config(['health.notifications.mail.to' => 'old@example.test']);
    expect((new HealthAlertNotifiable)->routeNotificationForMail())->toBe('old@example.test');
    expect((new HealthCheckFailedNotification([]))->toMail()->mailer)->toBeNull();

    saveAlertsAccount(PlatformUser::factory()->motherAdmin()->create());

    expect((new HealthAlertNotifiable)->routeNotificationForMail())->toBe(['ops@example.test', 'boss@example.test']);
    expect((new HealthCheckFailedNotification([]))->toMail()->mailer)->toBe(MailSettings::ALERTS_MAILER);
});

// ── Test email + removing ─────────────────────────────────────────────

test('send test emails the chosen address through the saved account', function () {
    Mail::fake();
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    saveUserAccount($motherAdmin);

    Livewire::actingAs($motherAdmin, 'platform')->test(Email::class)
        ->set('userTestTo', 'me@example.test')
        ->call('sendUserTest');

    Mail::assertSent(EmailSettingsTest::class, fn (EmailSettingsTest $mail): bool => $mail->hasTo('me@example.test') && $mail->mailer === MailSettings::USER_MAILER);
});

test('the alerts test goes to every alert recipient', function () {
    Mail::fake();
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    saveAlertsAccount($motherAdmin);

    Livewire::actingAs($motherAdmin, 'platform')->test(Email::class)->call('sendAlertsTest');

    Mail::assertSent(EmailSettingsTest::class, 2);
});

test('removing an account returns that kind of email to the server setting', function () {
    config(['mail.default' => 'log']);
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    saveUserAccount($motherAdmin);

    Livewire::actingAs($motherAdmin, 'platform')->test(Email::class)
        ->call('confirmRemove', MailSettings::USER_EMAILS)
        ->call('remove');

    expect(app(MailSettings::class)->isConfigured(MailSettings::USER_EMAILS))->toBeFalse();
    app(MailSettings::class)->applyToConfig();
    expect(config('mail.default'))->toBe('log');
});
