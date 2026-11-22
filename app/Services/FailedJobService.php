<?php

namespace App\Services;

use App\Enums\FailedJobAdvice;
use App\Jobs\DownloadLeadAttachment;
use App\Jobs\ProcessEmailLeadSource;
use App\Jobs\ReconcileOutboundMessageStatus;
use App\Jobs\SendCallingAppCallPush;
use App\Jobs\SendIncomingCallPushNotification;
use App\Jobs\SendMissedCallNotification;
use App\Jobs\SendOutboundMessage;
use App\Listeners\AutoAssignLead;
use App\Listeners\RecomputeLeadScoreOnReceipt;
use App\Listeners\SendLeadAssignedNotification;
use App\Listeners\SendNewLeadNotification;
use App\Notifications\CallingAppCallPush;
use App\Notifications\IncomingWhatsAppCallNotification;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\JobRepository;
use stdClass;
use Throwable;

/**
 * Reads, explains, retries and deletes failed queued jobs for the Platform
 * "Failed jobs" page. The source is Laravel's own failed_jobs table, which
 * records every failure — from before Horizon (database queue) and since —
 * so nothing is missing from the list. Horizon keeps its own copy in Redis;
 * retrying or deleting here clears that copy too, so both views agree.
 *
 * @phpstan-type FailedJob array{id: string, class: string, name: string, queue: string, failedAt: CarbonInterface, error: string, details: string, advice: FailedJobAdvice, reason: string}
 */
class FailedJobService
{
    /**
     * Newest failures, explained. Capped so a flood of failures can't make
     * the page slow; the page says so when the cap is reached.
     */
    public const LIMIT = 500;

    /**
     * Jobs that ring a phone for a call: pointless once the call is over.
     *
     * @var list<string>
     */
    private const CALL_RINGING_JOBS = [
        SendIncomingCallPushNotification::class,
        SendCallingAppCallPush::class,
        IncomingWhatsAppCallNotification::class,
        CallingAppCallPush::class,
    ];

    /**
     * Jobs that call Meta's Graph API, whose refusals are permanent.
     *
     * @var list<string>
     */
    private const CALLS_META = [
        SendOutboundMessage::class,
        ReconcileOutboundMessageStatus::class,
        DownloadLeadAttachment::class,
    ];

    /**
     * Jobs that send something to a person, where a retry could deliver it twice.
     *
     * @var list<string>
     */
    private const SENDS_TO_PEOPLE = [
        SendOutboundMessage::class,
        SendMissedCallNotification::class,
        SendNewLeadNotification::class,
        SendLeadAssignedNotification::class,
    ];

    /**
     * @return Collection<int, FailedJob>
     */
    public function recent(int $limit = self::LIMIT): Collection
    {
        return $this->table()
            ->orderByDesc('failed_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (stdClass $row): array => $this->describe($row))
            ->values();
    }

    public function total(): int
    {
        return $this->table()->count();
    }

    /**
     * @return FailedJob|null
     */
    public function find(string $uuid): ?array
    {
        $row = $this->table()->where('uuid', $uuid)->first();

        return $row instanceof stdClass ? $this->describe($row) : null;
    }

    /**
     * Puts the job back on the queue with a fresh attempt count, then removes
     * the failure record. It always goes onto the app's current queue
     * connection: jobs that failed before the move to Redis still point at
     * the old database queue, which nothing processes any more.
     */
    public function retry(string $uuid): bool
    {
        $row = $this->table()->where('uuid', $uuid)->first();

        if (! $row instanceof stdClass) {
            return false;
        }

        $payload = json_decode((string) $row->payload, true);

        if (! is_array($payload)) {
            return false;
        }

        // The Redis queue reads `id` and `attempts` from the payload itself;
        // a database-queue payload has neither.
        $payload['id'] = $payload['id'] ?? $payload['uuid'] ?? Str::random(32);
        $payload['attempts'] = 0;

        Queue::connection(config('queue.default'))->pushRaw((string) json_encode($payload), (string) $row->queue);

        $this->forget($uuid);

        return true;
    }

    public function delete(string $uuid): bool
    {
        if (! $this->table()->where('uuid', $uuid)->exists()) {
            return false;
        }

        $this->forget($uuid);

        return true;
    }

    /**
     * @param  array<int, string>  $uuids
     */
    public function deleteMany(array $uuids): int
    {
        $deleted = 0;

        foreach ($uuids as $uuid) {
            if ($this->delete($uuid)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * @return FailedJob
     */
    public function describe(stdClass $row): array
    {
        $payload = json_decode((string) $row->payload, true);
        $class = is_array($payload) ? (string) ($payload['displayName'] ?? '') : '';
        $exception = (string) $row->exception;
        $failedAt = Carbon::parse((string) $row->failed_at);

        [$advice, $reason] = $this->advise($class, $exception, $failedAt);

        return [
            'id' => (string) $row->uuid,
            'class' => $class,
            'name' => $this->friendlyName($class),
            'queue' => (string) $row->queue,
            'failedAt' => $failedAt,
            'error' => $this->errorSummary($exception),
            'details' => implode("\n", array_slice(explode("\n", $exception), 0, 40)),
            'advice' => $advice,
            'reason' => $reason,
        ];
    }

    /**
     * A plain-language suggestion for one failure. Deliberately cautious: when
     * the cause isn't recognised it says "Not sure" rather than guessing.
     *
     * @return array{0: FailedJobAdvice, 1: string}
     */
    public function advise(string $class, string $exception, CarbonInterface $failedAt): array
    {
        // Only the first line (exception class + message): the stack trace
        // below it is full of file paths and line numbers that would
        // otherwise match patterns like "429" by accident.
        $error = Str::lower(Str::before($exception, "\n"));
        $callsMeta = in_array($class, self::CALLS_META, true);
        $sendsToPeople = in_array($class, self::SENDS_TO_PEOPLE, true) || str_starts_with($class, 'App\\Notifications\\');

        if ($class === ReconcileOutboundMessageStatus::class && str_contains($error, 'no message found')) {
            return [FailedJobAdvice::Delete, __('WhatsApp sent a "sent / delivered / read" update for a message OptiFin has no record of — usually one sent from the WhatsApp phone app or Meta\'s own tools. There is nothing to update, so retrying will never work. Safe to delete.')];
        }

        if (in_array($class, self::CALL_RINGING_JOBS, true) && $failedAt->lt(now()->subMinutes(10))) {
            return [FailedJobAdvice::Delete, __('This rang a phone for an incoming WhatsApp call. The call ended long ago, so ringing now would only confuse the user. Safe to delete.')];
        }

        if (str_contains($error, 'modelnotfoundexception') || str_contains($error, 'no query results for model')) {
            return [FailedJobAdvice::Delete, __('The record this job was working on (a lead, message or user) has since been deleted, so there is nothing left to do. Safe to delete.')];
        }

        if ($this->containsAny($error, ['typeerror', 'argumentcounterror', 'badmethodcallexception', 'call to undefined', 'undefined method', 'undefined array key', 'undefined property', 'undefined variable', 'parseerror', 'valueerror', 'division by zero'])) {
            return [FailedJobAdvice::Developer, __('This looks like a bug in the code. Retrying will fail the same way until it is fixed. Send the error below to your developer; once they have fixed it, retry it (if it still matters) or delete it.')];
        }

        if (str_contains($error, 'oauthexception') || ($callsMeta && $this->containsAny($error, ['invalid oauth', 'access token', 'session has expired', '(#100)', '(#190)', '(#200)', '(#10)', 'not a valid whatsapp', 'recipient', 'not on whatsapp', 'outside the allowed window', 're-engagement', '131047', '131026', '132000', '132001']))) {
            return [FailedJobAdvice::Delete, __('Meta (WhatsApp / Facebook) refused this request — for example the number is not on WhatsApp, the 24-hour reply window has closed, or the connection token expired. Retrying gets the same answer. If it mentions a token or permission, fix that client\'s lead source settings first. Then delete it.')];
        }

        if ($this->containsAny($error, ['connectionexception', 'curl error', 'could not resolve host', 'connection refused', 'connection reset', 'timed out', 'timeout', 'too many requests', 'status code 429', 'status code 500', 'status code 502', 'status code 503', 'status code 504', 'deadlock', '40p01', '40001', 'server has gone away', 'lost connection', 'temporarily', 'try again', 'maxattemptsexceeded', 'has been attempted too many times', 'redisexception'])) {
            if ($failedAt->lt(now()->subDay()) && $sendsToPeople) {
                return [FailedJobAdvice::Delete, __('A temporary problem (network or a busy server) stopped this, but it is more than a day old. Sending it now would arrive very late and may confuse the person. Usually best to delete.')];
            }

            return [FailedJobAdvice::Retry, $sendsToPeople
                ? __('A temporary problem (network or a busy server) stopped this. Retrying usually works. It sends something to a person, so if you are unsure whether it partly went through, check first to avoid sending it twice.')
                : __('A temporary problem (network or a busy server) stopped this. Retrying usually works.')];
        }

        return [FailedJobAdvice::Review, __('The cause isn\'t a known pattern. If it is recent and important, try Retry once. If it fails again, delete it and send the error below to your developer.')];
    }

    /**
     * A name an admin can understand, from the job's class.
     */
    public function friendlyName(string $class): string
    {
        $known = [
            SendOutboundMessage::class => __('Send a WhatsApp / Messenger / Instagram message'),
            ReconcileOutboundMessageStatus::class => __('Update a message\'s delivered / read status'),
            ProcessEmailLeadSource::class => __('Read new leads from an email inbox'),
            DownloadLeadAttachment::class => __('Download a file a lead sent'),
            SendIncomingCallPushNotification::class => __('Ring a browser for an incoming WhatsApp call'),
            SendCallingAppCallPush::class => __('Ring the calling app for an incoming WhatsApp call'),
            SendMissedCallNotification::class => __('Tell admins about a missed call'),
            AutoAssignLead::class => __('Auto-assign a new lead'),
            RecomputeLeadScoreOnReceipt::class => __('Score a new lead'),
            SendNewLeadNotification::class => __('Notify about a new lead'),
            SendLeadAssignedNotification::class => __('Notify a user about an assigned lead'),
        ];

        if (isset($known[$class])) {
            return $known[$class];
        }

        if (str_starts_with($class, 'App\\Notifications\\')) {
            return __('Send notification: :name', ['name' => Str::headline(Str::beforeLast(class_basename($class), 'Notification'))]);
        }

        if (str_starts_with($class, 'Maatwebsite\\Excel\\')) {
            return __('Import leads from a CSV file');
        }

        return $class === '' ? __('Unknown job') : Str::headline(class_basename($class));
    }

    /**
     * The exception's first line, without the "in /path/file.php:123" tail.
     */
    private function errorSummary(string $exception): string
    {
        $firstLine = Str::before($exception, "\n");
        $withoutLocation = (string) preg_replace('/ in \/\S+:\d+$/', '', $firstLine);

        return Str::limit($withoutLocation, 300);
    }

    /**
     * Removes the failure from Laravel's table and from Horizon's copy.
     */
    private function forget(string $uuid): void
    {
        app(FailedJobProviderInterface::class)->forget($uuid);

        try {
            app(JobRepository::class)->deleteFailed($uuid);
        } catch (Throwable $exception) {
            // Redis unavailable: the authoritative record is already gone;
            // Horizon's copy expires on its own trim schedule.
            report($exception);
        }
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function table(): Builder
    {
        return DB::connection(config('queue.failed.database'))->table(config('queue.failed.table'));
    }
}
