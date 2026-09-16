<?php

namespace App\Jobs;

use App\Enums\LeadAttachmentStatus;
use App\Events\LeadReceived;
use App\Events\LeadUpdated;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use IMAP\Connection;
use Symfony\Component\Mime\MimeTypes;
use Throwable;

class ProcessEmailLeadSource implements ShouldQueue
{
    use Queueable;

    /**
     * Bumped from the framework default (1) to tolerate WithoutOverlapping
     * below releasing this job back onto the queue a time or two while a
     * previous run for the same source is still in flight — that's normal
     * contention (see middleware()), not a real failure, and shouldn't
     * exhaust retries and get this job marked permanently failed.
     */
    public int $tries = 3;

    /**
     * Cap on unseen messages processed per run. Unbounded processing on a
     * large inbox can outrun the queue worker's timeout, which kills the
     * process before it reaches the last_synced_at update below — leaving
     * the source permanently "due" and causing duplicate re-dispatch every
     * minute. Remaining unseen messages are picked up on the next run.
     */
    private const MAX_MESSAGES_PER_RUN = 25;

    /**
     * Matches the Messenger/Instagram attachment cap — a reasonable default
     * for inbound mail, and comfortably above what most mail providers
     * allow per attachment in the first place (Gmail caps at 25MB).
     */
    private const MAX_ATTACHMENT_BYTES = 25 * 1024 * 1024;

    /**
     * @var array<int, string>
     */
    private const IMAP_PRIMARY_TYPES = [
        0 => 'text', 1 => 'multipart', 2 => 'message', 3 => 'application',
        4 => 'audio', 5 => 'image', 6 => 'video', 7 => 'other',
    ];

    /**
     * IMAP fetches over a slow or throttled connection can occasionally push
     * a full batch past the queue worker's default 60s timeout even at the
     * message cap above; this gives it headroom instead of failing outright.
     */
    public int $timeout = 180;

    public function __construct(public LeadSource $source) {}

    /**
     * Without this, a job that overruns its source's fetch_interval_minutes
     * (plausible on a slow IMAP connection near the 180s timeout above)
     * would let leads:fetch-emails' next tick dispatch a second run for the
     * same source before the first finishes — since last_synced_at only
     * updates at the very end of handle() — and two concurrent IMAP
     * sessions against the same mailbox could both see the same UNSEEN
     * message before either flags it \Seen, creating duplicate leads.
     * expireAfter matches $timeout: if a run dies without releasing the
     * lock, it can't hold it any longer than it would have been allowed to
     * run anyway.
     *
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->source->id))->releaseAfter(30)->expireAfter($this->timeout)];
    }

    public function handle(): void
    {
        $config = $this->source->config ?? [];

        $mailbox = sprintf('{%s:%d/imap/ssl}INBOX', $config['imap_host'] ?? '', $config['imap_port'] ?? 993);

        $inbox = @imap_open($mailbox, $config['imap_username'] ?? '', $config['imap_password'] ?? '');

        if (! $inbox instanceof Connection) {
            $error = imap_last_error();

            // Drain the extension's internal alert/error stacks. Anything left
            // unread here surfaces as a PHP warning at request shutdown (outside
            // this suppressed scope), which Laravel turns into an ErrorException.
            imap_errors();
            imap_alerts();

            Log::error("Unable to connect to lead source [{$this->source->id}]: {$error}");

            return;
        }

        $messageNumbers = imap_search($inbox, 'UNSEEN') ?: [];
        sort($messageNumbers);
        $messageNumbers = array_slice($messageNumbers, 0, self::MAX_MESSAGES_PER_RUN);

        foreach ($messageNumbers as $messageNumber) {
            try {
                $this->createLeadFromEmail($inbox, (int) $messageNumber);
            } catch (Throwable $e) {
                Log::error("Failed to process email message [{$messageNumber}] on lead source [{$this->source->id}]: {$e->getMessage()}");
            }

            // Flagged \Seen even on failure above — a message that can't be
            // parsed won't suddenly parse successfully on a later run, so
            // leaving it unseen would mean re-fetching and re-failing on
            // the exact same message every run from now on, permanently
            // blocking every *other* unseen message queued behind it
            // (imap_search results are processed in ascending order) and
            // never letting last_synced_at advance. The lead is lost, but
            // logged above, and this source keeps working.
            imap_setflag_full($inbox, (string) $messageNumber, '\\Seen');
        }

        imap_errors();
        imap_alerts();
        imap_close($inbox);

        $this->source->update(['last_synced_at' => now()]);
    }

    private function createLeadFromEmail(Connection $inbox, int $messageNumber): void
    {
        $header = imap_headerinfo($inbox, $messageNumber);

        if ($header === false) {
            Log::error("Unable to read headers for message [{$messageNumber}] on lead source [{$this->source->id}].");

            return;
        }

        $structure = imap_fetchstructure($inbox, $messageNumber);
        $body = $structure !== false
            ? $this->extractBody($inbox, $messageNumber, $structure)
            : ['message' => '', 'html' => null];

        $from = $header->from[0] ?? null;
        $email = $from !== null ? "{$from->mailbox}@{$from->host}" : null;
        $name = $from->personal ?? $email ?? 'Unknown Sender';
        $sentAt = $this->emailSentAt($header);

        // A second email from the same address is a follow-up on the same
        // inquiry, not a new one — mirrors how Messenger/Instagram/WhatsApp
        // already append a follow-up message to the sender's existing lead
        // instead of creating a duplicate (see
        // LeadWebhookController::appendFacebookMessage()). Skipped entirely
        // when the address couldn't be parsed at all, since there's nothing
        // reliable to match a later email against.
        $existingLead = $email !== null
            ? Lead::query()->where('source_id', $this->source->id)->where('email', $email)->first()
            : null;

        if ($existingLead instanceof Lead) {
            $this->appendEmailMessage($existingLead, $header, $body, $email);

            if ($structure !== false) {
                $this->saveAttachments($inbox, $messageNumber, $structure, $existingLead, $sentAt);
            }

            return;
        }

        // TOCTOU fix: coordination lock — see
        // Platform\Clients\LeadSources::toggleLeadStatus()'s own docblock
        // for the full readers/writer protocol. Wrapped in its own
        // transaction (this job has none otherwise) so the lock and the
        // Lead::create() below commit atomically — a pipeline-status
        // removal racing this exact window can never strand the lead being
        // created. $tenant->initialLeadStatus() is read from this
        // freshly-locked instance, never $this->source->tenant (which may
        // carry a stale cached relation).
        //
        // DEADLOCK-1 fix: FOR UPDATE, not FOR SHARE — see
        // LeadWebhookController::createLead()'s identical comment for the
        // full reasoning. Lead::create() below still triggers
        // Lead::nextIdentifier()'s own FOR UPDATE on this same row; that is
        // now a harmless re-lock instead of a lock-upgrade deadlock hazard
        // against another concurrent creator for this tenant.
        $lead = DB::transaction(function () use ($name, $email, $body, $header): Lead {
            $tenant = Tenant::whereKey($this->source->tenant_id)->lockForUpdate()->firstOrFail();

            return Lead::create([
                'tenant_id' => $this->source->tenant_id,
                'source_id' => $this->source->id,
                'name' => $name,
                'email' => $email,
                'message' => trim($body['message']),
                'html_body' => $body['html'],
                'source_data' => ['subject' => $header->subject ?? null],
                'status_id' => $tenant->initialLeadStatus()?->id,
            ]);
        });

        event(new LeadReceived($lead));

        if ($structure !== false) {
            $this->saveAttachments($inbox, $messageNumber, $structure, $lead, $sentAt);
        }
    }

    /**
     * Appends a follow-up email from the same sender onto their existing
     * lead — mirrors LeadWebhookController::appendFacebookMessage(), with
     * one difference: there's no per-message conversation timeline for
     * email the way Lead::messageTimeline() gives Messenger/WhatsApp, so
     * html_body is simply replaced with the newest email's own content
     * (only one slot exists; showing the latest message beats a stale
     * first one), and message is newline-appended the same way those
     * channels already do.
     */
    private function appendEmailMessage(Lead $lead, object $header, array $body, string $email): void
    {
        $personalName = $header->from[0]->personal ?? null;

        if ($lead->name === $email && is_string($personalName) && $personalName !== '') {
            $lead->name = $personalName;
        }

        $messageText = trim($body['message']);

        if ($messageText !== '') {
            $lead->message = trim(($lead->message ?? '')."\n".$messageText);
        }

        if ($body['html'] !== null) {
            $lead->html_body = $body['html'];
        }

        $sourceData = $lead->source_data ?? [];
        $sourceData['subject'] = $header->subject ?? ($sourceData['subject'] ?? null);
        $lead->source_data = $sourceData;

        $lead->save();

        event(new LeadUpdated($lead));
    }

    /**
     * `imap_headerinfo()->date` is the raw Date header — malformed or
     * missing on some senders' mail, so this falls back to "now" rather
     * than letting a bad date header break attachment ordering entirely.
     */
    private function emailSentAt(object $header): CarbonInterface
    {
        if (! isset($header->date) || ! is_string($header->date) || $header->date === '') {
            return now();
        }

        try {
            return Carbon::parse($header->date);
        } catch (Throwable) {
            return now();
        }
    }

    /**
     * imap_fetchbody() returns a MIME part's raw, still-encoded bytes — it
     * never applies Content-Transfer-Encoding itself. Without decoding,
     * base64 or quoted-printable parts (common for HTML/forwarded mail) land
     * in the lead's message field as unreadable encoded text.
     *
     * Returns both the plain-text message (the lead's primary, searchable
     * field — HTML tags stripped as a last resort when no plain part exists
     * at all) and the original HTML part verbatim, if the message has one,
     * so the UI can offer "view original email" without re-deriving it.
     *
     * @return array{message: string, html: ?string}
     */
    private function extractBody(Connection $inbox, int $messageNumber, object $structure): array
    {
        ['plain' => $plain, 'html' => $html] = $this->findTextParts($structure);

        $htmlBody = $html !== null
            ? $this->fetchDecodedPart($inbox, $messageNumber, $html[0], $html[1])
            : null;

        $message = match (true) {
            $plain !== null => $this->fetchDecodedPart($inbox, $messageNumber, $plain[0], $plain[1]),
            $htmlBody !== null => html_entity_decode(strip_tags($htmlBody), ENT_QUOTES | ENT_HTML5),
            default => '',
        };

        return [
            'message' => $this->stripAntiClippingPadding($message),
            'html' => $htmlBody,
        ];
    }

    private function fetchDecodedPart(Connection $inbox, int $messageNumber, ?string $partNumber, int $encoding): string
    {
        $raw = $partNumber !== null
            ? imap_fetchbody($inbox, $messageNumber, $partNumber)
            : imap_fetchbody($inbox, $messageNumber, '1');

        return match ($encoding) {
            3 => base64_decode((string) $raw) ?: (string) $raw, // ENCBASE64
            4 => quoted_printable_decode((string) $raw), // ENCQUOTEDPRINTABLE
            default => (string) $raw,
        };
    }

    /**
     * Marketing senders (Hostinger here) pad plain-text bodies with invisible
     * zero-width/combining characters between spaces to defeat Gmail's
     * "message clipped" truncation. They're inert in an inbox but read as
     * garbage once extracted as plain text, so strip them along with the
     * whitespace runs they leave behind.
     */
    private function stripAntiClippingPadding(string $text): string
    {
        // Zero-width/format characters (ZWSP, ZWNJ, ZWJ, BOM, word joiner,
        // soft hyphen, combining grapheme joiner) used purely as padding.
        $text = preg_replace('/[\p{Cf}\x{034F}]/u', '', $text) ?? $text;

        // What's left is runs of Unicode space-separator characters (plain
        // space, figure space, NBSP, ...) standing in for the removed ones.
        // Trim each line so padding-only lines collapse to truly empty ones.
        $lines = array_map(
            fn (string $line): string => trim(preg_replace('/[\p{Zs}\t]{2,}/u', ' ', $line) ?? $line),
            preg_split('/\r\n|\r|\n/', $text) ?: [$text],
        );

        $text = preg_replace('/\n{3,}/', "\n\n", implode("\n", $lines)) ?? implode("\n", $lines);

        return trim($text);
    }

    /**
     * Walk a message's MIME structure for the best plain-text part and the
     * best HTML part, independently — a forwarded message commonly wraps
     * multipart/alternative (plain + html) inside multipart/mixed alongside
     * attachments, so both can exist several levels deep in the same tree.
     * A non-multipart message with an unrecognized subtype is treated as
     * "plain" so `message` still gets whatever body the message actually has.
     *
     * @return array{plain: ?array{0: ?string, 1: int}, html: ?array{0: ?string, 1: int}}
     *                                                                                    each candidate is [part number (dot-notation, e.g. "1.2"; null
     *                                                                                    for a non-multipart message), the part's IMAP encoding constant]
     */
    private function findTextParts(object $structure, string $prefix = ''): array
    {
        if (empty($structure->parts)) {
            $partNumber = $prefix !== '' ? $prefix : null;
            $encoding = $structure->encoding ?? 0;

            return strtoupper($structure->subtype ?? '') === 'HTML'
                ? ['plain' => null, 'html' => [$partNumber, $encoding]]
                : ['plain' => [$partNumber, $encoding], 'html' => null];
        }

        $plain = null;
        $html = null;

        foreach ($structure->parts as $index => $part) {
            if ($plain !== null && $html !== null) {
                break;
            }

            $partNumber = $prefix === '' ? (string) ($index + 1) : $prefix.'.'.($index + 1);

            if ((int) $part->type === 1) {
                $found = $this->findTextParts($part, $partNumber);
                $plain ??= $found['plain'];
                $html ??= $found['html'];

                continue;
            }

            if ((int) $part->type !== 0) {
                continue;
            }

            $subtype = strtoupper($part->subtype ?? '');

            if ($subtype === 'PLAIN') {
                $plain ??= [$partNumber, $part->encoding ?? 0];
            } elseif ($subtype === 'HTML') {
                $html ??= [$partNumber, $part->encoding ?? 0];
            }
        }

        return ['plain' => $plain, 'html' => $html];
    }

    /**
     * Walks the same MIME structure as findTextParts() for every part that
     * isn't the plain/html body — a forwarded email or one with several
     * files attached can carry more than one, so all of them are saved as
     * their own LeadAttachment row, not just the first.
     */
    private function saveAttachments(Connection $inbox, int $messageNumber, object $structure, Lead $lead, CarbonInterface $sentAt): void
    {
        $parts = $this->findAttachmentParts($structure);

        if (count($parts) > 1) {
            Log::info('Multiple email attachments received', ['count' => count($parts), 'lead_id' => $lead->id]);
        }

        foreach ($parts as $part) {
            $this->saveAttachmentPart($inbox, $messageNumber, $part, $lead, $sentAt);
        }
    }

    /**
     * @return array<int, array{part_number: string, encoding: int, mime: string, filename: ?string}>
     */
    private function findAttachmentParts(object $structure, string $prefix = ''): array
    {
        if (empty($structure->parts)) {
            return [];
        }

        $attachments = [];

        foreach ($structure->parts as $index => $part) {
            $partNumber = $prefix === '' ? (string) ($index + 1) : $prefix.'.'.($index + 1);

            if ((int) $part->type === 1) {
                $attachments = [...$attachments, ...$this->findAttachmentParts($part, $partNumber)];

                continue;
            }

            $subtype = strtoupper($part->subtype ?? '');
            $filename = $this->partFilename($part);

            // A plain/html part with no filename is the message body itself
            // (already captured by findTextParts()) — but the same subtypes
            // legitimately show up as real attachments too (e.g. a .txt or
            // .csv file), so only skip the bodyless case.
            if (in_array($subtype, ['PLAIN', 'HTML'], true) && $filename === null) {
                continue;
            }

            $attachments[] = [
                'part_number' => $partNumber,
                'encoding' => (int) ($part->encoding ?? 0),
                'mime' => $this->partMimeType($part),
                'filename' => $filename,
            ];
        }

        return $attachments;
    }

    /**
     * Reads the attachment's filename from the Content-Disposition or
     * Content-Type parameters — wherever the sender's mail client put it.
     */
    private function partFilename(object $part): ?string
    {
        $params = [...($part->dparameters ?? []), ...($part->parameters ?? [])];

        foreach ($params as $param) {
            if (in_array(strtolower($param->attribute ?? ''), ['filename', 'name'], true)) {
                return $param->value;
            }
        }

        return null;
    }

    private function partMimeType(object $part): string
    {
        $primary = self::IMAP_PRIMARY_TYPES[(int) ($part->type ?? 3)] ?? 'application';
        $subtype = strtolower($part->subtype ?? 'octet-stream');

        return "{$primary}/{$subtype}";
    }

    /**
     * @param  array{part_number: string, encoding: int, mime: string, filename: ?string}  $part
     */
    private function saveAttachmentPart(Connection $inbox, int $messageNumber, array $part, Lead $lead, CarbonInterface $sentAt): void
    {
        $type = $this->attachmentTypeCategory($part['mime']);

        try {
            $bytes = $this->fetchDecodedPart($inbox, $messageNumber, $part['part_number'], $part['encoding']);
        } catch (Throwable $e) {
            $lead->attachments()->create([
                'type' => $type,
                'status' => LeadAttachmentStatus::Failed,
                'failure_reason' => "Could not read attachment from the mailbox: {$e->getMessage()}",
                'sent_at' => $sentAt,
            ]);

            return;
        }

        if ($bytes === '' || strlen($bytes) > self::MAX_ATTACHMENT_BYTES) {
            $lead->attachments()->create([
                'type' => $type,
                'status' => LeadAttachmentStatus::Failed,
                'failure_reason' => $bytes === ''
                    ? 'Attachment was empty after decoding.'
                    : 'Attachment exceeds the '.self::MAX_ATTACHMENT_BYTES.'-byte limit.',
                'sent_at' => $sentAt,
            ]);

            return;
        }

        $relativePath = "leads/{$lead->tenant->tenant_code}/email/".Str::ulid().'.'.$this->attachmentExtension($part['filename'], $part['mime']);

        try {
            Storage::disk('local')->put($relativePath, $bytes);
        } catch (Throwable $e) {
            $lead->attachments()->create([
                'type' => $type,
                'status' => LeadAttachmentStatus::Failed,
                'failure_reason' => "Could not save attachment to disk: {$e->getMessage()}",
                'sent_at' => $sentAt,
            ]);

            return;
        }

        $lead->attachments()->create([
            'type' => $type,
            'path' => $relativePath,
            'mime' => $part['mime'],
            'status' => LeadAttachmentStatus::Downloaded,
            'sent_at' => $sentAt,
        ]);
    }

    private function attachmentTypeCategory(string $mime): string
    {
        return match (true) {
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'video/') => 'video',
            str_starts_with($mime, 'audio/') => 'audio',
            default => 'file',
        };
    }

    private function attachmentExtension(?string $filename, string $mime): string
    {
        if ($filename !== null) {
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            if ($extension !== '') {
                return $extension;
            }
        }

        return MimeTypes::getDefault()->getExtensions($mime)[0] ?? 'bin';
    }
}
