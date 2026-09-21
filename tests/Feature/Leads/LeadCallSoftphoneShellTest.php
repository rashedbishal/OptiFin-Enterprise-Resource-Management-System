<?php

use App\Models\Tenant;
use App\Models\TenantUser;
use Tests\Support\LeadCallScenario as Lc;
use Tests\Support\WhatsAppCallFixtures as Wa;

/*
 * The softphone shell in the tenant layout: rendered (and persisted across
 * wire:navigate) only for users who can take calls, and carrying nothing secret.
 */

test('a user who can take calls gets the softphone shell, wired to their own channel and the call routes', function () {
    $x = Lc::make();

    $response = $this->actingAs($x->agent)->get(route('dashboard'));

    $response->assertOk()->assertSee('data-test="softphone"', false);

    $html = $response->getContent();
    expect($html)
        ->toContain('softphone(')
        ->toContain($x->agent->id)
        ->toContain('__CALL__')
        ->toContain('x-ref="remoteAudio"')
        // The URLs sit inside an escaped JSON literal (slashes come out as \\/ or \\\/).
        ->toMatch('~lead-management\\\\+/calls\\\\+/active~')
        ->toMatch('~lead-management\\\\+/calls\\\\+/__CALL__\\\\+/claim~');
});

test('the shell is persisted so a live call survives navigation', function () {
    $x = Lc::make();

    $html = $this->actingAs($x->agent)->get(route('dashboard'))->getContent();

    // Livewire's @persist wraps the shell in persistence markers, exactly like the toast group.
    expect($html)->toContain('x-persist="softphone"')->toContain('x-persist="toast"');
    expect(substr_count($html, 'data-test="softphone"'))->toBe(1);
});

test('the shell exposes the call routes and copy but no credential, token, secret or phone number id', function () {
    $x = Lc::make();

    $html = $this->actingAs($x->agent)->get(route('dashboard'))->getContent();

    expect($html)
        ->not->toContain(Lc::TOKEN)
        ->not->toContain(Wa::APP_SECRET)
        ->not->toContain(Wa::PHONE_NUMBER_ID)
        ->not->toContain('access_token')
        ->not->toContain('app_secret')
        ->not->toContain('v=0');
});

test('a user without the lead-management update permission gets no softphone', function () {
    $x = Lc::make();
    $viewOnly = clientUserWithLeadPermission($x->tenant, ['view']);

    $this->actingAs($viewOnly)->get(route('dashboard'))->assertOk()->assertDontSee('data-test="softphone"', false);
});

test('a tenant without the lead-management module gets no softphone', function () {
    $tenant = Tenant::factory()->create();
    $user = TenantUser::factory()->create(['tenant_id' => $tenant->id]);

    $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertDontSee('data-test="softphone"', false);
});

test('the built frontend ships the softphone code without the Reverb secret', function () {
    $manifestPath = public_path('build/manifest.json');

    if (! is_file($manifestPath)) {
        $this->markTestSkipped('The frontend has not been built.');
    }

    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    $bundle = (string) file_get_contents(public_path('build/'.$manifest['resources/js/app.js']['file']));

    expect($bundle)->toContain('call.updated')->toContain('whatsapp-softphone')->not->toContain('REVERB_APP_SECRET');
});
