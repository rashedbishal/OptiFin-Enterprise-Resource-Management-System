<?php

use App\Livewire\Settings\CallingApp;
use App\Models\CallingAppDevice;
use App\Models\PlatformUser;
use App\Services\CallingAppRelease;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Support\LeadCallScenario as Lc;

/*
 * Calling app APK distribution: the Mother Admin uploads the APK in chunks
 * (Admin → Calling app), and users of a company with WhatsApp calling on
 * download it from Settings → Calling app; the app's update check reads it.
 */

beforeEach(function () {
    Storage::fake('local');
});

/**
 * Bytes of a minimal valid "APK" (a ZIP with the two entries checked).
 */
function apkBytes(bool $valid = true, int $padding = 1_000_000): string
{
    $path = tempnam(sys_get_temp_dir(), 'apk');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString($valid ? 'AndroidManifest.xml' : 'readme.txt', 'manifest');
    $zip->addFromString('classes.dex', 'dex');
    // Stored, not compressed, so the file spans several chunks.
    $zip->addFromString('assets/blob.bin', random_bytes($padding));
    $zip->setCompressionName('assets/blob.bin', ZipArchive::CM_STORE);
    $zip->close();
    $bytes = (string) file_get_contents($path);
    unlink($path);

    return $bytes;
}

/**
 * Drives the same start → chunks → complete sequence the browser does.
 */
function uploadApk(PlatformUser $admin, string $bytes, string $version = '1.3.0', ?string $notes = 'Faster calls'): TestResponse
{
    $start = test()->actingAs($admin, 'platform')
        ->postJson(route('admin.settings.calling-app.apk.start'), ['size' => strlen($bytes), 'version' => $version, 'notes' => $notes])
        ->assertOk();

    $id = $start->json('data.upload_id');
    $chunk = $start->json('data.chunk_bytes');

    foreach (str_split($bytes, $chunk) as $index => $part) {
        test()->call('PUT', route('admin.settings.calling-app.apk.chunk', ['upload' => $id, 'index' => $index]), [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/octet-stream'], $part)
            ->assertOk();
    }

    return test()->postJson(route('admin.settings.calling-app.apk.complete', ['upload' => $id]));
}

test('the mother admin uploads the apk in chunks and it becomes the current release', function () {
    $mother = PlatformUser::factory()->motherAdmin()->create();
    $bytes = apkBytes();

    uploadApk($mother, $bytes)->assertOk()->assertJsonPath('data.version', '1.3.0');

    $release = app(CallingAppRelease::class)->current();
    expect($release['version'])->toBe('1.3.0')
        ->and($release['notes'])->toBe('Faster calls')
        ->and($release['size'])->toBe(strlen($bytes))
        ->and($release['sha256'])->toBe(hash('sha256', $bytes))
        ->and(Storage::disk('local')->get($release['path']))->toBe($bytes)
        ->and(Storage::disk('local')->directories('calling-app/uploads'))->toBe([]);
});

test('uploading a new version replaces and deletes the previous file', function () {
    $mother = PlatformUser::factory()->motherAdmin()->create();
    uploadApk($mother, apkBytes())->assertOk();
    $old = app(CallingAppRelease::class)->current()['path'];

    uploadApk($mother, apkBytes(), '1.4.0')->assertOk();

    expect(app(CallingAppRelease::class)->current()['version'])->toBe('1.4.0')
        ->and(Storage::disk('local')->exists($old))->toBeFalse();
});

test('a file that is not an android app is rejected with 422', function () {
    $mother = PlatformUser::factory()->motherAdmin()->create();

    uploadApk($mother, apkBytes(valid: false))
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'This is not an Android app (.apk) file.');

    expect(app(CallingAppRelease::class)->current())->toBeNull();
});

test('a version that is not like 1.3.0 is rejected with 422', function () {
    $this->actingAs(PlatformUser::factory()->motherAdmin()->create(), 'platform')
        ->postJson(route('admin.settings.calling-app.apk.start'), ['size' => 10, 'version' => 'latest'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['version' => 'Use a version like 1.3.0.']);
});

test('a damaged chunk is rejected', function () {
    $start = $this->actingAs(PlatformUser::factory()->motherAdmin()->create(), 'platform')
        ->postJson(route('admin.settings.calling-app.apk.start'), ['size' => 2_000_000, 'version' => '1.3.0'])
        ->assertOk();

    $this->call('PUT', route('admin.settings.calling-app.apk.chunk', ['upload' => $start->json('data.upload_id'), 'index' => 0]), [], [], [], ['HTTP_ACCEPT' => 'application/json'], 'too short')
        ->assertStatus(422);
});

test('a platform admin who is not the mother admin cannot upload', function () {
    $this->actingAs(PlatformUser::factory()->create(), 'platform')
        ->postJson(route('admin.settings.calling-app.apk.start'), ['size' => 10, 'version' => '1.3.0'])
        ->assertForbidden();
});

test('a user of a company with whatsapp calling on sees and downloads the app', function () {
    uploadApk(PlatformUser::factory()->motherAdmin()->create(), $bytes = apkBytes())->assertOk();
    $x = Lc::make();

    Livewire::actingAs($x->agent, 'web')->test(CallingApp::class)
        ->assertSee('Get the OptiFin Calling app')
        ->assertSee('version 1.3.0');

    $response = $this->actingAs($x->agent, 'web')->get(route('calling-app.download'));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.android.package-archive')
        ->assertDownload('OptiFin-Calling-1.3.0.apk');
    expect($response->streamedContent())->toBe($bytes);
});

test('a company without whatsapp calling switched on neither sees nor downloads the app', function () {
    uploadApk(PlatformUser::factory()->motherAdmin()->create(), apkBytes())->assertOk();
    $x = Lc::make(source: ['calling_enabled' => false]);

    Livewire::actingAs($x->agent, 'web')->test(CallingApp::class)->assertDontSee('Get the OptiFin Calling app');
    $this->actingAs($x->agent, 'web')->get(route('calling-app.download'))->assertForbidden();
});

test('downloading before any app is uploaded is a 404', function () {
    $x = Lc::make();

    $this->actingAs($x->agent, 'web')->get(route('calling-app.download'))->assertNotFound();
});

test('the app update check reports the latest version only to an eligible paired phone', function () {
    uploadApk(PlatformUser::factory()->motherAdmin()->create(), $bytes = apkBytes())->assertOk();
    $x = Lc::make();
    $device = CallingAppDevice::factory()->create(['tenant_user_id' => $x->agent->id]);
    $token = $x->agent->createToken('Calling app', ['calling-app'])->plainTextToken;
    $device->update(['personal_access_token_id' => $x->agent->tokens()->latest('id')->first()->id]);

    app('auth')->forgetGuards();
    $this->withToken($token)->getJson(route('api.calling-app.latest-version'))
        ->assertOk()
        ->assertExactJson(['data' => [
            'version' => '1.3.0',
            'notes' => 'Faster calls',
            'size_bytes' => strlen($bytes),
            'download_page_url' => route('calling-app.edit'),
        ]]);

    $x->source->update(['calling_enabled' => false]);
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson(route('api.calling-app.latest-version'))
        ->assertOk()
        ->assertExactJson(['data' => null]);
});
