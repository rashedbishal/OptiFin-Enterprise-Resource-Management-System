<?php

use App\Livewire\Platform\Settings\ActivityLog;
use App\Models\PlatformUser;
use App\Models\Role;
use App\Models\Tenant;
use App\Services\BackupSettings;
use App\Services\MailSettings;
use App\Support\SettingsAudit;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

function mailAccountValues(): array
{
    return [
        'host' => 'smtp.gmail.com', 'port' => 587, 'encryption' => 'tls', 'username' => 'a@gmail.com',
        'password' => 'super-secret-app-password', 'from_address' => 'a@gmail.com', 'from_name' => 'OptiFin',
    ];
}

test('only the Mother Admin can open the Activity log', function () {
    $this->get(route('admin.settings.activity-log'))->assertRedirect(route('admin.login'));
    $this->actingAs(PlatformUser::factory()->create(), 'platform')->get(route('admin.settings.activity-log'))->assertForbidden();
    $this->actingAs(PlatformUser::factory()->motherAdmin()->create(), 'platform')->get(route('admin.settings.activity-log'))->assertOk();
});

test('changes show who made them, what changed, and the old and new values', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create(['name' => 'Rashed Owner']);
    $tenant = Tenant::factory()->create(['name' => 'Acme Old Name']);
    activity()->causedBy($motherAdmin)->performedOn($tenant)->event('updated')
        ->withProperties(['old' => ['name' => 'Acme Old Name'], 'attributes' => ['name' => 'Acme New Name']])
        ->log('updated');

    Livewire::actingAs($motherAdmin, 'platform')->test(ActivityLog::class)
        ->assertSee('Rashed Owner')
        ->assertSee('Client: Acme Old Name')
        ->assertSeeInOrder(['Acme Old Name', '→', 'Acme New Name']);
});

test('filters narrow the list by kind and by client', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $acme = Tenant::factory()->create(['name' => 'Acme Ltd']);
    $other = Tenant::factory()->create(['name' => 'Other Ltd']);
    $role = Role::create(['tenant_id' => $other->id, 'name' => 'Sales Team', 'slug' => 'sales-team']);
    activity()->causedBy($motherAdmin)->performedOn($acme)->event('updated')->withProperties(['attributes' => ['status' => 'active']])->log('updated');
    activity()->causedBy($motherAdmin)->performedOn($role)->event('created')->withProperties(['attributes' => ['name' => 'Sales Team']])->log('created');

    Livewire::actingAs($motherAdmin, 'platform')->test(ActivityLog::class)
        ->set('kind', 'roles')
        ->assertSee('Role: Sales Team')
        ->assertDontSee('Client: Acme Ltd')
        ->set('kind', '')
        ->set('client', $acme->id)
        ->assertSee('Client: Acme Ltd')
        ->assertDontSee('Role: Sales Team');
});

// ── Settings changes are now recorded ──────────────────────────────────

test('saving Email settings is recorded without the password', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    app(MailSettings::class)->store(MailSettings::USER_EMAILS, mailAccountValues(), $motherAdmin);

    $entry = Activity::query()->where('log_name', SettingsAudit::LOG_NAME)->latest('id')->firstOrFail();
    expect($entry->event)->toBe('saved')
        ->and($entry->causer->is($motherAdmin))->toBeTrue()
        ->and($entry->properties->get('area'))->toBe('Email settings — User emails')
        ->and($entry->properties->get('details'))->toMatchArray(['host' => 'smtp.gmail.com', 'password changed' => true]);
    expect(json_encode(DB::table('activity_log')->get()))->not->toContain('super-secret-app-password');
});

test('saving and removing Backup settings is recorded without the key or password', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $this->actingAs($motherAdmin, 'platform');

    app(BackupSettings::class)->store(['key_id' => '005abc', 'application_key' => 'K005-secret', 'bucket' => 'b', 'endpoint' => 's3.us-east-005.backblazeb2.com', 'password' => 'archive-secret-pw'], $motherAdmin);
    app(BackupSettings::class)->remove();

    expect(Activity::query()->where('log_name', SettingsAudit::LOG_NAME)->pluck('event')->all())->toBe(['saved', 'removed']);
    expect(json_encode(DB::table('activity_log')->get()))->not->toContain('K005-secret')->not->toContain('archive-secret-pw');
});

// ── Deleting old entries ───────────────────────────────────────────────

test('deleting old entries needs the Mother Admin\'s password', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create(['password' => 'correct-password']);
    activity()->causedBy($motherAdmin)->log('old entry');
    Activity::query()->where('description', 'old entry')->update(['created_at' => now()->subYear()]);
    $before = Activity::query()->count();

    Livewire::actingAs($motherAdmin, 'platform')->test(ActivityLog::class)
        ->call('openDeleteModal')
        ->set('deleteBefore', now()->subMonth()->toDateString())
        ->set('password', 'wrong-password')
        ->call('deleteOlder')
        ->assertHasErrors('password');

    expect(Activity::query()->count())->toBe($before);
});

test('deleting removes only older entries, records the deletion, and never deletes deletion records', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create(['password' => 'correct-password']);
    activity()->causedBy($motherAdmin)->log('old entry');
    Activity::query()->where('description', 'old entry')->update(['created_at' => now()->subYear()]);
    activity()->causedBy($motherAdmin)->log('recent entry');

    $page = Livewire::actingAs($motherAdmin, 'platform')->test(ActivityLog::class)
        ->call('openDeleteModal')
        ->set('deleteBefore', now()->subMonth()->toDateString())
        ->assertSet('deleteCount', 1)
        ->set('password', 'correct-password')
        ->call('deleteOlder')
        ->assertHasNoErrors();

    expect(Activity::query()->pluck('description')->all())->toContain('recent entry')->not->toContain('old entry');
    $deletionRecord = Activity::query()->where('log_name', ActivityLog::PERMANENT_LOG)->firstOrFail();
    expect($deletionRecord->properties->get('deleted entries'))->toBe(1);

    // Even once it is old, a deletion record survives the next deletion.
    $deletionRecord->update(['created_at' => now()->subYears(2)]);
    $page->call('openDeleteModal')->set('deleteBefore', now()->toDateString())->set('password', 'correct-password')->call('deleteOlder');

    expect(Activity::query()->where('log_name', ActivityLog::PERMANENT_LOG)->count())->toBe(2);
});
