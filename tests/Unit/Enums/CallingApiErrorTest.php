<?php

use App\Enums\CallingApiError;

/*
 * WhatsApp Calling API error classification. Pure enum logic — no framework —
 * so this is a Unit test.
 */

test('Meta 138006 (no approved call permission) classifies as MissingPermission, never the generic 401 Authentication fallback', function () {
    expect(CallingApiError::classify(138006, 401))->toBe(CallingApiError::MissingPermission);
});

test('a bare 401 with no Meta error code still falls back to Authentication', function () {
    expect(CallingApiError::classify(null, 401))->toBe(CallingApiError::Authentication);
});
