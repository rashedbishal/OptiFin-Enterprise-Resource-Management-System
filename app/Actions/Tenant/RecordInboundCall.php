<?php

namespace App\Actions\Tenant;

use App\Enums\CallEndReason;
use App\Enums\CallStatus;
use App\Enums\MessageChannel;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Models\LeadSource;
use App\Services\CallRouter;
use App\Support\LeadCallBroadcaster;
use App\Support\WhatsAppCallEvent;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WhatsApp inbound calling (Step 2) — the single writer of lead_calls for the
 * inbound `connect` and `terminate` webhooks. Everything about the call's
 * durable state lives here; the webhook controller only validates the request,
 * applies the source-level gates and hands over a WhatsAppCallEvent plus a
 * closure that creates a Lead (the controller's own createLead(), which owns
 * the tenant lock, initial status and the once-only LeadReceived event).
 *
 * No agent claim/answer logic exists yet and no Meta API call is made: an
 * inbound call is recorded as Ringing, and only a `terminate` webhook (or a
 * later step) moves it on, so a call never stays active just because the
 * answering flow is not built.
 *
 * Identity: (lead_source_id, external_call_id) is authoritative. A duplicate
 * delivery never overwrites an existing row, whatever its payload says.
 *
 * Seen-call-id marker: once a call row has COMMITTED, its id is remembered in
 * the cache for `calling.seen_ttl_seconds` (7 days — Meta's documented webhook
 * retry window). It exists only for one case the database key cannot cover:
 * a redelivery that arrives after the call's row was deleted together with its
 * lead. With no row left, such a redelivery would look like a brand-new call
 * and recreate an erased lead; the marker turns it into an ignored delivery.
 * Rules that keep it from ever doing harm:
 *  - written only AFTER the row's transaction has committed, and only for a row
 *    this code actually created from a valid, fully-gated event — so a rejected,
 *    unsigned, malformed or ignored payload can never mark an id as seen, and a
 *    rolled-back insert can never leave a marker behind;
 *  - consulted only when no row exists for the id (a row always wins);
 *  - fail-open: an unreadable or unwritable cache means "not seen", never a
 *    blocked call;
 *  - best effort by nature (a cache write cannot be atomic with a DB commit): a
 *    failed write is logged (ids only), and the next duplicate delivery or
 *    terminate for the still-existing row heals it. The stale rule remains the
 *    second line of defence for any redelivery older than the ring window.
 *
 * Calling switched off: only NEW calls are refused. A terminate for a call that
 * was already recorded is an update of an existing call's lifecycle, not the
 * acceptance of a new call, so it is still applied; with no recorded call a
 * terminate is ignored and creates nothing (no row, no lead, no tombstone).
 *
 * Locking: the SAME cache lock the WhatsApp message path takes for a phone
 * number (`lead-webhook-dedupe:{source}:{phone}`), so a message and a call from
 * one new number serialize instead of racing to create two leads. Inside it,
 * one short DB transaction locks the resolved lead row FOR UPDATE — the same
 * row lock lead deletion and merge take — follows a merge, and inserts. No
 * lock is held across anything external; the encrypted offer is cached only
 * after that transaction commits.
 *
 * @phpstan-type TerminalState array{status: CallStatus, end_reason: CallEndReason, meta_status: ?string, meta_terminated_at: ?Carbon, ended_at: ?Carbon, duration_seconds: ?int, connected_at: ?Carbon}
 * @phpstan-type Tombstone array{meta_status: ?string, has_errors: bool, ended_at: ?int}
 */
class RecordInboundCall
{
    public const string OUTCOME_RECORDED = 'recorded';

    public const string OUTCOME_RECORDED_NO_RECIPIENT = 'recorded_no_recipient';

    public const string OUTCOME_RECORDED_STALE = 'recorded_stale';

    public const string OUTCOME_RECORDED_AFTER_TERMINATE = 'recorded_after_terminate';

    public const string OUTCOME_OFFER_CACHE_FAILED = 'offer_cache_failed';

    public const string OUTCOME_DUPLICATE = 'duplicate';

    public const string OUTCOME_CONFLICT = 'conflict';

    public const string OUTCOME_IGNORED_STALE = 'ignored_stale_unknown_caller';

    public const string OUTCOME_IGNORED_TERMINATED = 'ignored_terminated_unknown_caller';

    public const string OUTCOME_IGNORED_LEAD_MISSING = 'ignored_lead_missing';

    public const string OUTCOME_IGNORED_SEEN = 'ignored_seen_call_id';

    public const string OUTCOME_IGNORED_CALLING_DISABLED = 'ignored_calling_disabled';

    public const string OUTCOME_TERMINATE_APPLIED = 'terminate_applied';

    public const string OUTCOME_TERMINATE_FILLED = 'terminate_filled';

    public const string OUTCOME_TERMINATE_NOOP = 'terminate_noop';

    public const string OUTCOME_TERMINATE_RECORDED = 'terminate_recorded';

    public const string OUTCOME_TERMINATE_TOMBSTONED = 'terminate_tombstoned';

    public function __construct(private readonly CallRouter $router) {}

    /**
     * @param  Closure(): Lead  $createLead  creates the Lead for an unknown caller (only ever invoked for a fresh, non-stale, non-terminated, never-seen connect)
     * @param  bool  $acceptNewCalls  false while calling is switched off locally: a connect is then refused and a terminate is applied only to an already-recorded call
     * @return string one of the OUTCOME_* constants
     */
    public function handle(WhatsAppCallEvent $event, LeadSource $source, Closure $createLead, bool $acceptNewCalls = true): string
    {
        if ($event->isConnect()) {
            return $acceptNewCalls
                ? $this->connect($event, $source, $createLead)
                : self::OUTCOME_IGNORED_CALLING_DISABLED;
        }

        return $this->terminate($event, $source, $acceptNewCalls);
    }

    public static function offerCacheKey(LeadSource $source, string $callId): string
    {
        return "wa-call-offer:{$source->tenant_id}:{$source->id}:{$callId}";
    }

    public static function tombstoneCacheKey(LeadSource $source, string $callId): string
    {
        return "wa-call-tombstone:{$source->tenant_id}:{$source->id}:{$callId}";
    }

    public static function seenCacheKey(LeadSource $source, string $callId): string
    {
        return "wa-call-seen:{$source->tenant_id}:{$source->id}:{$callId}";
    }

    // ── CONNECT ─────────────────────────────────────────────────────────────

    /**
     * @param  Closure(): Lead  $createLead
     */
    private function connect(WhatsAppCallEvent $event, LeadSource $source, Closure $createLead): string
    {
        $existing = $this->findCall($source, $event->callId);

        if ($existing !== null) {
            return $this->duplicateConnect($existing, $event, $source);
        }

        $phone = (string) $event->callerPhone;

        return $this->withPhoneLock($source, $phone, function () use ($event, $source, $createLead, $phone): string {
            $existing = $this->findCall($source, $event->callId);

            if ($existing !== null) {
                return $this->duplicateConnect($existing, $event, $source);
            }

            // No row exists. If this id WAS recorded before, its row is gone —
            // deleted with its lead — so this is a redelivery, not a new call.
            if ($this->callWasSeen($source, $event->callId)) {
                return self::OUTCOME_IGNORED_SEEN;
            }

            $tombstone = $this->readTombstone($source, $event->callId);
            $eventAt = $this->eventTime($event);
            $ringEndsAt = $eventAt->copy()->addSeconds($this->ringTtl());
            $stale = $ringEndsAt->lte(Carbon::now());

            $lead = $this->existingLead($source, $phone);
            $created = false;

            if ($lead === null) {
                // A stale or already-terminated call can no longer ring, so it
                // must never be the reason a Lead comes into existence — that
                // would let a delayed delivery resurrect an erased lead.
                if ($tombstone !== null) {
                    return self::OUTCOME_IGNORED_TERMINATED;
                }

                if ($stale) {
                    return self::OUTCOME_IGNORED_STALE;
                }

                $lead = $createLead();
                $created = true;
            }

            $terminal = match (true) {
                $tombstone !== null => $this->terminalFromTombstone($tombstone, $eventAt),
                $stale => $this->terminalState(CallStatus::Missed, CallEndReason::TerminateBeforeConnect, endedAt: $ringEndsAt),
                default => null,
            };

            $call = $this->create($lead, $created, $source, $event, $eventAt, $terminal);

            if (is_string($call)) {
                return $call;
            }

            if ($call->status !== CallStatus::Ringing) {
                return match (true) {
                    $tombstone !== null => self::OUTCOME_RECORDED_AFTER_TERMINATE,
                    $stale => self::OUTCOME_RECORDED_STALE,
                    default => self::OUTCOME_RECORDED_NO_RECIPIENT,
                };
            }

            if (! $this->cacheOfferOrFail($call, $source, $event)) {
                return self::OUTCOME_OFFER_CACHE_FAILED;
            }

            // Announce the ring only now: the offer is safely cached, so an
            // agent who answers straight away can always claim it.
            LeadCallBroadcaster::updated($call, CallStatus::Ringing);

            return self::OUTCOME_RECORDED;
        });
    }

    /**
     * A repeat delivery of a connect we already hold. Never modifies the row:
     * a payload naming a different caller than the recorded call's own
     * conversation is reported as a conflict and ignored, and the only repair
     * is re-caching a missing offer for a call that is still ringing.
     */
    private function duplicateConnect(LeadCall $existing, WhatsAppCallEvent $event, LeadSource $source): string
    {
        if ($existing->conversation_id !== null) {
            $thread = Conversation::query()
                ->where('tenant_id', $source->tenant_id)
                ->whereKey($existing->conversation_id)
                ->value('external_thread_id');

            if (is_string($thread) && $thread !== $event->callerPhone) {
                return self::OUTCOME_CONFLICT;
            }
        }

        $this->healSeenMarker($source, $event->callId);

        if ($existing->status === CallStatus::Ringing
            && $existing->ringing_expires_at !== null
            && $existing->ringing_expires_at->isFuture()
            && ! $this->offerIsCached($source, $event->callId)) {
            $this->cacheOfferOrFail($existing, $source, $event);
        }

        return self::OUTCOME_DUPLICATE;
    }

    // ── TERMINATE ───────────────────────────────────────────────────────────

    private function terminate(WhatsAppCallEvent $event, LeadSource $source, bool $acceptNewCalls): string
    {
        $existing = $this->findCall($source, $event->callId);

        if ($existing !== null) {
            $this->healSeenMarker($source, $event->callId);

            return $this->applyTerminate($existing->id, $source, $event);
        }

        // Past this point the call is unknown to us, so acting on it would mean
        // accepting a new call — which a switched-off source must not do.
        if (! $acceptNewCalls) {
            return self::OUTCOME_IGNORED_CALLING_DISABLED;
        }

        // A terminate for an id we already recorded whose row has since been
        // deleted (with its lead) is a redelivery: nothing to update, and it
        // must not recreate a call record or leave a tombstone.
        if ($this->callWasSeen($source, $event->callId)) {
            return self::OUTCOME_IGNORED_SEEN;
        }

        $phone = $event->callerPhone;

        if ($phone === null) {
            $this->storeTombstone($source, $event);

            return self::OUTCOME_TERMINATE_TOMBSTONED;
        }

        return $this->withPhoneLock($source, $phone, function () use ($event, $source, $phone): string {
            $existing = $this->findCall($source, $event->callId);

            if ($existing !== null) {
                return $this->applyTerminate($existing->id, $source, $event);
            }

            $lead = $this->existingLead($source, $phone);

            // Never create a Lead from a terminate: remember it briefly so a
            // delayed connect for the same call can't ring or create one.
            if ($lead === null) {
                $this->storeTombstone($source, $event);

                return self::OUTCOME_TERMINATE_TOMBSTONED;
            }

            $eventAt = $this->eventTime($event);
            $status = $event->hasErrors ? CallStatus::Failed : CallStatus::Missed;
            $reason = $event->hasErrors ? CallEndReason::MetaError : CallEndReason::TerminateBeforeConnect;

            $call = $this->create($lead, false, $source, $event, $eventAt, $this->terminalState(
                $status,
                $reason,
                metaStatus: $event->rawMetaStatus(),
                metaTerminatedAt: Carbon::now(),
                endedAt: $this->endedAt($event, $eventAt),
                duration: $event->duration,
            ));

            return is_string($call) ? $call : self::OUTCOME_TERMINATE_RECORDED;
        });
    }

    /**
     * The approved terminate matrix, applied to the row under its own lock in
     * one short transaction — nothing external happens inside it. A terminal
     * row never changes status; the only writes allowed on it are fill-only
     * (meta_status, meta_terminated_at, ended_at, duration_seconds). A row whose
     * terminate was already applied (meta_terminated_at set) is a no-op, which
     * makes a redelivered terminate harmless.
     */
    private function applyTerminate(string $callId, LeadSource $source, WhatsAppCallEvent $event): string
    {
        $transition = null;

        $outcome = DB::transaction(function () use ($callId, $source, $event, &$transition): string {
            $call = LeadCall::query()
                ->where('tenant_id', $source->tenant_id)
                ->whereKey($callId)
                ->lockForUpdate()
                ->first();

            if ($call === null || $call->meta_terminated_at !== null) {
                return self::OUTCOME_TERMINATE_NOOP;
            }

            $endedAt = $this->endedAt($event, $this->eventTime($event));
            $updates = ['meta_terminated_at' => Carbon::now()];

            if ($call->meta_status === null && $event->rawMetaStatus() !== null) {
                $updates['meta_status'] = $event->rawMetaStatus();
            }

            if ($call->status->isTerminal()) {
                if ($call->ended_at === null) {
                    $updates['ended_at'] = $endedAt;
                }

                if ($call->duration_seconds === null && $event->duration !== null) {
                    $updates['duration_seconds'] = $event->duration;
                }

                $call->update($updates);

                return self::OUTCOME_TERMINATE_FILLED;
            }

            [$next, $reason] = $this->terminalFor($call->status, $event);

            if (! $call->status->canTransitionTo($next)) {
                return self::OUTCOME_TERMINATE_NOOP;
            }

            $updates += [
                'status' => $next,
                'end_reason' => $reason,
                'ended_at' => $endedAt,
                'duration_seconds' => $event->duration,
            ];

            if ($call->status === CallStatus::Answering && $next === CallStatus::Completed && $event->startTime !== null) {
                $updates['connected_at'] = Carbon::createFromTimestamp($event->startTime);
            }

            $call->update($updates);

            $transition = [$call, $next];

            return self::OUTCOME_TERMINATE_APPLIED;
        });

        // Announced after the transaction has committed, and only when the
        // call's status actually changed (a fill-only terminate changes none).
        if ($transition !== null) {
            LeadCallBroadcaster::updated($transition[0], $transition[1]);
        }

        return $outcome;
    }

    /**
     * Local state × terminate payload → the next status. Decided only by the
     * current state, whether `errors` was present, whether the call was picked
     * up (start_time present) and — for the two states where it matters — the
     * normalized status token. Meta's status enum is not documented, so a
     * status is never invented from it: an unknown or absent token falls to the
     * safe branch for that state.
     *
     * @return array{0: CallStatus, 1: CallEndReason}
     */
    private function terminalFor(CallStatus $current, WhatsAppCallEvent $event): array
    {
        return match ($current) {
            CallStatus::Ringing => $event->hasErrors
                ? [CallStatus::Failed, CallEndReason::MetaError]
                : [CallStatus::Missed, CallEndReason::CallerEndedOrNotAnswered],
            CallStatus::Answering => match (true) {
                $event->hasErrors => [CallStatus::Failed, CallEndReason::MetaError],
                $event->pickedUp() && $event->statusMentions('COMPLETED') => [CallStatus::Completed, CallEndReason::CompletedUnconfirmedLocally],
                default => [CallStatus::Missed, CallEndReason::EndedBeforeConnect],
            },
            CallStatus::Connected => $event->hasErrors || $event->statusMentions('FAILED')
                ? [CallStatus::Failed, CallEndReason::MetaFailedAfterConnect]
                : [CallStatus::Completed, CallEndReason::RemoteTerminated],
            default => [$current, CallEndReason::RemoteTerminated],
        };
    }

    // ── Shared: creating the row ────────────────────────────────────────────

    /**
     * @param  TerminalState|null  $terminal  null = a normal ringing call
     * @return LeadCall|string the row, or an OUTCOME_* constant when no row was created
     */
    private function create(Lead $lead, bool $created, LeadSource $source, WhatsAppCallEvent $event, Carbon $eventAt, ?array $terminal): LeadCall|string
    {
        try {
            $call = DB::transaction(fn (): ?LeadCall => $this->insertRow($lead, $created, $source, $event, $eventAt, $terminal));
        } catch (UniqueConstraintViolationException) {
            // Lost a race on (lead_source_id, external_call_id): the winner's
            // row is authoritative; treat this delivery as its duplicate.
            $existing = $this->findCall($source, $event->callId);

            if ($existing === null) {
                return self::OUTCOME_DUPLICATE;
            }

            return $event->isConnect()
                ? $this->duplicateConnect($existing, $event, $source)
                : $this->applyTerminate($existing->id, $source, $event);
        }

        if ($call === null) {
            return self::OUTCOME_IGNORED_LEAD_MISSING;
        }

        // The row has committed (this runs after DB::transaction() returned),
        // so remembering the id can never outlive a rolled-back insert.
        $this->rememberCall($source, $event->callId);

        return $call;
    }

    /**
     * The one short transaction: lock the lead FOR UPDATE (the same row lock
     * lead deletion and merge use), follow a merge to the surviving lead,
     * upsert the conversation, compute the frozen routing snapshot, insert.
     * Returns null when the lead no longer exists (deleted while the webhook
     * was being processed) — the call is then dropped, never re-created.
     *
     * @param  TerminalState|null  $terminal
     */
    private function insertRow(Lead $lead, bool $created, LeadSource $source, WhatsAppCallEvent $event, Carbon $eventAt, ?array $terminal): ?LeadCall
    {
        $target = null;
        $leadId = $lead->id;

        for ($hop = 0; $hop < 5; $hop++) {
            $target = Lead::query()->where('tenant_id', $source->tenant_id)->whereKey($leadId)->lockForUpdate()->first();

            if ($target === null || $target->merged_into_lead_id === null) {
                break;
            }

            $leadId = $target->merged_into_lead_id;
        }

        if ($target === null || $target->merged_into_lead_id !== null) {
            return null;
        }

        $conversation = Conversation::query()->firstOrCreate(
            [
                'tenant_id' => $source->tenant_id,
                'lead_source_id' => $source->id,
                'channel' => MessageChannel::Whatsapp,
                'external_thread_id' => (string) $event->callerPhone,
            ],
            ['lead_id' => $target->id],
        );

        // A call opens/refreshes the messaging window whether or not it is
        // answered (Meta's pricing documentation) — forward-only, so a delayed
        // event never rewinds a newer inbound message's timestamp.
        if ($conversation->last_inbound_at === null || $conversation->last_inbound_at->lt($eventAt)) {
            $conversation->update(['last_inbound_at' => $eventAt]);
        }

        $routing = $this->router->route($target, $created);

        $attributes = [
            'tenant_id' => $source->tenant_id,
            'lead_id' => $target->id,
            'lead_source_id' => $source->id,
            'conversation_id' => $conversation->id,
            'external_call_id' => $event->callId,
            'routing_mode' => $routing['routing_mode'],
            'routed_user_ids' => $routing['routed_user_ids'],
            'assignee_id_at_arrival' => $routing['assignee_id_at_arrival'],
        ];

        if ($terminal !== null) {
            $attributes += $terminal + ['ringing_expires_at' => null];
        } elseif ($routing['routed_user_ids'] === []) {
            $attributes += [
                'status' => CallStatus::Missed,
                'end_reason' => CallEndReason::NoRecipient,
                'ended_at' => Carbon::now(),
                'ringing_expires_at' => null,
            ];
        } else {
            $attributes += [
                'status' => CallStatus::Ringing,
                'ringing_expires_at' => $eventAt->copy()->addSeconds($this->ringTtl()),
            ];
        }

        return LeadCall::query()->create($attributes);
    }

    // ── Shared: lookups ─────────────────────────────────────────────────────

    private function findCall(LeadSource $source, string $callId): ?LeadCall
    {
        return LeadCall::query()
            ->where('tenant_id', $source->tenant_id)
            ->where('lead_source_id', $source->id)
            ->where('external_call_id', $callId)
            ->first();
    }

    /**
     * Same two lookups, in the same order, as the WhatsApp message path: the
     * merge-aware conversation first (a merged lead's conversation was
     * reparented onto the survivor), then the exact phone on this source
     * excluding merged-away leads.
     */
    private function existingLead(LeadSource $source, string $phone): ?Lead
    {
        $lead = Conversation::query()
            ->where('tenant_id', $source->tenant_id)
            ->where('lead_source_id', $source->id)
            ->where('channel', MessageChannel::Whatsapp)
            ->where('external_thread_id', $phone)
            ->first()
            ?->lead;

        if ($lead instanceof Lead) {
            return $lead;
        }

        return Lead::query()
            ->where('tenant_id', $source->tenant_id)
            ->where('source_id', $source->id)
            ->where('phone', $phone)
            ->whereNull('merged_into_lead_id')
            ->first();
    }

    private function withPhoneLock(LeadSource $source, string $phone, Closure $callback): string
    {
        // Deliberately the exact key LeadWebhookController::withDedupeLock()
        // builds for a WhatsApp message, so both paths serialize on one lock.
        return (string) Cache::lock("lead-webhook-dedupe:{$source->id}:{$phone}", 10)->block(5, $callback);
    }

    // ── Shared: the encrypted offer and the tombstone ───────────────────────

    /**
     * Encrypts the SDP offer and caches it, retrying once. If it can't be
     * cached the call can never be answered, so it is conditionally moved from
     * Ringing to Failed (sdp_cache_failure) — never creating anything extra.
     */
    private function cacheOfferOrFail(LeadCall $call, LeadSource $source, WhatsAppCallEvent $event): bool
    {
        if ($this->storeOffer($source, $event)) {
            return true;
        }

        LeadCall::query()
            ->whereKey($call->id)
            ->where('status', CallStatus::Ringing->value)
            ->update([
                'status' => CallStatus::Failed->value,
                'end_reason' => CallEndReason::SdpCacheFailure->value,
                'ended_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

        return false;
    }

    private function storeOffer(LeadSource $source, WhatsAppCallEvent $event): bool
    {
        if ($event->sdp === null) {
            return false;
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $stored = Cache::put(
                    self::offerCacheKey($source, $event->callId),
                    Crypt::encryptString($event->sdp),
                    Carbon::now()->addSeconds((int) config('calling.offer_ttl_seconds')),
                );

                if ($stored) {
                    return true;
                }
            } catch (Throwable) {
                // Retried once, then reported as a cache failure by the caller.
            }
        }

        return false;
    }

    private function offerIsCached(LeadSource $source, string $callId): bool
    {
        try {
            return Cache::has(self::offerCacheKey($source, $callId));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Remembers that this call id was durably recorded (see the class docblock).
     * Two attempts; a failure never fails the call — it is logged by id only and
     * healed by the next duplicate delivery or terminate for the same row.
     */
    private function rememberCall(LeadSource $source, string $callId): bool
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                if (Cache::put(self::seenCacheKey($source, $callId), Carbon::now()->getTimestamp(), Carbon::now()->addSeconds((int) config('calling.seen_ttl_seconds')))) {
                    return true;
                }
            } catch (Throwable) {
                // Retried once, then reported below.
            }
        }

        Log::channel('whatsapp')->warning('WA CALL SEEN MARKER FAILED', [
            'tenant_id' => $source->tenant_id,
            'lead_source_id' => $source->id,
            'call_id' => $callId,
        ]);

        return false;
    }

    private function callWasSeen(LeadSource $source, string $callId): bool
    {
        try {
            return Cache::has(self::seenCacheKey($source, $callId));
        } catch (Throwable) {
            // Fail-open: an unreadable cache must never block a call.
            return false;
        }
    }

    /**
     * For a call whose row exists: re-write a missing marker (an earlier write
     * may have failed). Only ever called for an id with a real row, so it can
     * never mark an id that has no recorded call.
     */
    private function healSeenMarker(LeadSource $source, string $callId): void
    {
        if (! $this->callWasSeen($source, $callId)) {
            $this->rememberCall($source, $callId);
        }
    }

    private function storeTombstone(LeadSource $source, WhatsAppCallEvent $event): void
    {
        try {
            Cache::put(
                self::tombstoneCacheKey($source, $event->callId),
                [
                    'meta_status' => $event->rawMetaStatus(),
                    'has_errors' => $event->hasErrors,
                    'ended_at' => $event->endTime,
                ],
                Carbon::now()->addSeconds((int) config('calling.tombstone_ttl_seconds')),
            );
        } catch (Throwable) {
            // Best effort: without it a delayed connect is handled as any other.
        }
    }

    /**
     * @return Tombstone|null
     */
    private function readTombstone(LeadSource $source, string $callId): ?array
    {
        try {
            $value = Cache::get(self::tombstoneCacheKey($source, $callId));
        } catch (Throwable) {
            return null;
        }

        if (! is_array($value)) {
            return null;
        }

        return [
            'meta_status' => is_string($value['meta_status'] ?? null) ? $value['meta_status'] : null,
            'has_errors' => ($value['has_errors'] ?? false) === true,
            'ended_at' => is_int($value['ended_at'] ?? null) ? $value['ended_at'] : null,
        ];
    }

    // ── Shared: small pure helpers ──────────────────────────────────────────

    /**
     * @param  Tombstone  $tombstone
     * @return TerminalState
     */
    private function terminalFromTombstone(array $tombstone, Carbon $eventAt): array
    {
        return $this->terminalState(
            $tombstone['has_errors'] ? CallStatus::Failed : CallStatus::Missed,
            $tombstone['has_errors'] ? CallEndReason::MetaError : CallEndReason::TerminateBeforeConnect,
            metaStatus: $tombstone['meta_status'],
            metaTerminatedAt: Carbon::now(),
            endedAt: $tombstone['ended_at'] !== null ? Carbon::createFromTimestamp($tombstone['ended_at']) : $eventAt,
        );
    }

    /**
     * @return TerminalState
     */
    private function terminalState(
        CallStatus $status,
        CallEndReason $reason,
        ?string $metaStatus = null,
        ?Carbon $metaTerminatedAt = null,
        ?Carbon $endedAt = null,
        ?int $duration = null,
    ): array {
        return [
            'status' => $status,
            'end_reason' => $reason,
            'meta_status' => $metaStatus,
            'meta_terminated_at' => $metaTerminatedAt,
            'ended_at' => $endedAt,
            'duration_seconds' => $duration,
            'connected_at' => null,
        ];
    }

    /**
     * Meta's own event time, but never later than now — a future timestamp
     * (a forged or skewed one) can't extend a ring window or push a
     * conversation's last_inbound_at into the future.
     */
    private function eventTime(WhatsAppCallEvent $event): Carbon
    {
        return Carbon::createFromTimestamp(min($event->timestamp, Carbon::now()->getTimestamp()));
    }

    private function endedAt(WhatsAppCallEvent $event, Carbon $eventAt): Carbon
    {
        return $event->endTime !== null ? Carbon::createFromTimestamp($event->endTime) : $eventAt;
    }

    private function ringTtl(): int
    {
        return (int) config('calling.ring_ttl_seconds');
    }
}
