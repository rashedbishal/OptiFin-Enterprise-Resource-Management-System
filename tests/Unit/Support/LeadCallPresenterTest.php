<?php

use App\Enums\CallStatus;
use App\Support\LeadCallPresenter;

/*
 * LeadCallPresenter::statusColor() is an exhaustive match() over CallStatus
 * with no default arm — a new CallStatus case (e.g. Initiated, added for
 * WhatsApp Outbound Calling) throws UnhandledMatchError here unless this file
 * is updated in the same change. This test exists specifically to catch that
 * regression for every current and future case, not just Initiated.
 *
 * The equivalent test for endReason()'s own exhaustive match() over
 * CallEndReason lives in tests/Feature/Leads/LeadCallPresenterEndReasonTest.php,
 * not here: endReason() calls __() for every case, which needs a booted
 * translator this plain Unit suite (PHPUnit\Framework\TestCase, no app
 * container — see tests/Pest.php) does not provide.
 */
test('statusColor() has a color for every CallStatus case, including Initiated', function (CallStatus $status) {
    expect(LeadCallPresenter::statusColor($status))->toBeString()->not->toBeEmpty();
})->with('call statuses');

dataset('call statuses', fn () => CallStatus::cases());
