<?php

namespace App\Enums;

/**
 * Outcome of the daily Meta token check (App\Services\MetaTokenChecker).
 */
enum TokenCheckStatus: string
{
    /** Meta accepts the token. */
    case Valid = 'valid';

    /** Meta rejects the token (expired, revoked, password changed…). */
    case Invalid = 'invalid';

    /** The source has no access token configured. */
    case Missing = 'missing';

    /** Meta couldn't be reached, so the token's state is unknown this time. */
    case Unreachable = 'unreachable';
}
