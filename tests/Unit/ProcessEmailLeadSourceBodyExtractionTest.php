<?php

use App\Jobs\ProcessEmailLeadSource;
use App\Models\LeadSource;

/**
 * Builds a stdClass shaped like an `imap_fetchstructure()` node.
 *
 * @param  array<int, object>  $parts
 */
function mimePart(int $type, ?string $subtype, int $encoding = 0, array $parts = [], ?string $filename = null): object
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

    return $part;
}

/**
 * @return array<int, array{part_number: string, encoding: int, mime: string, filename: ?string}>
 */
function findAttachmentParts(object $structure): array
{
    $job = new ProcessEmailLeadSource(new LeadSource);
    $method = new ReflectionMethod($job, 'findAttachmentParts');

    return $method->invoke($job, $structure);
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
        ['part_number' => '2', 'encoding' => 3, 'mime' => 'application/pdf', 'filename' => 'invoice.pdf'],
    ]);
});

test('several attached files in one message are all returned, in order', function () {
    $structure = mimePart(type: 1, subtype: 'MIXED', parts: [
        mimePart(type: 0, subtype: 'PLAIN', encoding: 0),
        mimePart(type: 5, subtype: 'PNG', encoding: 3, filename: 'photo.png'),
        mimePart(type: 3, subtype: 'PDF', encoding: 3, filename: 'invoice.pdf'),
    ]);

    expect(findAttachmentParts($structure))->toBe([
        ['part_number' => '2', 'encoding' => 3, 'mime' => 'image/png', 'filename' => 'photo.png'],
        ['part_number' => '3', 'encoding' => 3, 'mime' => 'application/pdf', 'filename' => 'invoice.pdf'],
    ]);
});

test('a text/plain part with a filename is treated as an attachment, not the message body', function () {
    $structure = mimePart(type: 1, subtype: 'MIXED', parts: [
        mimePart(type: 0, subtype: 'PLAIN', encoding: 0),
        mimePart(type: 0, subtype: 'PLAIN', encoding: 0, filename: 'notes.txt'),
    ]);

    expect(findAttachmentParts($structure))->toBe([
        ['part_number' => '2', 'encoding' => 0, 'mime' => 'text/plain', 'filename' => 'notes.txt'],
    ]);
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
