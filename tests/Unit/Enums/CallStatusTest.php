<?php

use App\Enums\CallStatus;

/*
 * The structural edges of the approved WhatsApp-calling state machine. Pure
 * enum logic — no framework — so this is a Unit test. The guards on each edge
 * (who may trigger it, the Meta status that picks Completed/Missed/Failed,
 * the timeouts) belong to the later state-machine service, not to this enum.
 */

test('only Initiated, Ringing, Answering and Connected are active', function () {
    expect(CallStatus::activeValues())->toBe(['initiated', 'ringing', 'answering', 'connected']);
});

test('the terminal statuses are exactly the four outcomes', function () {
    $terminal = array_filter(CallStatus::cases(), fn (CallStatus $status): bool => $status->isTerminal());

    expect(array_values(array_map(fn (CallStatus $status): string => $status->value, $terminal)))
        ->toBe(['completed', 'missed', 'rejected', 'failed']);
});

test('each status allows exactly the documented next statuses', function (CallStatus $from, array $expectedNext) {
    expect($from->allowedNext())->toBe($expectedNext);
})->with([
    'initiated' => [CallStatus::Initiated, [CallStatus::Ringing, CallStatus::Failed]],
    'ringing' => [CallStatus::Ringing, [CallStatus::Answering, CallStatus::Rejected, CallStatus::Missed, CallStatus::Failed]],
    'answering' => [CallStatus::Answering, [CallStatus::Connected, CallStatus::Completed, CallStatus::Missed, CallStatus::Rejected, CallStatus::Failed]],
    'connected' => [CallStatus::Connected, [CallStatus::Completed, CallStatus::Failed]],
    'completed' => [CallStatus::Completed, []],
    'missed' => [CallStatus::Missed, []],
    'rejected' => [CallStatus::Rejected, []],
    'failed' => [CallStatus::Failed, []],
]);

test('a terminal status can never move to any other status', function (CallStatus $terminal) {
    foreach (CallStatus::cases() as $next) {
        expect($terminal->canTransitionTo($next))->toBeFalse();
    }
})->with([
    'completed' => CallStatus::Completed,
    'missed' => CallStatus::Missed,
    'rejected' => CallStatus::Rejected,
    'failed' => CallStatus::Failed,
]);

test('an active call can never go back to Ringing', function (CallStatus $from) {
    expect($from->canTransitionTo(CallStatus::Ringing))->toBeFalse();
})->with([
    'ringing' => CallStatus::Ringing,
    'answering' => CallStatus::Answering,
    'connected' => CallStatus::Connected,
]);

/*
 * Initiated (WhatsApp Outbound Calling): a row can exist before Meta has
 * returned the call's wacid. It is active (blocks lead deletion, appears in
 * "my active calls") but reaches every other status only through Ringing —
 * never directly — mirroring how an inbound row is never created Initiated.
 */
test('Initiated only ever moves on to Ringing or Failed, never anything else', function () {
    foreach (CallStatus::cases() as $next) {
        $expected = in_array($next, [CallStatus::Ringing, CallStatus::Failed], true);

        expect(CallStatus::Initiated->canTransitionTo($next))->toBe($expected);
    }
});
