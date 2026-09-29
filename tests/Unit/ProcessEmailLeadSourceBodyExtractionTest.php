<?php

use App\Jobs\ProcessEmailLeadSource;
use App\Models\LeadSource;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;

/**
 * Builds a stdClass shaped like an `imap_fetchstructure()` node.
 *
 * @param  array<int, object>  $parts
 * @param  int|string|null  $bytes  F10 remediation — passed through verbatim
 *                                  (including a deliberately non-numeric
 *                                  string, for testing that case) so a test
 *                                  can simulate a real server's reported
 *                                  BODYSTRUCTURE `bytes` field, or its
 *                                  absence.
 */
function mimePart(int $type, ?string $subtype, int $encoding = 0, array $parts = [], ?string $filename = null, int|string|null $bytes = null): object
{
    $part = new stdClass;
    $part->type = $type;
    $part->subtype = $subtype;
    $part->encoding = $encoding;

    if ($parts !== []) {
        $part->parts = $parts;
    }

    if ($filename !== null) {
        $param = new stdClass;
        $param->attribute = 'filename';
        $param->value = $filename;
        $part->dparameters = [$param];
    }

    if ($bytes !== null) {
        $part->bytes = $bytes;
    }

    return $part;
}

/**
 * @return array<int, array{part_number: string, encoding: int, mime: string, filename: ?string, bytes: ?int}>
 */
function findAttachmentParts(object $structure): array
{
    $job = new ProcessEmailLeadSource(new LeadSource);
    $method = new ReflectionMethod($job, 'findAttachmentParts');

    return $method->invoke($job, $structure);
}

/**
 * F10 remediation (forensic audit Batch B).
 */
function isDefinitelyOversized(?int $reportedBytes): bool
{
    $job = new ProcessEmailLeadSource(new LeadSource);
    $method = new ReflectionMethod($job, 'isDefinitelyOversized');

    return $method->invoke($job, $reportedBytes);
}

/**
 * @return array{plain: ?array{0: ?string, 1: int}, html: ?array{0: ?string, 1: int}}
 */
function findTextParts(object $structure): array
{
    $job = new ProcessEmailLeadSource(new LeadSource);
    $method = new ReflectionMethod($job, 'findTextParts');

    return $method->invoke($job, $structure);
}

function stripAntiClippingPadding(string $text): string
{
    $job = new ProcessEmailLeadSource(new LeadSource);
    $method = new ReflectionMethod($job, 'stripAntiClippingPadding');

    return $method->invoke($job, $text);
}

function attachmentTypeCategory(string $mime): string
{
    $job = new ProcessEmailLeadSource(new LeadSource);
    $method = new ReflectionMethod($job, 'attachmentTypeCategory');

    return $method->invoke($job, $mime);
}

function normalizeMessageId(mixed $value): ?string
{
    $job = new ProcessEmailLeadSource(new LeadSource);
    $method = new ReflectionMethod($job, 'normalizeMessageId');

    return $method->invoke($job, $value);
}

function classifyFailure(?object $header, Throwable $e): string
{
    $job = new ProcessEmailLeadSource(new LeadSource);
    $method = new ReflectionMethod($job, 'classifyFailure');

    return $method->invoke($job, $header, $e);
}

function attachmentExtension(?string $filename, string $mime): string
{
    $job = new ProcessEmailLeadSource(new LeadSource);
    $method = new ReflectionMethod($job, 'attachmentExtension');

    return $method->invoke($job, $filename, $mime);
}

test('a non-multipart plain text message resolves to the whole body as plain, with no html', function () {
    $structure = mimePart(type: 0, subtype: 'PLAIN', encoding: 0);

    expect(findTextParts($structure))->toBe(['plain' => [null, 0], 'html' => null]);
});

test('a non-multipart base64 html message resolves to html only, with no plain fallback', function () {
    $structure = mimePart(type: 0, subtype: 'HTML', encoding: 3);

    expect(findTextParts($structure))->toBe(['plain' => null, 'html' => [null, 3]]);
});

test('multipart/alternative resolves both the plain and html parts regardless of order', function () {
    $htmlFirst = mimePart(type: 1, subtype: 'ALTERNATIVE', parts: [
        mimePart(type: 0, subtype: 'HTML', encoding: 4),
        mimePart(type: 0, subtype: 'PLAIN', encoding: 0),
    ]);

    expect(findTextParts($htmlFirst))->toBe(['plain' => ['2', 0], 'html' => ['1', 4]]);

    $plainFirst = mimePart(type: 1, subtype: 'ALTERNATIVE', parts: [
        mimePart(type: 0, subtype: 'PLAIN', encoding: 0),
        mimePart(type: 0, subtype: 'HTML', encoding: 4),
    ]);

    expect(findTextParts($plainFirst))->toBe(['plain' => ['1', 0], 'html' => ['2', 4]]);
});

test('multipart/alternative with only an html part resolves html with no plain fallback', function () {
    $structure = mimePart(type: 1, subtype: 'ALTERNATIVE', parts: [
        mimePart(type: 0, subtype: 'HTML', encoding: 4),
    ]);

    expect(findTextParts($structure))->toBe(['plain' => null, 'html' => ['1', 4]]);
});

test('a forwarded message wraps multipart/alternative inside multipart/mixed alongside an attachment', function () {
    $structure = mimePart(type: 1, subtype: 'MIXED', parts: [
        mimePart(type: 1, subtype: 'ALTERNATIVE', parts: [
            mimePart(type: 0, subtype: 'PLAIN', encoding: 0),
            mimePart(type: 0, subtype: 'HTML', encoding: 3),
        ]),
        mimePart(type: 3, subtype: 'PDF', encoding: 3),
    ]);

    expect(findTextParts($structure))->toBe(['plain' => ['1.1', 0], 'html' => ['1.2', 3]]);
});

test('a forwarded message with only base64 html anywhere in the tree still resolves that part', function () {
    $structure = mimePart(type: 1, subtype: 'MIXED', parts: [
        mimePart(type: 1, subtype: 'ALTERNATIVE', parts: [
            mimePart(type: 0, subtype: 'HTML', encoding: 3),
        ]),
        mimePart(type: 3, subtype: 'PDF', encoding: 3),
    ]);

    expect(findTextParts($structure))->toBe(['plain' => null, 'html' => ['1.1', 3]]);
});

test('a non-multipart message has no attachments', function () {
    $structure = mimePart(type: 0, subtype: 'PLAIN', encoding: 0);

    expect(findAttachmentParts($structure))->toBe([]);
});

test('a multipart/alternative body with no attached files has no attachments', function () {
    $structure = mimePart(type: 1, subtype: 'ALTERNATIVE', parts: [
        mimePart(type: 0, subtype: 'PLAIN', encoding: 0),
        mimePart(type: 0, subtype: 'HTML', encoding: 4),
    ]);

    expect(findAttachmentParts($structure))->toBe([]);
});

test('a forwarded message with one attachment finds it alongside the nested plain/html body', function () {
    $structure = mimePart(type: 1, subtype: 'MIXED', parts: [
        mimePart(type: 1, subtype: 'ALTERNATIVE', parts: [
            mimePart(type: 0, subtype: 'PLAIN', encoding: 0),
            mimePart(type: 0, subtype: 'HTML', encoding: 3),
        ]),
        mimePart(type: 3, subtype: 'PDF', encoding: 3, filename: 'invoice.pdf'),
    ]);

    expect(findAttachmentParts($structure))->toBe([
        ['part_number' => '2', 'encoding' => 3, 'mime' => 'application/pdf', 'filename' => 'invoice.pdf', 'bytes' => null],
    ]);
});

test('several attached files in one message are all returned, in order', function () {
    $structure = mimePart(type: 1, subtype: 'MIXED', parts: [
        mimePart(type: 0, subtype: 'PLAIN', encoding: 0),
        mimePart(type: 5, subtype: 'PNG', encoding: 3, filename: 'photo.png'),
        mimePart(type: 3, subtype: 'PDF', encoding: 3, filename: 'invoice.pdf'),
    ]);

    expect(findAttachmentParts($structure))->toBe([
        ['part_number' => '2', 'encoding' => 3, 'mime' => 'image/png', 'filename' => 'photo.png', 'bytes' => null],
        ['part_number' => '3', 'encoding' => 3, 'mime' => 'application/pdf', 'filename' => 'invoice.pdf', 'bytes' => null],
    ]);
});

test('a text/plain part with a filename is treated as an attachment, not the message body', function () {
    $structure = mimePart(type: 1, subtype: 'MIXED', parts: [
        mimePart(type: 0, subtype: 'PLAIN', encoding: 0),
        mimePart(type: 0, subtype: 'PLAIN', encoding: 0, filename: 'notes.txt'),
    ]);

    expect(findAttachmentParts($structure))->toBe([
        ['part_number' => '2', 'encoding' => 0, 'mime' => 'text/plain', 'filename' => 'notes.txt', 'bytes' => null],
    ]);
});

// F10 remediation (forensic audit Batch B) — pre-fetch attachment size guard

test('findAttachmentParts extracts a numeric bytes value from the structure verbatim', function () {
    $structure = mimePart(type: 1, subtype: 'MIXED', parts: [
        mimePart(type: 0, subtype: 'PLAIN', encoding: 0),
        mimePart(type: 3, subtype: 'PDF', encoding: 3, filename: 'invoice.pdf', bytes: 12345),
    ]);

    expect(findAttachmentParts($structure))->toBe([
        ['part_number' => '2', 'encoding' => 3, 'mime' => 'application/pdf', 'filename' => 'invoice.pdf', 'bytes' => 12345],
    ]);
});

test('findAttachmentParts falls back to a null bytes value when the structure carries a non-numeric one', function () {
    // A real IMAP server is not expected to send this, but the extension
    // exposes structure fields as loosely-typed stdClass properties with no
    // guarantee about their shape — this proves a malformed value is never
    // blindly cast, only ever treated as "no usable metadata".
    $structure = mimePart(type: 1, subtype: 'MIXED', parts: [
        mimePart(type: 0, subtype: 'PLAIN', encoding: 0),
        mimePart(type: 3, subtype: 'PDF', encoding: 3, filename: 'invoice.pdf', bytes: 'not-a-number'),
    ]);

    expect(findAttachmentParts($structure))->toBe([
        ['part_number' => '2', 'encoding' => 3, 'mime' => 'application/pdf', 'filename' => 'invoice.pdf', 'bytes' => null],
    ]);
});

test('a reported size clearly over the limit, even after the pre-fetch headroom multiplier, is definitely oversized', function () {
    // MAX_ATTACHMENT_BYTES is 25 MiB; comfortably past even the most
    // generous plausible encoding inflation.
    expect(isDefinitelyOversized(100 * 1024 * 1024))->toBeTrue();
});

test('a reported size only slightly over the raw byte limit stays within the base64-inflation headroom and is not rejected pre-fetch', function () {
    // 25 MiB decoded, base64-encoded, is roughly 33% larger on the wire —
    // comfortably under the 1.34x headroom, so this must NOT be rejected
    // before the real, authoritative post-decode check gets to see it.
    $rawBase64Size = (int) ceil(25 * 1024 * 1024 * 4 / 3);

    expect(isDefinitelyOversized($rawBase64Size))->toBeFalse();
});

test('a reported size that unambiguously exceeds even the base64-inflation headroom is rejected pre-fetch', function () {
    $wellPastHeadroom = (int) ceil(25 * 1024 * 1024 * 1.34) + 1;

    expect(isDefinitelyOversized($wellPastHeadroom))->toBeTrue();
});

test('a normal-sized reported attachment is never rejected pre-fetch', function () {
    expect(isDefinitelyOversized(2 * 1024 * 1024))->toBeFalse();
});

test('a missing (null) reported size is never rejected pre-fetch, falling back to the existing post-decode check', function () {
    expect(isDefinitelyOversized(null))->toBeFalse();
});

test('attachment type category is classified from the mime type', function () {
    expect(attachmentTypeCategory('image/png'))->toBe('image');
    expect(attachmentTypeCategory('video/mp4'))->toBe('video');
    expect(attachmentTypeCategory('audio/ogg'))->toBe('audio');
    expect(attachmentTypeCategory('application/pdf'))->toBe('file');
});

test('attachment extension prefers the sender-provided filename over the mime type', function () {
    expect(attachmentExtension('invoice.pdf', 'application/octet-stream'))->toBe('pdf');
    expect(attachmentExtension(null, 'application/pdf'))->toBe('pdf');
    expect(attachmentExtension('no-extension', 'image/png'))->toBe('png');
});

test('anti-clipping padding lines of zero-width characters and figure spaces collapse away', function () {
    // Mirrors a real Hostinger padding line: combining grapheme joiner,
    // zero-width non-joiner, figure space (U+2007), and BOM, repeated.
    $padding = "\u{034F}\u{0020}\u{200C}\u{0020}\u{0020}\u{0020}\u{2007}\u{0020}\u{FEFF}\u{0020}\u{034F}\u{0020}\u{200C}";
    $text = "Verify it's you to stay secure\n{$padding}\n{$padding}\nHere is your code";

    expect(stripAntiClippingPadding($text))->toBe("Verify it's you to stay secure\n\nHere is your code");
});

test('a genuinely blank paragraph break between two lines is preserved', function () {
    expect(stripAntiClippingPadding("First paragraph.\n\nSecond paragraph."))
        ->toBe("First paragraph.\n\nSecond paragraph.");
});

test('excess blank lines collapse to a single blank line', function () {
    expect(stripAntiClippingPadding("First.\n\n\n\n\nSecond."))
        ->toBe("First.\n\nSecond.");
});

// EMAIL-LOSS-1

test('a well-formed Message-ID header is preserved verbatim after trimming', function () {
    expect(normalizeMessageId(' <abc123@example.com> '))->toBe('<abc123@example.com>');
});

test('a missing or non-string Message-ID normalizes to null', function () {
    expect(normalizeMessageId(null))->toBeNull();
    expect(normalizeMessageId(123))->toBeNull();
});

test('a blank or whitespace-only Message-ID normalizes to null', function () {
    expect(normalizeMessageId(''))->toBeNull();
    expect(normalizeMessageId('   '))->toBeNull();
});

test('a failure with no header at all is classified as a permanent unreadable-header failure', function () {
    expect(classifyFailure(null, new RuntimeException('Unable to read headers')))
        ->toBe('permanent_unreadable_header');
});

test('a genuine PostgreSQL deadlock is classified as transient DB concurrency', function () {
    $header = new stdClass;
    $deadlock = new DeadlockException('SQLSTATE[40P01]: deadlock detected');

    expect(classifyFailure($header, $deadlock))->toBe('transient_db_concurrency');
});

test('a generic database query exception is classified as transient DB', function () {
    $header = new stdClass;
    $queryException = new QueryException('pgsql', 'select 1', [], new RuntimeException('connection refused'));

    expect(classifyFailure($header, $queryException))->toBe('transient_db');
});

test('an unrecognized exception is classified as unknown, defaulting to retryable rather than assumed permanent', function () {
    $header = new stdClass;

    expect(classifyFailure($header, new RuntimeException('something unexpected')))->toBe('unknown');
});
