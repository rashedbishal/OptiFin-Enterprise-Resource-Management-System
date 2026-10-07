<?php

use App\Livewire\Tenant\CallingSettings\Index;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\WhatsAppCallFixtures;

/*
 * WhatsApp inbound calling — a Client-Admin-only, tenant-wide custom
 * ringtone (MP3, <=1MB). Storage::fake('local') isolates every assertion
 * from the real disk, same convention as LeadCsvImportEngineTest.
 *
 * The page itself requires a WhatsApp lead source (mount() redirects away
 * otherwise — see the dedicated test below), so every test exercising the
 * page's actual behavior sets one up via WhatsAppCallFixtures::source().
 */

beforeEach(fn () => Storage::fake('local'));

test('a client admin can upload a ringtone', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    WhatsAppCallFixtures::source($tenant);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->set('ringtoneFile', UploadedFile::fake()->create('ringtone.mp3', 500, 'audio/mpeg'))
        ->call('save')
        ->assertHasNoErrors();

    $tenant->refresh();
    expect($tenant->whatsapp_call_ringtone_path)->not->toBeNull();
    expect($tenant->whatsapp_call_ringtone_original_name)->toBe('ringtone.mp3');
    expect($tenant->whatsapp_call_ringtone_size)->toBe(500 * 1024);
    Storage::disk('local')->assertExists($tenant->whatsapp_call_ringtone_path);
});

test('uploading a new ringtone deletes the previous file from storage', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    WhatsAppCallFixtures::source($tenant);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->set('ringtoneFile', UploadedFile::fake()->create('first.mp3', 200, 'audio/mpeg'))
        ->call('save');

    $firstPath = $tenant->refresh()->whatsapp_call_ringtone_path;
    Storage::disk('local')->assertExists($firstPath);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->set('ringtoneFile', UploadedFile::fake()->create('second.mp3', 300, 'audio/mpeg'))
        ->call('save');

    $tenant->refresh();
    expect($tenant->whatsapp_call_ringtone_path)->not->toBe($firstPath);
    expect($tenant->whatsapp_call_ringtone_original_name)->toBe('second.mp3');
    Storage::disk('local')->assertMissing($firstPath);
    Storage::disk('local')->assertExists($tenant->whatsapp_call_ringtone_path);
});

test('remove deletes the file from storage and clears the tenant columns', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    WhatsAppCallFixtures::source($tenant);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->set('ringtoneFile', UploadedFile::fake()->create('ringtone.mp3', 400, 'audio/mpeg'))
        ->call('save');

    $path = $tenant->refresh()->whatsapp_call_ringtone_path;

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('remove');

    $tenant->refresh();
    expect($tenant->whatsapp_call_ringtone_path)->toBeNull();
    expect($tenant->whatsapp_call_ringtone_original_name)->toBeNull();
    expect($tenant->whatsapp_call_ringtone_size)->toBeNull();
    Storage::disk('local')->assertMissing($path);
});

test('remove without a ringtone set is a safe no-op', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    WhatsAppCallFixtures::source($tenant);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('remove')
        ->assertHasNoErrors();
});

test('a non-mp3 file is rejected', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    WhatsAppCallFixtures::source($tenant);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->set('ringtoneFile', UploadedFile::fake()->create('ringtone.wav', 500, 'audio/wav'))
        ->call('save')
        ->assertHasErrors('ringtoneFile');

    expect($tenant->refresh()->whatsapp_call_ringtone_path)->toBeNull();
});

test('a file over 1MB is rejected', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    WhatsAppCallFixtures::source($tenant);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->set('ringtoneFile', UploadedFile::fake()->create('big.mp3', 1025, 'audio/mpeg'))
        ->call('save')
        ->assertHasErrors('ringtoneFile');

    expect($tenant->refresh()->whatsapp_call_ringtone_path)->toBeNull();
});

test('a client user (non-admin) cannot open the calling settings page', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    WhatsAppCallFixtures::source($tenant);
    $clientUser = clientUserWithLeadPermission($tenant);

    Livewire::actingAs($clientUser)
        ->test(Index::class)
        ->assertForbidden();
});

test('a client admin is redirected away from the page when the tenant has no WhatsApp lead source', function () {
    ['clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->assertRedirect(route('lead-management.index'));
});
