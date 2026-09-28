<?php

use App\Support\CallTemplateText;

/*
 * WhatsApp Outbound Calling — CallTemplateText::sanitize() encodes the real,
 * live-Meta-verified constraint (error 132018) on a template text parameter:
 * no newline, no tab, no run of more than 4 consecutive spaces. Pure
 * function, no side effects — RequestLeadCallPermissionTest covers the
 * end-to-end wiring and the "storage untouched" guarantee.
 */

test('a normal single-line message is left unchanged', function () {
    expect(CallTemplateText::sanitize('I would like a quote for a new website.'))
        ->toBe('I would like a quote for a new website.');
});

test('newlines are replaced with a single space', function () {
    expect(CallTemplateText::sanitize("Hello\nHi\n1234"))->toBe('Hello Hi 1234');
});

test('the exact real Meta 132018 failing message is sanitized correctly', function () {
    expect(CallTemplateText::sanitize("Hello\nHi\n1234\nHello\n0099889\n11223\nHello brother"))
        ->toBe('Hello Hi 1234 Hello 0099889 11223 Hello brother');
});

test('CRLF and lone CR are each collapsed to a single space', function () {
    expect(CallTemplateText::sanitize("Hello\r\nWorld"))->toBe('Hello World');
    expect(CallTemplateText::sanitize("Hello\rWorld"))->toBe('Hello World');
});

test('tabs are replaced with a single space', function () {
    expect(CallTemplateText::sanitize("Hello\tWorld"))->toBe('Hello World');
});

test('runs of 2 or more consecutive spaces are collapsed to exactly one', function () {
    expect(CallTemplateText::sanitize('Hello    World'))->toBe('Hello World');
    expect(CallTemplateText::sanitize('Hello  World'))->toBe('Hello World');
    expect(CallTemplateText::sanitize('Hello          World'))->toBe('Hello World');
});

test('mixed newline, tab and excessive spaces are all normalized in one pass', function () {
    $result = CallTemplateText::sanitize("Hello\n\tWorld   Again\r\n\t  Once   more");

    expect($result)
        ->not->toContain("\n")
        ->not->toContain("\r")
        ->not->toContain("\t")
        ->not->toMatch('/ {2,}/');
});

test('leading/trailing whitespace introduced by the transform is trimmed', function () {
    expect(CallTemplateText::sanitize("\nHello World\n"))->toBe('Hello World');
});

test('an all-whitespace string sanitizes to an empty string', function () {
    expect(CallTemplateText::sanitize("\n\t   \n"))->toBe('');
});
