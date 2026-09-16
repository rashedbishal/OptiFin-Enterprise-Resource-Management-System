<?php

namespace App\Exceptions;

use App\Enums\EmailPartialFailureStage;
use RuntimeException;
use Throwable;

/**
 * EMAIL-LOSS-1 — thrown by App\Jobs\ProcessEmailLeadSource::runPostPersistSteps()
 * when the Lead itself was already durably created or appended (the write
 * committed) but a step AFTER that — the LeadReceived/LeadUpdated event
 * dispatch, or attachment saving — then failed. Carries the already-resolved
 * Lead's id so the caller's failure-recording logic can distinguish this
 * case from a failure before any Lead write occurred: a retry of THIS
 * exception's failure must never re-run Lead::create()/append (which would
 * duplicate the message text), only retry the step that actually failed.
 *
 * EMAIL-LOSS-1 (event-replay fix) — $stage and $isNewLead exist so a retry
 * can tell an event-dispatch failure apart from an attachment failure
 * (previously conflated — see App\Enums\EmailPartialFailureStage's own
 * docblock) and, when the event is what failed, fire the CORRECT event
 * class (LeadReceived for a create, LeadUpdated for an append) rather than
 * guessing. $isNewLead reflects the original operation, not whatever a
 * later retry attempt does.
 */
class EmailMessagePartiallyProcessed extends RuntimeException
{
    public function __construct(
        public readonly string $leadId,
        public readonly EmailPartialFailureStage $stage,
        public readonly bool $isNewLead,
        Throwable $previous,
    ) {
        parent::__construct($previous->getMessage(), 0, $previous);
    }
}
