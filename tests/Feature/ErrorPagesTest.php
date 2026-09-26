<?php

use App\Models\TenantUser;
use Illuminate\Support\Facades\Route;

test('a guest hitting a missing page sees the designed 404 page with a link home', function () {
    $this->get('/this-page-does-not-exist')
        ->assertNotFound()
        ->assertSee('Page not found')
        ->assertSee('Back to home')
        ->assertSee('href="'.url('/').'"', false);
});

test('a signed-in tenant user hitting a missing page is offered their dashboard', function () {
    $this->actingAs(TenantUser::factory()->create())
        ->get('/this-page-does-not-exist')
        ->assertNotFound()
        ->assertSee('Back to dashboard')
        ->assertSee('href="'.route('dashboard').'"', false);
});

test('server errors offer a retry instead of going back', function () {
    Route::get('/__error-page-test', fn () => abort(500));

    $this->get('/__error-page-test')
        ->assertInternalServerError()
        ->assertSee('Something went wrong')
        ->assertSee('Try again');
});

test('a 4xx status without its own page falls back to the generic designed page', function () {
    Route::get('/__error-page-test', fn () => abort(410));

    $this->get('/__error-page-test')
        ->assertStatus(410)
        ->assertSee('Request could not be completed')
        ->assertSee('410 · Gone');
});
