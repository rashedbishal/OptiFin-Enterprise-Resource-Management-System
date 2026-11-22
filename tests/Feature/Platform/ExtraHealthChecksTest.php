<?php

use App\Enums\FailedEmailMessageStatus;
use App\Enums\LeadSourceType;
use App\Health\DatabaseSizeCheck;
use App\Health\EmailInboxesCheck;
use App\Health\FailedJobsCheck;
use App\Health\MetaTokensCheck;
use App\Models\FailedEmailMessage;
use App\Models\LeadSource;
use App\Models\LeadSourceTokenCheck;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Health\Enums\Status;
use Spatie\Health\Facades\Health;

function metaSourceFor(string $clientName, array $attributes = []): LeadSource
{
    return LeadSource::factory()->create([
        'tenant_id' => Tenant::factory()->create(['name' => $clientName]),
        'type' => LeadSourceType::Whatsapp,
        'name' => 'WhatsApp',
        'config' => ['phone_number_id' => '1', 'access_token' => 'EAAtoken'],
        'last_synced_at' => now(),
        ...$attributes,
    ]);
}

function insertFailedJobAt(CarbonInterface $failedAt): void
{
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'default',
        'payload' => '{}', 'exception' => 'RuntimeException: x', 'failed_at' => $failedAt,
    ]);
}

test('the new checks are registered on the Health page', function () {
    $labels = collect(Health::registeredChecks())->map(fn ($check) => $check->getLabel())->all();

    expect($labels)->toContain('Meta tokens', 'Email inboxes', 'Failed jobs', 'Redis memory');
});

test('alert emails are throttled to one per 6 hours', function () {
    expect(config('health.notifications.throttle_notifications_for_minutes'))->toBe(360);
});

// ── Meta tokens ────────────────────────────────────────────────────────

test('Meta tokens: failing when a token is invalid, naming the client', function () {
    $source = metaSourceFor('Acme Ltd');
    LeadSourceTokenCheck::factory()->invalid()->create(['lead_source_id' => $source->id]);

    $result = (new MetaTokensCheck)->run();

    expect($result->status)->toBe(Status::failed())
        ->and($result->getNotificationMessage())->toContain('Acme Ltd — WhatsApp');
});

test('Meta tokens: warning when a token expires within 7 days, OK otherwise', function () {
    $source = metaSourceFor('Beta Ltd');
    $check = LeadSourceTokenCheck::factory()->expiringIn(4)->create(['lead_source_id' => $source->id]);

    expect((new MetaTokensCheck)->run()->status)->toBe(Status::warning());

    $check->update(['expires_at' => now()->addDays(40)]);

    expect((new MetaTokensCheck)->run()->status)->toBe(Status::ok());
});

// ── Email inboxes ──────────────────────────────────────────────────────

test('Email inboxes: failing when one keeps failing to connect', function () {
    $source = LeadSource::factory()->create(['type' => LeadSourceType::Email, 'last_synced_at' => now()]);
    Cache::put("ops:imap-connection-failures:{$source->id}", 5);

    expect((new EmailInboxesCheck)->run()->status)->toBe(Status::failed());
});

test('Email inboxes: warning for imports that failed in the last 24 hours only', function () {
    $source = LeadSource::factory()->create(['type' => LeadSourceType::Email, 'last_synced_at' => now()]);
    $failure = FailedEmailMessage::factory()->create(['tenant_id' => $source->tenant_id, 'lead_source_id' => $source->id, 'status' => FailedEmailMessageStatus::Failed, 'first_failed_at' => now()->subHours(3)]);

    expect((new EmailInboxesCheck)->run()->status)->toBe(Status::warning());

    $failure->update(['first_failed_at' => now()->subDays(2)]);

    expect((new EmailInboxesCheck)->run()->status)->toBe(Status::ok());
});

// ── Failed jobs ────────────────────────────────────────────────────────

test('Failed jobs: OK with none, warning from the first, failing at 10 — only counting the last hour', function () {
    insertFailedJobAt(now()->subHours(3));
    expect((new FailedJobsCheck)->run()->status)->toBe(Status::ok());

    insertFailedJobAt(now()->subMinutes(5));
    expect((new FailedJobsCheck)->run()->status)->toBe(Status::warning());

    foreach (range(1, 9) as $ignored) {
        insertFailedJobAt(now()->subMinutes(10));
    }
    expect((new FailedJobsCheck)->run()->status)->toBe(Status::failed());
});

// ── Database size ──────────────────────────────────────────────────────

test('Database size: OK up to 2 GB, warning above, failing above 10 GB', function (float $gigabytes, Status $expected) {
    $check = new class($gigabytes) extends DatabaseSizeCheck
    {
        public function __construct(private float $gigabytes) {}

        protected function sizeInBytes(): int
        {
            return (int) ($this->gigabytes * 1024 ** 3);
        }
    };

    expect($check->run()->status)->toBe($expected);
})->with([
    'small' => [0.5, Status::ok()],
    'growing' => [3.0, Status::warning()],
    'too big' => [11.0, Status::failed()],
]);
