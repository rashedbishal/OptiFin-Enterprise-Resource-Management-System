<?php

use App\Enums\FailedJobAdvice;
use App\Jobs\ReconcileOutboundMessageStatus;
use App\Jobs\SendIncomingCallPushNotification;
use App\Jobs\SendOutboundMessage;
use App\Livewire\Platform\Monitoring\FailedJobs;
use App\Models\PlatformUser;
use App\Services\FailedJobService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\JobRepository;
use Livewire\Livewire;

/**
 * Inserts one row into Laravel's failed_jobs table, shaped like a real one.
 */
function insertFailedJob(string $class, string $exception, ?CarbonInterface $failedAt = null, string $connection = 'redis'): string
{
    $uuid = (string) Str::uuid();

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => $connection,
        'queue' => 'default',
        'payload' => json_encode(['uuid' => $uuid, 'displayName' => $class, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'maxTries' => 3, 'data' => ['commandName' => $class, 'command' => 'O:8:"stdClass":0:{}']]),
        'exception' => $exception."\n#0 /var/www/app/Jobs/Example.php(429): handle()\n#1 {main}",
        'failed_at' => $failedAt ?? now(),
    ]);

    return $uuid;
}

beforeEach(function () {
    // Horizon's Redis copy of each failure — never touched for real in tests.
    app()->instance(JobRepository::class, Mockery::mock(JobRepository::class, ['deleteFailed' => 1]));
});

// ── Suggestions ─────────────────────────────────────────────────────────

test('each kind of failure gets the right plain-language suggestion', function (string $class, string $exception, ?CarbonInterface $failedAt, FailedJobAdvice $expected) {
    [$advice] = app(FailedJobService::class)->advise($class, $exception, $failedAt ?? now());

    expect($advice)->toBe($expected);
})->with([
    'WhatsApp status for a message the app never sent' => [ReconcileOutboundMessageStatus::class, 'RuntimeException: No message found for external_message_id [wamid.X] on attempt 3.', null, FailedJobAdvice::Delete],
    'record deleted' => [SendOutboundMessage::class, 'Illuminate\\Database\\Eloquent\\ModelNotFoundException: No query results for model [App\\Models\\Message].', null, FailedJobAdvice::Delete],
    'network timeout, recent' => [SendOutboundMessage::class, 'Illuminate\\Http\\Client\\ConnectionException: cURL error 28: Operation timed out', null, FailedJobAdvice::Retry],
    'network timeout on a message, days old' => [SendOutboundMessage::class, 'Illuminate\\Http\\Client\\ConnectionException: cURL error 28: Operation timed out', now()->subDays(3), FailedJobAdvice::Delete],
    'Meta refused the recipient' => [SendOutboundMessage::class, 'RuntimeException: (#131026) Message undeliverable: recipient is not a valid WhatsApp user', null, FailedJobAdvice::Delete],
    'expired token' => [SendOutboundMessage::class, 'RuntimeException: OAuthException: Error validating access token: Session has expired', null, FailedJobAdvice::Delete],
    'code bug' => [SendOutboundMessage::class, 'TypeError: App\\Services\\WhatsAppSender::send(): Argument #1 must be of type string, null given', null, FailedJobAdvice::Developer],
    'stale call ringing' => [SendIncomingCallPushNotification::class, 'Illuminate\\Http\\Client\\ConnectionException: cURL error 7: Connection refused', now()->subHour(), FailedJobAdvice::Delete],
    'unknown cause' => ['App\\Jobs\\SomethingElse', 'RuntimeException: Something odd happened', null, FailedJobAdvice::Review],
]);

test('a line number in the stack trace is never mistaken for an HTTP error code', function () {
    $uuid = insertFailedJob('App\\Jobs\\SomethingElse', 'RuntimeException: Something odd happened');

    expect(app(FailedJobService::class)->find($uuid)['advice'])->toBe(FailedJobAdvice::Review);
});

// ── Page ───────────────────────────────────────────────────────────────

test('the page needs Horizon view access', function () {
    $this->actingAs(platformAdminWithMonitoring([]), 'platform')
        ->get(route('admin.monitoring.horizon.failed-jobs'))->assertForbidden();

    $this->actingAs(platformAdminWithMonitoring(['horizon' => ['view']]), 'platform')
        ->get(route('admin.monitoring.horizon.failed-jobs'))->assertOk();
});

test('the page lists failed jobs in plain language with their suggestion, including pre-Horizon ones', function () {
    insertFailedJob(ReconcileOutboundMessageStatus::class, 'RuntimeException: No message found for external_message_id [wamid.X] on attempt 3.', connection: 'database');
    $admin = platformAdminWithMonitoring(['horizon' => ['view']]);

    Livewire::actingAs($admin, 'platform')->test(FailedJobs::class)
        ->assertSee('Update a message\'s delivered / read status')
        ->assertSee('No message found for external_message_id')
        ->assertDontSee('/var/www/app/Jobs/Example.php')
        ->assertDontSeeHtml('wire:click="confirmDeleteSuggested"');
});

test('filtering by suggestion shows only matching jobs', function () {
    insertFailedJob(ReconcileOutboundMessageStatus::class, 'RuntimeException: No message found for external_message_id [wamid.X] on attempt 3.');
    insertFailedJob(SendOutboundMessage::class, 'Illuminate\\Http\\Client\\ConnectionException: cURL error 28: Operation timed out');
    $admin = platformAdminWithMonitoring(['horizon' => ['view']]);

    Livewire::actingAs($admin, 'platform')->test(FailedJobs::class)
        ->set('advice', 'retry')
        ->assertSee('cURL error 28')
        ->assertDontSee('No message found');
});

test('opening a job shows the reason and the technical details', function () {
    $uuid = insertFailedJob(ReconcileOutboundMessageStatus::class, 'RuntimeException: No message found for external_message_id [wamid.X] on attempt 3.');
    $admin = platformAdminWithMonitoring(['horizon' => ['view']]);

    Livewire::actingAs($admin, 'platform')->test(FailedJobs::class)
        ->call('showJob', $uuid)
        ->assertSet('showDetailModal', true)
        ->assertSee('Safe to delete')
        ->assertSee('Technical details');
});

test('retrying and deleting need Horizon update permission', function () {
    Queue::fake();
    $uuid = insertFailedJob(SendOutboundMessage::class, 'Illuminate\\Http\\Client\\ConnectionException: cURL error 28: Operation timed out');
    $viewer = platformAdminWithMonitoring(['horizon' => ['view']]);

    Livewire::actingAs($viewer, 'platform')->test(FailedJobs::class)->call('retry', $uuid)->assertForbidden();
    Livewire::actingAs($viewer, 'platform')->test(FailedJobs::class)->call('confirmDelete', $uuid)->assertForbidden();

    expect(DB::table('failed_jobs')->where('uuid', $uuid)->exists())->toBeTrue();
    expect(Queue::pushedRaw())->toBeEmpty();
});

test('retry puts the job back on the current queue with a fresh attempt count and clears the failure', function () {
    Queue::fake();
    $uuid = insertFailedJob(SendOutboundMessage::class, 'Illuminate\\Http\\Client\\ConnectionException: cURL error 28: Operation timed out', connection: 'database');
    $operator = platformAdminWithMonitoring(['horizon' => ['view', 'update']]);

    Livewire::actingAs($operator, 'platform')->test(FailedJobs::class)->call('retry', $uuid);

    $pushed = Queue::pushedRaw();
    expect($pushed)->toHaveCount(1);
    $payload = json_decode($pushed->first()['payload'], true);
    expect($payload['displayName'])->toBe(SendOutboundMessage::class)
        ->and($payload['attempts'])->toBe(0)
        ->and($payload['id'])->toBe($uuid);
    expect(DB::table('failed_jobs')->where('uuid', $uuid)->exists())->toBeFalse();
});

test('deleting one job removes only that failure', function () {
    $keep = insertFailedJob(SendOutboundMessage::class, 'RuntimeException: Something odd happened');
    $remove = insertFailedJob(SendOutboundMessage::class, 'RuntimeException: Something odd happened');
    $operator = platformAdminWithMonitoring(['horizon' => ['view', 'update']]);

    Livewire::actingAs($operator, 'platform')->test(FailedJobs::class)
        ->call('confirmDelete', $remove)
        ->call('deleteConfirmed');

    expect(DB::table('failed_jobs')->pluck('uuid')->all())->toBe([$keep]);
});

test('"delete all marked Delete" removes only the jobs suggested for deletion', function () {
    $staleStatus = insertFailedJob(ReconcileOutboundMessageStatus::class, 'RuntimeException: No message found for external_message_id [wamid.X] on attempt 3.');
    $retryable = insertFailedJob(SendOutboundMessage::class, 'Illuminate\\Http\\Client\\ConnectionException: cURL error 28: Operation timed out');
    $operator = platformAdminWithMonitoring(['horizon' => ['view', 'update']]);

    Livewire::actingAs($operator, 'platform')->test(FailedJobs::class)
        ->assertSeeHtml('wire:click="confirmDeleteSuggested"')
        ->call('confirmDeleteSuggested')
        ->call('deleteSuggestedConfirmed');

    expect(DB::table('failed_jobs')->pluck('uuid')->all())->toBe([$retryable])
        ->and($staleStatus)->not->toBeIn(DB::table('failed_jobs')->pluck('uuid')->all());
});

test('the Mother Admin can manage failed jobs', function () {
    $uuid = insertFailedJob(SendOutboundMessage::class, 'RuntimeException: Something odd happened');

    Livewire::actingAs(PlatformUser::factory()->motherAdmin()->create(), 'platform')->test(FailedJobs::class)
        ->call('confirmDelete', $uuid)
        ->call('deleteConfirmed');

    expect(DB::table('failed_jobs')->count())->toBe(0);
});
