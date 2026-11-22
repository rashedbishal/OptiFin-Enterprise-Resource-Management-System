<?php

use App\Enums\LogLevel;
use App\Livewire\Platform\Monitoring\Logs;
use App\Models\PlatformUser;
use App\Services\Logs\LogIndex;
use App\Services\Logs\LogReader;
use App\Services\Logs\MailPreviewParser;
use App\Support\Logs\LogFile;
use App\Support\SettingsAudit;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

const SAMPLE_LARAVEL_LOG = <<<'LOG'
[2026-11-22 08:00:00] production.INFO: Lead received {"lead":"L-1"} []
[2026-11-22 08:05:00] production.ERROR: Something broke for lead 42 {"exception":"[object] (RuntimeException(code: 0): Something broke for lead 42 at /var/www/app/Services/WhatsAppSender.php:42)
[stacktrace]
#0 /var/www/app/Jobs/SendOutboundMessage.php(70): App\\Services\\WhatsAppSender->send()
#1 {main}
"} 
[2026-11-22 08:10:00] production.WARNING: Token expiring soon {"source":"WhatsApp"} []
[2026-11-22 08:15:00] production.DEBUG: From: OptiFin <a@example.com>
To: user@example.com
Subject: Reset Password Notification
MIME-Version: 1.0
Content-Type: multipart/alternative; boundary=XYZ

--XYZ
Content-Type: text/plain; charset=utf-8
Content-Transfer-Encoding: quoted-printable

Reset your password here
--XYZ
Content-Type: text/html; charset=utf-8
Content-Transfer-Encoding: quoted-printable

<p>Reset your password <a href=3D"https://x.test">here</a></p>
--XYZ--
  
LOG;

const SAMPLE_HORIZON_LOG = <<<'LOG'

   INFO  Horizon started successfully.

  2026-11-22 08:20:00 App\Jobs\SendOutboundMessage .............. RUNNING
  2026-11-22 08:20:01 App\Jobs\SendOutboundMessage .............. FAIL
LOG;

/**
 * Points the Logs page at a temporary folder of sample logs.
 */
function sampleLogs(): string
{
    $root = storage_path('framework/testing/logs-page-test/'.Str::random(8));
    File::ensureDirectoryExists($root.'/archive');
    File::put($root.'/laravel.log', SAMPLE_LARAVEL_LOG."\n");
    File::put($root.'/horizon.log', SAMPLE_HORIZON_LOG."\n");
    File::put($root.'/archive/old.log', "[2026-10-01 10:00:00] production.ERROR: An old error [] []\n");

    app()->instance(LogReader::class, new LogReader(new LogIndex($root.'/.index'), new MailPreviewParser, $root));

    return $root;
}

function sampleFile(string $relativePath): LogFile
{
    return app(LogReader::class)->find(LogFile::idFor($relativePath));
}

afterEach(function () {
    File::deleteDirectory(storage_path('framework/testing/logs-page-test'));
});

// ── Access ─────────────────────────────────────────────────────────────

test('the Logs page follows the Logs module: guest → login, no module → 403, View → OK', function () {
    sampleLogs();

    $this->get(route('admin.monitoring.logs'))->assertRedirect(route('admin.login'));
    $this->actingAs(platformAdminWithMonitoring(['health' => ['view']]), 'platform')->get(route('admin.monitoring.logs'))->assertForbidden();
    $this->actingAs(platformAdminWithMonitoring(['logs' => ['view']]), 'platform')->get(route('admin.monitoring.logs'))->assertOk()->assertSee('laravel.log');
});

test('a tenant session in the same browser never opens the Logs page', function () {
    ['clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $this->actingAs($clientAdmin, 'web')->get(route('admin.monitoring.logs'))->assertRedirect(route('admin.login'));
});

test('downloads need Update; a folder downloads as a zip', function () {
    sampleLogs();
    $file = sampleFile('laravel.log');

    $this->actingAs(platformAdminWithMonitoring(['logs' => ['view']]), 'platform')
        ->get(route('admin.monitoring.logs.download-file', $file->id))->assertForbidden();

    $this->actingAs(platformAdminWithMonitoring(['logs' => ['view', 'update']]), 'platform');
    $this->get(route('admin.monitoring.logs.download-file', $file->id))->assertOk()->assertDownload('laravel.log');
    $this->get(route('admin.monitoring.logs.download-folder', 'archive'))->assertOk()->assertDownload('archive-logs.zip');
    $this->get(route('admin.monitoring.logs.download-file', 'not-a-file'))->assertNotFound();
});

// ── Reading ────────────────────────────────────────────────────────────

test('Laravel entries are split correctly, with counts per level', function () {
    sampleLogs();

    expect(app(LogReader::class)->levelCounts(sampleFile('laravel.log')))->toBe(['info' => 1, 'error' => 1, 'warning' => 1, 'debug' => 1]);
});

test('worker logs (Horizon) become entries, with FAIL as an error', function () {
    sampleLogs();
    $page = app(LogReader::class)->page([sampleFile('horizon.log')], [LogLevel::Error], '', false, true, 1, 25);

    expect($page['total'])->toBe(1)->and($page['entries'][0]->preview)->toContain('FAIL');
});

test('an opened error shows its message, exception and stack trace', function () {
    sampleLogs();
    $reader = app(LogReader::class);
    $file = sampleFile('laravel.log');
    $error = $reader->page([$file], [LogLevel::Error], '', false, true, 1, 1)['entries'][0];

    $opened = $reader->open($file, $error->offset);

    expect($error->preview)->toBe('Something broke for lead 42')
        ->and($opened['exception'])->toContain('RuntimeException(code: 0)')
        ->and($opened['trace'])->toHaveCount(2)
        ->and($opened['trace'][0])->toStartWith('#0 /var/www/app/Jobs/SendOutboundMessage.php(70)');
});

test('extra data is shown as formatted JSON, and logged emails get a preview', function () {
    sampleLogs();
    $reader = app(LogReader::class);
    $file = sampleFile('laravel.log');

    $warning = $reader->open($file, $reader->page([$file], [LogLevel::Warning], '', false, true, 1, 1)['entries'][0]->offset);
    $email = $reader->open($file, $reader->page([$file], [LogLevel::Debug], '', false, true, 1, 1)['entries'][0]->offset);

    expect($warning['context'])->toContain('"source": "WhatsApp"');
    expect($email['mail'])->toMatchArray(['subject' => 'Reset Password Notification', 'to' => 'user@example.com'])
        ->and($email['mail']['html'])->toContain('<a href="https://x.test">here</a>');
});

test('search: plain text, across all files, and a clear message for a broken regex', function () {
    sampleLogs();
    $reader = app(LogReader::class);

    expect($reader->page([sampleFile('laravel.log')], [], 'lead 42', false, true, 1, 25)['total'])->toBe(1);
    expect($reader->page($reader->files(), [], 'error', false, true, 1, 25)['total'])->toBe(2); // laravel.log + archive/old.log
    expect($reader->page([sampleFile('laravel.log')], [], 'lead \d+', true, true, 1, 25)['total'])->toBe(1);
    expect($reader->page([sampleFile('laravel.log')], [], '(unclosed', true, true, 1, 25)['error'])->toBe('That is not a valid regular expression.');
});

test('newest first or oldest first', function () {
    sampleLogs();
    $reader = app(LogReader::class);
    $file = sampleFile('laravel.log');

    expect($reader->page([$file], [], '', false, true, 1, 1)['entries'][0]->level)->toBe(LogLevel::Debug);
    expect($reader->page([$file], [], '', false, false, 1, 1)['entries'][0]->level)->toBe(LogLevel::Info);
});

test('a growing file is picked up without a rescan, and a replaced file is read again', function () {
    $root = sampleLogs();
    File::append($root.'/laravel.log', "[2026-11-22 09:00:00] production.ERROR: A new error [] []\n");

    expect(app(LogReader::class)->levelCounts(sampleFile('laravel.log'))['error'])->toBe(2);

    File::put($root.'/laravel.log', "[2026-11-22 10:00:00] production.INFO: Fresh file [] []\n");

    expect(app(LogReader::class)->levelCounts(sampleFile('laravel.log')))->toBe(['info' => 1]);
});

// ── Page ───────────────────────────────────────────────────────────────

test('the page filters by level, and a shared link opens that entry', function () {
    sampleLogs();
    $admin = platformAdminWithMonitoring(['logs' => ['view']]);
    $file = sampleFile('laravel.log');
    $error = app(LogReader::class)->page([$file], [LogLevel::Error], '', false, true, 1, 1)['entries'][0];

    Livewire::actingAs($admin, 'platform')->test(Logs::class, ['file' => $file->id])
        ->set('perPage', '50')
        ->assertSet('perPage', 50)
        ->call('toggleLevel', 'error')
        ->assertSee('Something broke for lead 42')
        ->assertDontSee('Token expiring soon');

    Livewire::withQueryParams(['file' => $file->id, 'entry' => $error->offset])
        ->actingAs($admin, 'platform')
        ->test(Logs::class)
        ->assertSet('showEntryModal', true)
        ->assertSee('RuntimeException(code: 0)');
});

test('deleting needs Update, removes only the chosen files, and is recorded in the Activity log', function () {
    $root = sampleLogs();
    $viewer = platformAdminWithMonitoring(['logs' => ['view']]);
    $operator = platformAdminWithMonitoring(['logs' => ['view', 'update']]);

    Livewire::actingAs($viewer, 'platform')->test(Logs::class)->call('confirmDelete', 'folder:archive')->assertForbidden();

    Livewire::actingAs($operator, 'platform')->test(Logs::class)
        ->call('confirmDelete', 'folder:archive')
        ->call('deleteConfirmed');

    expect(File::exists($root.'/archive/old.log'))->toBeFalse()
        ->and(File::exists($root.'/laravel.log'))->toBeTrue();

    $record = Activity::query()->where('log_name', SettingsAudit::LOG_NAME)->latest('id')->firstOrFail();
    expect($record->properties->get('area'))->toBe('Logs')
        ->and($record->properties->get('details')['files'])->toBe('archive/old.log')
        ->and($record->causer->is($operator))->toBeTrue();
});

test('the top level is never deleted as a "folder"', function () {
    $root = sampleLogs();

    Livewire::actingAs(PlatformUser::factory()->motherAdmin()->create(), 'platform')->test(Logs::class)
        ->call('confirmDelete', 'folder:')
        ->call('deleteConfirmed');

    expect(File::exists($root.'/laravel.log'))->toBeTrue();
});

test('selected files can be deleted together', function () {
    $root = sampleLogs();

    Livewire::actingAs(PlatformUser::factory()->motherAdmin()->create(), 'platform')->test(Logs::class)
        ->call('toggleSelected', sampleFile('laravel.log')->id)
        ->call('toggleSelected', sampleFile('horizon.log')->id)
        ->call('confirmDelete', 'selected')
        ->call('deleteConfirmed');

    expect(File::exists($root.'/laravel.log'))->toBeFalse()
        ->and(File::exists($root.'/horizon.log'))->toBeFalse()
        ->and(File::exists($root.'/archive/old.log'))->toBeTrue();
});
