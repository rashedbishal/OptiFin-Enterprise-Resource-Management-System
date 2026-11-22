<?php

use App\Models\PlatformUser;
use Illuminate\Support\Facades\Gate;
use Opcodes\LogViewer\LogFile;

test('a guest is sent to the admin login', function () {
    $this->get(route('log-viewer.index'))->assertRedirect(route('admin.login'));
});

test('View opens the log viewer, but only Update may download or delete log files', function () {
    $viewer = platformAdminWithMonitoring(['logs' => ['view']]);
    $operator = platformAdminWithMonitoring(['logs' => ['view', 'update']]);
    $file = new LogFile(storage_path('logs/laravel.log'));

    $this->actingAs($viewer, 'platform')->get(route('log-viewer.index'))->assertOk();
    foreach (['downloadLogFile', 'deleteLogFile'] as $ability) {
        expect(Gate::allows($ability, $file))->toBeFalse();
    }

    $this->actingAs($operator, 'platform');
    foreach (['downloadLogFile', 'deleteLogFile'] as $ability) {
        expect(Gate::allows($ability, $file))->toBeTrue();
    }
});

test('the log viewer API refuses admins without the Logs module', function () {
    $this->actingAs(platformAdminWithMonitoring(['health' => ['view']]), 'platform')
        ->getJson(route('log-viewer.files'), ['referer' => config('app.url')])
        ->assertForbidden();
});

test('a tenant session in the same browser never opens the log viewer', function () {
    ['clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $this->actingAs($clientAdmin, 'web')->get(route('log-viewer.index'))->assertRedirect(route('admin.login'));
});

test('the Mother Admin may delete log files', function () {
    $this->actingAs(PlatformUser::factory()->motherAdmin()->create(), 'platform');

    expect(Gate::allows('deleteLogFile', new LogFile(storage_path('logs/laravel.log'))))->toBeTrue();
});
