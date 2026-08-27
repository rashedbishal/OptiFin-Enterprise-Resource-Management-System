<?php

use App\Enums\PlatformUserType;
use App\Models\PlatformUser;
use Illuminate\Database\QueryException;

test('only one mother admin can ever exist', function () {
    PlatformUser::factory()->motherAdmin()->create(['email' => 'first@example.com']);

    expect(fn () => PlatformUser::factory()->motherAdmin()->create(['email' => 'second@example.com']))
        ->toThrow(QueryException::class);
});

test('the mother admin cannot be deleted', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    expect($motherAdmin->delete())->toBeFalse();
    expect(PlatformUser::query()->whereKey($motherAdmin->id)->exists())->toBeTrue();
});

test('the mother admin cannot be deactivated', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    $motherAdmin->update(['is_active' => false]);

    expect($motherAdmin->fresh()->is_active)->toBeTrue();
});

test('a regular platform admin can be deleted', function () {
    $admin = PlatformUser::factory()->create(['user_type' => PlatformUserType::PlatformAdmin]);

    expect($admin->delete())->toBeTrue();
    expect(PlatformUser::query()->whereKey($admin->id)->exists())->toBeFalse();
});
