<?php

use App\Backups\MaximumAgeInHours;
use App\Health\BackupsCheck;
use App\Jobs\RunBackup;
use App\Livewire\Platform\Settings\Backups;
use App\Models\PlatformSetting;
use App\Models\PlatformUser;
use App\Notifications\Backups\BackupHasFailedNotification;
use App\Services\BackupSettings;
use App\Services\MailSettings;
use App\Support\BackupAlertNotifiable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Backup\BackupDestination\BackupDestination;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Exceptions\InvalidHealthCheck;
use Spatie\Health\Enums\Status;

function saveB2Settings(PlatformUser $motherAdmin, array $overrides = []): void
{
    $values = [
        'bucket' => 'optifin-backups',
        'endpoint' => 's3.us-west-004.backblazeb2.com',
        'keyId' => '004abc0000000000000000001',
        'applicationKey' => 'K004secretsecretsecret',
        'password' => 'correct horse battery',
        'passwordConfirmation' => 'correct horse battery',
        ...$overrides,
    ];

    $component = Livewire::actingAs($motherAdmin, 'platform')->test(Backups::class);

    foreach ($values as $field => $value) {
        $component->set($field, $value);
    }

    $component->call('save')->assertHasNoErrors();
}

/**
 * Keeps BackupSettings real, except that it must not replace the faked
 * `backups` disk with a real Backblaze B2 one.
 */
function fakeBackupsDisk(): void
{
    test()->partialMock(BackupSettings::class, fn ($mock) => $mock->shouldReceive('applyToConfig')->andReturnNull());
    Storage::fake(BackupSettings::DISK);
}

function putBackup(string $made): string
{
    $path = config('backup.backup.name').'/'.now()->parse($made)->format('Y-m-d-H-i-s').'.zip';
    Storage::disk(BackupSettings::DISK)->put($path, str_repeat('x', 2048));

    return $path;
}

// ── Access ─────────────────────────────────────────────────────────────

test('only the Mother Admin can open Backups', function () {
    $this->get(route('admin.settings.backups'))->assertRedirect(route('admin.login'));
    $this->actingAs(PlatformUser::factory()->create(), 'platform')->get(route('admin.settings.backups'))->assertForbidden();
    $this->actingAs(PlatformUser::factory()->motherAdmin()->create(), 'platform')->get(route('admin.settings.backups'))->assertOk();
});

// ── Settings ───────────────────────────────────────────────────────────

test('saving stores the B2 key and backup password encrypted, and never shows them again', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    saveB2Settings($motherAdmin);

    $raw = DB::table('platform_settings')->where('key', BackupSettings::SETTING_KEY)->value('value');
    expect($raw)->not->toContain('K004secret')->not->toContain('battery');

    Livewire::actingAs($motherAdmin, 'platform')->test(Backups::class)
        ->assertSet('bucket', 'optifin-backups')
        ->assertSet('applicationKey', '')
        ->assertSet('password', '')
        ->assertDontSee('K004secretsecretsecret');
});

test('the endpoint, a long enough matching password and the key are required the first time', function (string $field, mixed $value) {
    $component = Livewire::actingAs(PlatformUser::factory()->motherAdmin()->create(), 'platform')->test(Backups::class)
        ->set('bucket', 'optifin-backups')
        ->set('endpoint', 's3.us-west-004.backblazeb2.com')
        ->set('keyId', '004abc')
        ->set('applicationKey', 'K004secret')
        ->set('password', 'correct horse battery')
        ->set('passwordConfirmation', 'correct horse battery')
        ->set($field, $value)
        ->call('save');

    $component->assertHasErrors($field === 'passwordConfirmation' ? 'password' : $field);
    expect(PlatformSetting::count())->toBe(0);
})->with([
    'endpoint with https' => ['endpoint', 'https://s3.us-west-004.backblazeb2.com'],
    'short password' => ['password', 'short'],
    'passwords differ' => ['passwordConfirmation', 'something else entirely'],
    'missing key' => ['applicationKey', ''],
]);

test('leaving the key and password empty keeps the saved ones', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    saveB2Settings($motherAdmin);

    Livewire::actingAs($motherAdmin, 'platform')->test(Backups::class)
        ->set('bucket', 'optifin-backups-2')
        ->call('save')
        ->assertHasNoErrors();

    app(BackupSettings::class)->applyToConfig();

    expect(config('filesystems.disks.backups.bucket'))->toBe('optifin-backups-2')
        ->and(config('filesystems.disks.backups.secret'))->toBe('K004secretsecretsecret')
        ->and(config('backup.backup.password'))->toBe('correct horse battery');
});

test('the saved settings become the B2 disk (region from the endpoint) and the archive password', function () {
    saveB2Settings(PlatformUser::factory()->motherAdmin()->create());

    app(BackupSettings::class)->applyToConfig();

    expect(config('filesystems.disks.backups'))->toMatchArray([
        'driver' => 's3',
        'region' => 'us-west-004',
        'endpoint' => 'https://s3.us-west-004.backblazeb2.com',
        'bucket' => 'optifin-backups',
    ])->and(config('backup.backup.password'))->toBe('correct horse battery');
});

test('the scheduled backup commands are skipped until settings are saved', function () {
    Artisan::call('schedule:list');
    $backupEvents = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains($event->command, 'backup:'));

    expect($backupEvents)->toHaveCount(3);
    expect($backupEvents->every(fn ($event) => ! $event->filtersPass(app())))->toBeTrue();

    saveB2Settings(PlatformUser::factory()->motherAdmin()->create());

    expect($backupEvents->every(fn ($event) => $event->filtersPass(app())))->toBeTrue();
});

// ── Backups list ───────────────────────────────────────────────────────

test('the page lists backups newest first and shows that they are healthy', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    saveB2Settings($motherAdmin);
    fakeBackupsDisk();
    putBackup('-3 days');
    putBackup('-2 hours');

    Livewire::actingAs($motherAdmin, 'platform')->test(Backups::class)
        ->assertSee('Backups are healthy')
        ->assertSeeInOrder([now()->subHours(2)->toDayDateTimeString(), now()->subDays(3)->toDayDateTimeString()]);
});

test('back up now queues a backup run', function () {
    Queue::fake();
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    saveB2Settings($motherAdmin);
    fakeBackupsDisk();

    Livewire::actingAs($motherAdmin, 'platform')->test(Backups::class)->call('backUpNow');

    Queue::assertPushed(RunBackup::class);
});

test('a backup can be downloaded and deleted', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    saveB2Settings($motherAdmin);
    fakeBackupsDisk();
    $path = putBackup('-1 hour');

    Livewire::actingAs($motherAdmin, 'platform')->test(Backups::class)
        ->call('download', $path)
        ->assertFileDownloaded(basename($path))
        ->call('confirmDelete', $path)
        ->call('deleteConfirmed');

    Storage::disk(BackupSettings::DISK)->assertMissing($path);
});

test('a path that is not a listed backup can be neither downloaded nor deleted', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    saveB2Settings($motherAdmin);
    fakeBackupsDisk();
    Storage::disk(BackupSettings::DISK)->put('other/secret.txt', 'x');

    Livewire::actingAs($motherAdmin, 'platform')->test(Backups::class)
        ->call('confirmDelete', 'other/secret.txt')
        ->call('deleteConfirmed');

    Storage::disk(BackupSettings::DISK)->assertExists('other/secret.txt');
    expect(app(BackupSettings::class)->download('other/secret.txt'))->toBeNull();
});

// ── Health + alerts ────────────────────────────────────────────────────

test('the Health page backup check: not set up, healthy, and too old', function () {
    $notSetUp = (new BackupsCheck)->run();
    expect($notSetUp->status)->toBe(Status::warning())
        ->and($notSetUp->getNotificationMessage())->toBe('');

    saveB2Settings(PlatformUser::factory()->motherAdmin()->create());
    fakeBackupsDisk();
    putBackup('-30 hours');

    expect((new BackupsCheck)->run()->status)->toBe(Status::failed());

    putBackup('-1 hour');
    app(BackupSettings::class)->forgetStatus();

    expect((new BackupsCheck)->run()->status)->toBe(Status::ok());
});

test('the nightly monitor rule fails when the newest backup is older than 26 hours', function () {
    fakeBackupsDisk();
    putBackup('-27 hours');
    $destination = BackupDestination::create(BackupSettings::DISK, config('backup.backup.name'));

    expect(fn () => (new MaximumAgeInHours(26))->checkHealth($destination))->toThrow(InvalidHealthCheck::class);

    putBackup('-25 hours');
    $fresh = BackupDestination::create(BackupSettings::DISK, config('backup.backup.name'));
    (new MaximumAgeInHours(26))->checkHealth($fresh);
});

test('backup failure emails go to the alert recipients through the alerts account', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    config(['ops.alert_email' => 'old@example.test']);
    expect((new BackupAlertNotifiable)->routeNotificationForMail())->toBe('old@example.test');

    app(MailSettings::class)->store(MailSettings::MONITORING_ALERTS, [
        'host' => 'smtp.gmail.com', 'port' => 587, 'encryption' => 'tls', 'username' => 'a@gmail.com',
        'password' => 'abcdefghijklmnop', 'from_address' => 'a@gmail.com', 'from_name' => 'OptiFin', 'recipients' => ['ops@example.test'],
    ], $motherAdmin);

    expect((new BackupAlertNotifiable)->routeNotificationForMail())->toBe(['ops@example.test']);
    expect((new BackupHasFailedNotification(new BackupHasFailed(new RuntimeException('B2 down'), 'backups', 'optifin-erp')))->toMail()->mailer)
        ->toBe(MailSettings::ALERTS_MAILER);
});

test('the cached backup status survives the production database cache, which never rebuilds objects', function () {
    config(['cache.default' => 'database']);
    saveB2Settings(PlatformUser::factory()->motherAdmin()->create());
    fakeBackupsDisk();
    putBackup('-1 hour');

    expect((new BackupsCheck)->run()->status)->toBe(Status::ok()); // fills the cache
    expect((new BackupsCheck)->run()->status)->toBe(Status::ok()); // reads it back

    expect(app(BackupSettings::class)->status()['newestAt']->isToday())->toBeTrue();
});
