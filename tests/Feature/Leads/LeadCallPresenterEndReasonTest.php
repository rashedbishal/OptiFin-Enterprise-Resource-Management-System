<?php

use App\Enums\CallEndReason;
use App\Support\LeadCallPresenter;

/*
 * LeadCallPresenter::endReason() is an exhaustive match() over CallEndReason
 * with no default arm — a new case throws UnhandledMatchError unless this
 * file is updated in the same change. A Feature test, not Unit: endReason()
 * calls __() for every case, which needs a booted translator.
 */
test('endReason() has text for every CallEndReason case', function (CallEndReason $reason) {
    expect(LeadCallPresenter::endReason($reason))->toBeString()->not->toBeEmpty();
})->with('call end reasons');

dataset('call end reasons', fn () => CallEndReason::cases());
