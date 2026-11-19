<?php

use App\Livewire\Platform\Settings\CallingApp;
use App\Models\PlatformSetting;
use App\Models\PlatformUser;
use App\Services\CallingAppFirebase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Kreait\Firebase\Contract\Messaging;
use Livewire\Livewire;

/*
 * Admin → Calling app: the Mother Admin uploads the calling app's Firebase
 * service-account key (platform-wide, encrypted at rest) instead of putting
 * it in the server's .env.
 */

/**
 * @return array<string, string>
 */
function cfAccount(string $projectId = 'optifin-calling'): array
{
    return [
        'type' => 'service_account',
        'project_id' => $projectId,
        'private_key_id' => 'test',
        'private_key' => "-----BEGIN PRIVATE KEY-----\nSECRET-KEY-BODY\n-----END PRIVATE KEY-----\n",
        'client_email' => "firebase-adminsdk@{$projectId}.iam.gserviceaccount.com",
        'client_id' => '1',
    ];
}

function cfUpload(array $account): UploadedFile
{
    return UploadedFile::fake()->createWithContent('service-account.json', json_encode($account));
}

test('the mother admin uploads the key, which is stored encrypted and summarised without the private key', function () {
    $mother = PlatformUser::factory()->motherAdmin()->create();

    Livewire::actingAs($mother, 'platform')->test(CallingApp::class)
        ->set('keyFile', cfUpload(cfAccount()))
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('optifin-calling')
        ->assertSee('firebase-adminsdk@optifin-calling.iam.gserviceaccount.com')
        ->assertDontSee('SECRET-KEY-BODY');

    $raw = DB::table('platform_settings')->where('key', CallingAppFirebase::SETTING_KEY)->value('value');

    expect($raw)->not->toContain('SECRET-KEY-BODY')
        ->and(app(CallingAppFirebase::class)->isConfigured())->toBeTrue()
        ->and(PlatformSetting::query()->sole()->updated_by)->toBe($mother->id);
});

test('a key for a different Firebase project is rejected', function () {
    Livewire::actingAs(PlatformUser::factory()->motherAdmin()->create(), 'platform')->test(CallingApp::class)
        ->set('keyFile', cfUpload(cfAccount('someone-else')))
        ->call('save')
        ->assertHasErrors(['keyFile' => 'This key belongs to Firebase project "someone-else", but the OptiFin Calling app uses "optifin-calling".']);

    expect(app(CallingAppFirebase::class)->isConfigured())->toBeFalse();
});

test('a file that is not a service account key is rejected', function () {
    Livewire::actingAs(PlatformUser::factory()->motherAdmin()->create(), 'platform')->test(CallingApp::class)
        ->set('keyFile', cfUpload(['project_info' => ['project_id' => 'optifin-calling']]))
        ->call('save')
        ->assertHasErrors(['keyFile']);

    expect(app(CallingAppFirebase::class)->isConfigured())->toBeFalse();
});

test('the mother admin removes the key', function () {
    $mother = PlatformUser::factory()->motherAdmin()->create();
    app(CallingAppFirebase::class)->store(cfAccount(), $mother);

    Livewire::actingAs($mother, 'platform')->test(CallingApp::class)
        ->call('confirmRemove')
        ->call('remove');

    expect(app(CallingAppFirebase::class)->isConfigured())->toBeFalse();
});

test('a platform admin who is not the mother admin cannot open the page', function () {
    $this->actingAs(PlatformUser::factory()->create(), 'platform')
        ->get(route('admin.settings.calling-app'))
        ->assertForbidden();
});

test('the firebase client is built from the uploaded key and refuses when none is configured', function () {
    expect(fn () => app(Messaging::class))->toThrow(RuntimeException::class, 'The calling app Firebase key is not configured');

    app(CallingAppFirebase::class)->store(cfAccount(), PlatformUser::factory()->motherAdmin()->create());

    // Building the client is offline; it only parses the stored key.
    expect(app(Messaging::class))->toBeInstanceOf(Messaging::class);
});
