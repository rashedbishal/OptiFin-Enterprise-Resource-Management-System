<?php

use App\Enums\CallingApiError;

/*
 * userMessage() calls __() and so needs the framework booted — CallingApiError's
 * classify()/pure-logic coverage lives in tests/Unit/Enums/CallingApiErrorTest.php
 * instead. This exists so every case (including MissingPermission, added for
 * WhatsApp Outbound Calling) keeps a real, non-empty, fixed message rather than
 * ever falling through to Meta's own unreviewed text.
 */
test('every CallingApiError case has a fixed, non-empty user message that never echoes Meta text', function (CallingApiError $error) {
    expect($error->userMessage())->toBeString()->not->toBeEmpty();
})->with('calling api errors');

dataset('calling api errors', fn () => CallingApiError::cases());
