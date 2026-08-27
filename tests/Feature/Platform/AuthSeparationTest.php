<?php

use App\Livewire\Platform\Auth\Login;
use App\Models\PlatformUser;
use App\Models\TenantUser;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

test('a tenant user cannot authenticate at the platform login', function () {
    $tenantUser = TenantUser::factory()->create();

    Livewire::test(Login::class)
        ->set('email', $tenantUser->email)
        ->set('password', 'password')
        ->call('authenticate')
        ->assertHasErrors(['email']);

    expect(Auth::guard('platform')->check())->toBeFalse();
});

test('a platform admin cannot authenticate at the tenant login', function () {
    $platformUser = PlatformUser::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $platformUser->email,
        'password' => 'password',
    ]);

    $response->assertSessionHasErrors('email');
    expect(Auth::guard('web')->check())->toBeFalse();
});

test('a platform admin can authenticate at the platform login', function () {
    $platformUser = PlatformUser::factory()->create();

    Livewire::test(Login::class)
        ->set('email', $platformUser->email)
        ->set('password', 'password')
        ->call('authenticate')
        ->assertHasNoErrors();

    expect(Auth::guard('platform')->id())->toBe($platformUser->id);
});
