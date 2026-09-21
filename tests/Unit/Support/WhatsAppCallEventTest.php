<?php

use App\Support\WhatsAppCallEvent as Event;
use Illuminate\Support\Carbon;
use Tests\Support\WhatsAppCallFixtures as Wa;

/*
 * WhatsAppCallEvent is a pure parser: one `calls[]` item plus its `changes[]`
 * context in, either a normalized event or a log-safe ignore reason out. No
 * framework, database or cache is involved, so these are Unit tests.
 *
 * @param  array<string, mixed>  $call
 * @param  array<string, mixed>  $valueExtra
 */
function parseCall(array $call, array $valueExtra = []): Event|string
{
    return Event::fromWebhook(Wa::change([$call], $valueExtra), $call);
}

test('a valid connect is normalized into an event', function () {
    $event = parseCall(Wa::connect(['timestamp' => '1750000000']));

    expect($event)->toBeInstanceOf(Event::class);
    expect($event->isConnect())->toBeTrue();
    expect($event->isTerminate())->toBeFalse();
    expect($event->callId)->toBe('wacid.CALL-1');
    expect($event->callerPhone)->toBe(Wa::CALLER);
    expect($event->callerName)->toBe('Caller Name');
    expect($event->phoneNumberId)->toBe(Wa::PHONE_NUMBER_ID);
    expect($event->timestamp)->toBe(1750000000);
    expect($event->sdp)->toBe(Wa::sdp());
    expect($event->hasErrors)->toBeFalse();
});

test('a valid terminate carries the status token, times and duration', function () {
    $event = parseCall(Wa::terminate([
        'status' => 'COMPLETED',
        'start_time' => '1750000000',
        'end_time' => '1750000120',
        'duration' => 120,
    ]));

    expect($event)->toBeInstanceOf(Event::class);
    expect($event->isTerminate())->toBeTrue();
    expect($event->statusToken)->toBe('COMPLETED');
    expect($event->startTime)->toBe(1750000000);
    expect($event->endTime)->toBe(1750000120);
    expect($event->duration)->toBe(120);
    expect($event->pickedUp())->toBeTrue();
    expect($event->sdp)->toBeNull();
});

test('a terminate without start_time is not treated as picked up', function () {
    $event = parseCall(Wa::terminate(['status' => 'FAILED']));

    expect($event->pickedUp())->toBeFalse();
    expect($event->startTime)->toBeNull();
    expect($event->duration)->toBeNull();
});

test('a terminate status is normalized whether Meta sends a string or an array', function (mixed $status, ?string $expected) {
    expect(parseCall(Wa::terminate(['status' => $status]))->statusToken)->toBe($expected);
})->with([
    'plain string' => ['Completed', 'COMPLETED'],
    'array of values' => [['Failed', 'Completed'], 'FAILED|COMPLETED'],
    'placeholder text is stripped to letters' => ['[FAILED | COMPLETED]', 'FAILED|COMPLETED'],
    'absent' => [null, null],
    'not a string' => [123, null],
]);

test('errors at the value level mark the terminate as failed and store the Meta code verbatim', function () {
    $event = parseCall(Wa::terminate(), ['errors' => [['code' => 138021, 'message' => 'Media receive timeout']]]);

    expect($event->hasErrors)->toBeTrue();
    expect($event->errorToken)->toBe('ERROR:138021');
    expect($event->rawMetaStatus())->toBe('ERROR:138021');
});

test('an errors array without a usable code is still recorded as an error', function () {
    $event = parseCall(Wa::terminate(), ['errors' => [['message' => 'no code here']]]);

    expect($event->hasErrors)->toBeTrue();
    expect($event->rawMetaStatus())->toBe('ERROR:UNKNOWN');
});

test('a terminate does not need a usable caller', function () {
    $event = parseCall(Wa::terminate(['from' => null]), ['contacts' => []]);

    expect($event)->toBeInstanceOf(Event::class);
    expect($event->callerPhone)->toBeNull();
});

test('a structurally malformed item is ignored with a reason, never an exception', function (mixed $call) {
    expect(Event::fromWebhook(Wa::change([]), $call))->toBe(Event::REASON_MALFORMED);
})->with([
    'a string' => ['nonsense'],
    'null' => [null],
    'a number' => [42],
]);

test('an item whose changes value is not an array is malformed', function () {
    expect(Event::fromWebhook(['value' => 'not-an-array'], Wa::connect()))->toBe(Event::REASON_MALFORMED);
    expect(Event::fromWebhook([], Wa::connect()))->toBe(Event::REASON_MALFORMED);
});

test('a missing or empty call id is ignored', function (mixed $id) {
    expect(parseCall(Wa::connect(['id' => $id])))->toBe(Event::REASON_MISSING_ID);
})->with([
    'null' => [null],
    'empty string' => [''],
    'not a string' => [12345],
]);

test('an oversized or whitespace-containing call id is ignored', function (string $id) {
    expect(parseCall(Wa::connect(['id' => $id])))->toBe(Event::REASON_INVALID_ID);
})->with([
    'one over the limit' => [str_repeat('a', Event::MAX_CALL_ID_LENGTH + 1)],
    'contains a space' => ['wacid.has space'],
    'contains a newline' => ["wacid.line\nbreak"],
]);

test('a call id at exactly the length limit is accepted', function () {
    expect(parseCall(Wa::connect(['id' => str_repeat('a', Event::MAX_CALL_ID_LENGTH)])))->toBeInstanceOf(Event::class);
});

test('only connect and terminate events are supported', function (mixed $event) {
    expect(parseCall(Wa::connect(['event' => $event])))->toBe(Event::REASON_UNSUPPORTED_EVENT);
})->with([
    'call_created' => ['call_created'],
    'recording' => ['call_recording_available'],
    'wrong case' => ['CONNECT'],
    'absent' => [null],
]);

test('direction is optional on connect but must be USER_INITIATED when present', function () {
    $absent = Wa::connect();
    unset($absent['direction']);

    expect(parseCall($absent))->toBeInstanceOf(Event::class);
    expect(parseCall(Wa::connect(['direction' => 'USER_INITIATED'])))->toBeInstanceOf(Event::class);
    expect(parseCall(Wa::connect(['direction' => 'BUSINESS_INITIATED'])))->toBe(Event::REASON_UNSUPPORTED_DIRECTION);
    expect(parseCall(Wa::connect(['direction' => ['USER_INITIATED']])))->toBe(Event::REASON_UNSUPPORTED_DIRECTION);
});

test('a missing timestamp falls back to now and an invalid one is ignored', function () {
    $noTimestamp = Wa::connect();
    unset($noTimestamp['timestamp']);

    expect(parseCall($noTimestamp)->timestamp)->toBeGreaterThanOrEqual(Carbon::now()->getTimestamp() - 2);

    foreach (['abc', '-5', '0', '12.5', '', ['1']] as $bad) {
        expect(parseCall(Wa::connect(['timestamp' => $bad])))->toBe(Event::REASON_INVALID_TIMESTAMP);
    }
});

test('a connect needs an offer session', function (mixed $session) {
    expect(parseCall(Wa::connect(['session' => $session])))->toBe(Event::REASON_MISSING_OFFER);
})->with([
    'no session' => [null],
    'session not an array' => ['v=0'],
    'an answer instead of an offer' => [['sdp_type' => 'answer', 'sdp' => "v=0\r\n"]],
    'no sdp_type' => [['sdp' => "v=0\r\n"]],
    'no sdp' => [['sdp_type' => 'offer']],
    'empty sdp' => [['sdp_type' => 'offer', 'sdp' => '']],
    'whitespace-only sdp' => [['sdp_type' => 'offer', 'sdp' => "  \r\n"]],
    'sdp not a string' => [['sdp_type' => 'offer', 'sdp' => ['v=0']]],
]);

test('an SDP over 16 KB is ignored and one at exactly the limit is accepted', function () {
    $atLimit = "v=0\r\n".str_repeat('a', Event::MAX_SDP_BYTES - 5);

    expect(strlen($atLimit))->toBe(Event::MAX_SDP_BYTES);
    expect(parseCall(Wa::connect(['session' => ['sdp_type' => 'offer', 'sdp' => $atLimit]])))->toBeInstanceOf(Event::class);
    expect(parseCall(Wa::connect(['session' => ['sdp_type' => 'offer', 'sdp' => $atLimit.'a']])))->toBe(Event::REASON_OVERSIZED_SDP);
});

test('an SDP that does not start with v=0 is ignored', function () {
    expect(parseCall(Wa::connect(['session' => ['sdp_type' => 'offer', 'sdp' => "o=- 1 2 IN IP4 0.0.0.0\r\nv=0\r\n"]])))
        ->toBe(Event::REASON_INVALID_SDP);
    expect(parseCall(Wa::connect(['session' => ['sdp_type' => 'offer', 'sdp' => '<html>not sdp</html>']])))
        ->toBe(Event::REASON_INVALID_SDP);
});

test('the caller is taken from `from` first', function () {
    $event = parseCall(Wa::connect(['from' => '15550001111']), ['contacts' => [['profile' => ['name' => 'Other'], 'wa_id' => '15550002222']]]);

    expect($event->callerPhone)->toBe('15550001111');
});

test('the caller falls back to contacts wa_id when `from` is omitted', function () {
    $call = Wa::connect();
    unset($call['from']);

    $event = parseCall($call);

    expect($event->callerPhone)->toBe(Wa::CALLER);
    expect($event->callerName)->toBe('Caller Name');
});

test('a `from` that is the business own number is never accepted as the caller', function (string $businessField) {
    // Meta's older documentation example has `from` equal to the business
    // display number; the real caller is then the contact's wa_id.
    $call = Wa::connect(['from' => Wa::BUSINESS_NUMBER]);
    $value = $businessField === 'to'
        ? ['metadata' => ['phone_number_id' => Wa::PHONE_NUMBER_ID, 'display_phone_number' => '19998887777']]
        : [];
    if ($businessField === 'to') {
        $call['to'] = Wa::BUSINESS_NUMBER;
    }

    expect(parseCall($call, $value)->callerPhone)->toBe(Wa::CALLER);
})->with(['matches display_phone_number' => ['display'], 'matches `to`' => ['to']]);

test('a formatted display number is compared by its digits', function () {
    $call = Wa::connect(['from' => '16315553601']);

    $event = parseCall($call, ['metadata' => ['phone_number_id' => Wa::PHONE_NUMBER_ID, 'display_phone_number' => '+1 (631) 555-3601']]);

    expect($event->callerPhone)->toBe(Wa::CALLER);
});

test('a connect with no usable caller identity is ignored', function (array $callOverrides, array $value) {
    $call = Wa::connect($callOverrides);

    expect(parseCall($call, $value))->toBe(Event::REASON_UNUSABLE_CALLER);
})->with([
    'username-only caller: no from, no contacts' => [['from' => null], ['contacts' => []]],
    'no from and a contact without wa_id' => [['from' => null], ['contacts' => [['profile' => ['name' => 'X'], 'user_id' => 'BSUID']]]],
    'from too short' => [['from' => '123'], ['contacts' => []]],
    'from with a plus and spaces' => [['from' => '+1 555 000 1111'], ['contacts' => []]],
    'from and wa_id both the business number' => [['from' => Wa::BUSINESS_NUMBER], ['contacts' => [['wa_id' => Wa::BUSINESS_NUMBER]]]],
]);

test('the contact name only comes from the contact matching the caller', function () {
    $event = parseCall(
        Wa::connect(['from' => '15550001111']),
        ['contacts' => [['profile' => ['name' => 'Wrong Person'], 'wa_id' => '15550009999'], ['profile' => ['name' => 'Right Person'], 'wa_id' => '15550001111']]],
    );

    expect($event->callerName)->toBe('Right Person');
});

test('a phone_number_id mismatch is only reported when both sides are known', function () {
    $event = parseCall(Wa::connect());

    expect($event->matchesPhoneNumberId(Wa::PHONE_NUMBER_ID))->toBeTrue();
    expect($event->matchesPhoneNumberId('999'))->toBeFalse();
    expect($event->matchesPhoneNumberId(null))->toBeTrue();
    expect($event->matchesPhoneNumberId(''))->toBeTrue();
    expect($event->matchesPhoneNumberId(['not-a-string']))->toBeTrue();

    $noMetadata = parseCall(Wa::connect(), ['metadata' => []]);
    expect($noMetadata->phoneNumberId)->toBeNull();
    expect($noMetadata->matchesPhoneNumberId('999'))->toBeTrue();
});

test('loggableId keeps only printable ASCII and is bounded', function () {
    expect(Event::loggableId(['id' => "wacid.AB\r\nCD\t"]))->toBe('wacid.ABCD');
    expect(Event::loggableId(['id' => str_repeat('x', 500)]))->toHaveLength(Event::MAX_CALL_ID_LENGTH);
    expect(Event::loggableId(['id' => '']))->toBeNull();
    expect(Event::loggableId(['id' => 5]))->toBeNull();
    expect(Event::loggableId('not-an-array'))->toBeNull();
    expect(Event::loggableId(['id' => "\x00\x01"]))->toBeNull();
});

test('a debug dump of an event never reveals the SDP or the caller number', function () {
    $event = parseCall(Wa::connect());

    ob_start();
    var_dump($event);
    $dump = print_r($event, true).ob_get_clean();

    expect($dump)->not->toContain(Wa::SDP_SENTINEL);
    expect($dump)->not->toContain(Wa::CALLER);
    expect($dump)->toContain('wacid.CALL-1');
});

// ── The SDP's final CRLF (Laravel's TrimStrings strips it from JSON bodies) ──

/**
 * @return array<string, mixed>
 */
function connectWithSdp(string $sdp): array
{
    return Wa::connect(['session' => ['sdp_type' => 'offer', 'sdp' => $sdp]]);
}

test('an SDP that already ends in CRLF is kept byte-for-byte, with no second terminator', function () {
    $event = parseCall(connectWithSdp(Wa::sdp()));

    expect($event->sdp)->toBe(Wa::sdp());
    expect($event->sdp)->toEndWith("\r\n");
    expect($event->sdp)->not->toEndWith("\r\n\r\n");
});

test('an SDP whose final terminator was trimmed away gets exactly one CRLF restored', function () {
    $trimmed = rtrim(Wa::sdp());

    $event = parseCall(connectWithSdp($trimmed));

    expect($event->sdp)->toBe($trimmed."\r\n");
    expect($event->sdp)->toBe(Wa::sdp());
    expect(substr_count($event->sdp, "\r\n"))->toBe(substr_count(Wa::sdp(), "\r\n"));
});

test('restoring the terminator never touches a single content line', function () {
    $sdp = "v=0\r\no=- 46117 2 IN IP4 127.0.0.1\r\ns=-\r\nt=0 0\r\na=group:BUNDLE 0\r\nm=audio 9 UDP/TLS/RTP/SAVPF 111 0 8\r\na=setup:actpass\r\na=candidate:1 1 udp 2113937151 192.0.2.1 54321 typ host\r\na=rtpmap:111 opus/48000/2\r\na=fmtp:111 minptime=10;useinbandfec=1";

    $event = parseCall(connectWithSdp($sdp));

    expect($event->sdp)->toBe($sdp."\r\n");
    expect(substr($event->sdp, 0, -2))->toBe($sdp);
});

test('an SDP that already ends in a line terminator is never given another', function (string $ending, string $expectedSuffix) {
    $body = "v=0\r\no=- 1 2 IN IP4 127.0.0.1\r\ns=-\r\nt=0 0\r\nm=audio 9 UDP/TLS/RTP/SAVPF 111".$ending;

    $event = parseCall(connectWithSdp($body));

    expect($event->sdp)->toBe("v=0\r\no=- 1 2 IN IP4 127.0.0.1\r\ns=-\r\nt=0 0\r\nm=audio 9 UDP/TLS/RTP/SAVPF 111".$expectedSuffix);
})->with([
    'CRLF (what Meta sends)' => ["\r\n", "\r\n"],
    'a doubled CRLF reaching the parser directly is preserved, not collapsed' => ["\r\n\r\n", "\r\n\r\n"],
    'a bare LF' => ["\n", "\n"],
    'a bare CR is completed to one CRLF, not followed by another' => ["\r", "\r\n"],
    'no terminator at all' => ['', "\r\n"],
]);

test('the 16 KB limit still applies to the SDP as received, before any terminator is restored', function () {
    $atLimit = "v=0\r\n".str_repeat('a', Event::MAX_SDP_BYTES - 5);

    expect(strlen($atLimit))->toBe(Event::MAX_SDP_BYTES);

    $accepted = parseCall(connectWithSdp($atLimit));

    expect($accepted)->toBeInstanceOf(Event::class);
    expect($accepted->sdp)->toBe($atLimit."\r\n");
    expect(parseCall(connectWithSdp($atLimit.'a')))->toBe(Event::REASON_OVERSIZED_SDP);
});

test('restoring the terminator never makes an invalid SDP valid', function (string $sdp, string $reason) {
    expect(parseCall(connectWithSdp($sdp)))->toBe($reason);
})->with([
    'not SDP' => ['<html>not sdp</html>', Event::REASON_INVALID_SDP],
    'v=0 is not the first line' => ["o=- 1 2 IN IP4 0.0.0.0\r\nv=0", Event::REASON_INVALID_SDP],
    'whitespace only' => ["   \r\n", Event::REASON_MISSING_OFFER],
    'empty' => ['', Event::REASON_MISSING_OFFER],
    'oversized' => ["v=0\r\n".str_repeat('a', Event::MAX_SDP_BYTES), Event::REASON_OVERSIZED_SDP],
]);

test('a terminate has no SDP, so nothing is ever restored on it', function () {
    expect(parseCall(Wa::terminate())->sdp)->toBeNull();
});
