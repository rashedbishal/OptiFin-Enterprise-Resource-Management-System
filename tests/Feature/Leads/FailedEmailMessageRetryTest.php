<?php

use App\Enums\EmailPartialFailureStage;
use App\Enums\FailedEmailMessageStatus;
use App\Enums\LeadSourceType;
use App\Exceptions\EmailMessagePartiallyProcessed;
use App\Jobs\ProcessEmailLeadSource;
use App\Models\FailedEmailMessage;
use App\Models\Lead;
use App\Models\LeadSource;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;

/**
 * EMAIL-LOSS-1 — deterministic coverage for the durable failure/retry
 * logic that does NOT require a real IMAP\Connection (see this finding's
 * own Decision Brief §4: that class is final/internal and can only be
 * constructed by a genuine imap_open(), which no fake object can satisfy).
 * Full end-to-end handle()/createLeadFromEmail()/handleRetries()/
 * runPostPersistSteps() coverage is therefore manual/operational only —
 * not claimed here. This same constraint applies to the event-replay fix's
 * own tryRecordNewFailure()/retryOneMessage()/runPostPersistSteps() — only
 * the pieces of the new state machine that don't take a Connection
 * parameter (recordRetryFailure(), eventAlreadyDispatchedFor()) are
 * directly, deterministically testable here.
 */
function retryFailure(FailedEmailMessage $record, string $reason, ?string $partialLeadId = null, ?EmailPartialFailureStage $stage = null): void
{
    $job = new ProcessEmailLeadSource($record->leadSource);
    $method = new ReflectionMethod($job, 'recordRetryFailure');
    $method->invoke($job, $record, $reason, $partialLeadId, $stage);
}

function exhaustFailure(FailedEmailMessage $record, string $reason): void
{
    $job = new ProcessEmailLeadSource($record->leadSource);
    $method = new ReflectionMethod($job, 'exhaustRecord');
    $method->invoke($job, $record, $reason);
}

function eventAlreadyDispatchedFor(FailedEmailMessage $record): bool
{
    $job = new ProcessEmailLeadSource($record->leadSource);
    $method = new ReflectionMethod($job, 'eventAlreadyDispatchedFor');

    return $method->invoke($job, $record);
}

test('a due failed record is selected and dispatched for retry', function () {
    Bus::fake();
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Email, 'is_active' => true]);
    $record = FailedEmailMessage::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_source_id' => $source->id,
        'next_retry_at' => now()->subMinute(),
    ]);

    $this->artisan('leads:retry-failed-emails')->assertExitCode(Command::SUCCESS);

    Bus::assertDispatched(ProcessEmailLeadSource::class, fn ($job) => $job->source->is($source) && $job->retryFailedMessageIds === [$record->id]);
});

test('a record not yet due is not selected', function () {
    Bus::fake();
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Email, 'is_active' => true]);
    FailedEmailMessage::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_source_id' => $source->id,
        'next_retry_at' => now()->addMinutes(10),
    ]);

    $this->artisan('leads:retry-failed-emails')->assertExitCode(Command::SUCCESS);

    Bus::assertNotDispatched(ProcessEmailLeadSource::class);
});

test('recovered and exhausted records are never selected for retry', function () {
    Bus::fake();
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Email, 'is_active' => true]);
    FailedEmailMessage::factory()->recovered()->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id]);
    FailedEmailMessage::factory()->exhausted()->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id]);

    $this->artisan('leads:retry-failed-emails')->assertExitCode(Command::SUCCESS);

    Bus::assertNotDispatched(ProcessEmailLeadSource::class);
});

test('multiple due records for the same source are dispatched together in one job', function () {
    Bus::fake();
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Email, 'is_active' => true]);
    $recordA = FailedEmailMessage::factory()->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id, 'next_retry_at' => now()->subMinute()]);
    $recordB = FailedEmailMessage::factory()->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id, 'next_retry_at' => now()->subMinute()]);

    $this->artisan('leads:retry-failed-emails')->assertExitCode(Command::SUCCESS);

    Bus::assertDispatchedTimes(ProcessEmailLeadSource::class, 1);
    Bus::assertDispatched(ProcessEmailLeadSource::class, function ($job) use ($source, $recordA, $recordB) {
        return $job->source->is($source)
            && in_array($recordA->id, $job->retryFailedMessageIds, true)
            && in_array($recordB->id, $job->retryFailedMessageIds, true);
    });
});

test('due records for an inactive source are skipped without dispatching', function () {
    Bus::fake();
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Email, 'is_active' => false]);
    FailedEmailMessage::factory()->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id, 'next_retry_at' => now()->subMinute()]);

    $this->artisan('leads:retry-failed-emails')->assertExitCode(Command::SUCCESS);

    Bus::assertNotDispatched(ProcessEmailLeadSource::class);
});

test('the --limit option bounds how many due records are considered in one invocation', function () {
    Bus::fake();
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Email, 'is_active' => true]);

    foreach (range(1, 5) as $i) {
        FailedEmailMessage::factory()->create([
            'tenant_id' => $tenant->id,
            'lead_source_id' => $source->id,
            'next_retry_at' => now()->subMinutes(10 - $i),
        ]);
    }

    $this->artisan('leads:retry-failed-emails', ['--limit' => 2])->assertExitCode(Command::SUCCESS);

    Bus::assertDispatched(ProcessEmailLeadSource::class, fn ($job) => count($job->retryFailedMessageIds) === 2);
});

test('a retry failure under the ceiling increments attempts and stays retryable with backoff', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Email]);
    $record = FailedEmailMessage::factory()->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id, 'attempts' => 1]);

    retryFailure($record, 'a transient failure happened again');

    $record->refresh();
    expect($record->status)->toBe(FailedEmailMessageStatus::Failed);
    expect($record->attempts)->toBe(2);
    expect($record->failure_reason)->toBe('a transient failure happened again');
    expect($record->next_retry_at)->not->toBeNull();
    expect($record->next_retry_at->isFuture())->toBeTrue();
});

test('a retry failure that reaches the ceiling is marked exhausted with no further retry scheduled', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Email]);
    $record = FailedEmailMessage::factory()->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id, 'attempts' => 4]);

    retryFailure($record, 'still failing');

    $record->refresh();
    expect($record->status)->toBe(FailedEmailMessageStatus::Exhausted);
    expect($record->attempts)->toBe(5);
    expect($record->next_retry_at)->toBeNull();
});

test('a partial-lead id passed to a retry failure is recorded, so a subsequent retry knows to skip re-creating the Lead', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Email]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $record = FailedEmailMessage::factory()->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id, 'partial_lead_id' => null]);

    retryFailure($record, 'attachment save failed', partialLeadId: $lead->id);

    expect($record->refresh()->partial_lead_id)->toBe($lead->id);
});

test('exhaustRecord marks a record terminal immediately, without waiting for the attempt ceiling', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Email]);
    $record = FailedEmailMessage::factory()->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id, 'attempts' => 1]);

    exhaustFailure($record, 'the mailbox UID validity changed since this failure was recorded');

    $record->refresh();
    expect($record->status)->toBe(FailedEmailMessageStatus::Exhausted);
    expect($record->failure_reason)->toBe('the mailbox UID validity changed since this failure was recorded');
    expect($record->next_retry_at)->toBeNull();
});

test('two different lead sources can independently record a failure at the same uid/uidvalidity', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $sourceA = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Email]);
    $sourceB = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Email]);

    FailedEmailMessage::factory()->create(['tenant_id' => $tenant->id, 'lead_source_id' => $sourceA->id, 'imap_uid' => 42, 'uidvalidity' => 1]);
    FailedEmailMessage::factory()->create(['tenant_id' => $tenant->id, 'lead_source_id' => $sourceB->id, 'imap_uid' => 42, 'uidvalidity' => 1]);

    expect(FailedEmailMessage::where('imap_uid', 42)->where('uidvalidity', 1)->count())->toBe(2);
});

test('the same lead source cannot record two failures at the same uid/uidvalidity', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Email]);

    FailedEmailMessage::factory()->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id, 'imap_uid' => 7, 'uidvalidity' => 1]);

    expect(fn () => FailedEmailMessage::factory()->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id, 'imap_uid' => 7, 'uidvalidity' => 1]))
        ->toThrow(QueryException::class);
});

test('a FailedEmailMessage casts its status and timestamps correctly', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Email]);
    $record = FailedEmailMessage::factory()->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id]);

    expect($record->status)->toBeInstanceOf(FailedEmailMessageStatus::class);
    expect($record->first_failed_at)->toBeInstanceOf(CarbonInterface::class);
    expect($record->leadSource->is($source))->toBeTrue();
    expect($record->tenant->is($tenant))->toBeTrue();
});

// EMAIL-LOSS-1 event-replay fix

test('EmailMessagePartiallyProcessed carries the lead id, failure stage, and original create/append flag', function () {
    $previous = new RuntimeException('the underlying cause');

    $exception = new EmailMessagePartiallyProcessed('lead-123', EmailPartialFailureStage::Event, true, $previous);

    expect($exception->leadId)->toBe('lead-123');
    expect($exception->stage)->toBe(EmailPartialFailureStage::Event);
    expect($exception->isNewLead)->toBeTrue();
    expect($exception->getMessage())->toBe('the underlying cause');
    expect($exception->getPrevious())->toBe($previous);
});

test('a record whose stage is Attachments means the event already dispatched, so it must not be re-fired', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Email]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $record = FailedEmailMessage::factory()->partiallyProcessed(EmailPartialFailureStage::Attachments, $lead->id)
        ->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id]);

    expect(eventAlreadyDispatchedFor($record))->toBeTrue();
});

test('a record whose stage is Event means the event never dispatched, so it must be re-fired', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Email]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $record = FailedEmailMessage::factory()->partiallyProcessed(EmailPartialFailureStage::Event, $lead->id)
        ->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id]);

    expect(eventAlreadyDispatchedFor($record))->toBeFalse();
});

test('a record with no recorded stage at all defaults to re-firing the event, never silently skipping it', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Email]);
    $record = FailedEmailMessage::factory()->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id, 'partial_stage' => null]);

    expect(eventAlreadyDispatchedFor($record))->toBeFalse();
});

test('a retry failure that passes an explicit stage updates partial_stage', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Email]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $record = FailedEmailMessage::factory()->partiallyProcessed(EmailPartialFailureStage::Event, $lead->id)
        ->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id, 'attempts' => 1]);

    // Simulates: the retried event dispatch succeeded this time, but
    // attachments then failed — the stage must advance from Event to
    // Attachments so the NEXT retry knows not to re-fire the event again.
    retryFailure($record, 'attachment save failed on retry', partialLeadId: $lead->id, stage: EmailPartialFailureStage::Attachments);

    $record->refresh();
    expect($record->partial_stage)->toBe(EmailPartialFailureStage::Attachments);
    expect($record->status)->toBe(FailedEmailMessageStatus::Failed);
    expect($record->attempts)->toBe(2);
});

test('a retry failure with no stage argument leaves the existing partial_stage unchanged', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Email]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $record = FailedEmailMessage::factory()->partiallyProcessed(EmailPartialFailureStage::Attachments, $lead->id)
        ->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id, 'attempts' => 1]);

    // Simulates: the attachment retry itself failed again (event stays
    // known-dispatched — stage must NOT revert to Event or be cleared).
    retryFailure($record, 'attachment save failed again');

    $record->refresh();
    expect($record->partial_stage)->toBe(EmailPartialFailureStage::Attachments);
});

test('an event-stage failure still reaches the retry ceiling and becomes exhausted like any other failure', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Email]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $record = FailedEmailMessage::factory()->partiallyProcessed(EmailPartialFailureStage::Event, $lead->id)
        ->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id, 'attempts' => 4]);

    retryFailure($record, 'the event dispatch keeps failing', partialLeadId: $lead->id, stage: EmailPartialFailureStage::Event);

    $record->refresh();
    expect($record->status)->toBe(FailedEmailMessageStatus::Exhausted);
    expect($record->attempts)->toBe(5);
    expect($record->next_retry_at)->toBeNull();
    // The Lead itself was never lost or duplicated by exhausting this
    // record — it still exists, unaffected, exactly as it was.
    expect(Lead::whereKey($lead->id)->exists())->toBeTrue();
});

test('FailedEmailMessage casts partial_stage and is_new_lead correctly', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Email]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $record = FailedEmailMessage::factory()->partiallyProcessed(EmailPartialFailureStage::Event, $lead->id, isNewLead: false)
        ->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id]);

    expect($record->partial_stage)->toBeInstanceOf(EmailPartialFailureStage::class);
    expect($record->partial_stage)->toBe(EmailPartialFailureStage::Event);
    expect($record->is_new_lead)->toBeFalse();
    expect($record->partialLead->is($lead))->toBeTrue();
});

test('a record with no partial failure at all has null partial_stage and is_new_lead', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Email]);
    $record = FailedEmailMessage::factory()->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id]);

    expect($record->partial_stage)->toBeNull();
    expect($record->is_new_lead)->toBeNull();
    expect($record->partial_lead_id)->toBeNull();
});
