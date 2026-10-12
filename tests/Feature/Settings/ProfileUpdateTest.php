<?php

use App\Livewire\Settings\Profile;
use App\Models\TenantUser;
use Livewire\Livewire;

test('profile page is displayed', function () {
    $this->actingAs($user = TenantUser::factory()->create());

    $this->get('/settings/profile')->assertOk();
});

test('profile information can be updated', function () {
    $user = TenantUser::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test(Profile::class)
        ->set('name', 'Test User')
        ->set('email', 'test@example.com')
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    $user->refresh();

    expect($user->name)->toEqual('Test User');
    expect($user->email)->toEqual('test@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('email verification status is unchanged when email address is unchanged', function () {
    $user = TenantUser::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test(Profile::class)
        ->set('name', 'Test User')
        ->set('email', $user->email)
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('user can delete their account', function () {
    $user = TenantUser::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('settings.delete-user-form')
        ->set('password', 'password')
        ->call('deleteUser');

    $response
        ->assertHasNoErrors()
        ->assertRedirect('/');

    expect($user->fresh())->toBeNull();
    expect(auth()->check())->toBeFalse();
});

test('correct password must be provided to delete account', function () {
    $user = TenantUser::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('settings.delete-user-form')
        ->set('password', 'wrong-password')
        ->call('deleteUser');

    $response->assertHasErrors(['password']);

    expect($user->fresh())->not->toBeNull();
});

/*
 * A Client Admin's account is provisioned by Platform Admin (see
 * App\Actions\Platform\CreateClient) — this generic starter-kit settings
 * page has no policy check of its own (unlike the Users page, which goes
 * through TenantUserPolicy), so it must refuse a Client Admin directly,
 * or the Users page's own self-action guard (TenantUserPolicy::update()/
 * delete()) would be a no-op loophole.
 */

test('a client admin cannot update their own name/email via the profile settings page', function () {
    $clientAdmin = TenantUser::factory()->clientAdmin()->create(['name' => 'Original Name', 'email' => 'original@example.com']);

    $this->actingAs($clientAdmin);

    Livewire::test(Profile::class)
        ->assertSet('isLockedClientAdmin', true)
        ->set('name', 'New Name')
        ->set('email', 'new@example.com')
        ->call('updateProfileInformation');

    $clientAdmin->refresh();
    expect($clientAdmin->name)->toBe('Original Name');
    expect($clientAdmin->email)->toBe('original@example.com');
});

test('a client user is unaffected and can still update their own profile', function () {
    $clientUser = TenantUser::factory()->create();

    $this->actingAs($clientUser);

    Livewire::test(Profile::class)
        ->assertSet('isLockedClientAdmin', false)
        ->set('name', 'New Name')
        ->set('email', 'new@example.com')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect($clientUser->refresh()->name)->toBe('New Name');
});

test('a client admin cannot delete their own account via the settings delete form', function () {
    $clientAdmin = TenantUser::factory()->clientAdmin()->create();

    $this->actingAs($clientAdmin);

    Livewire::test('settings.delete-user-form')
        ->set('password', 'password')
        ->call('deleteUser');

    expect(TenantUser::find($clientAdmin->id))->not->toBeNull();
    expect(auth()->check())->toBeTrue();
});

test('a client user can still delete their own account via the settings delete form', function () {
    $clientUser = TenantUser::factory()->create();

    $this->actingAs($clientUser);

    Livewire::test('settings.delete-user-form')
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertRedirect('/');

    expect(TenantUser::find($clientUser->id))->toBeNull();
});
