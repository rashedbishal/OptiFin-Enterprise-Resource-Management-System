<?php

use App\Enums\LeadCallPermissionStatus;

/*
 * WhatsApp Outbound Calling — pure enum logic, no framework, same discipline
 * as CallStatusTest.
 */

test('values() lists every case', function () {
    expect(LeadCallPermissionStatus::values())->toBe(['no_permission', 'requested', 'temporary', 'permanent', 'rejected']);
});

test('every case has a non-empty label', function (LeadCallPermissionStatus $status) {
    expect($status->label())->toBeString()->not->toBeEmpty();
})->with('lead call permission statuses');

dataset('lead call permission statuses', fn () => LeadCallPermissionStatus::cases());
