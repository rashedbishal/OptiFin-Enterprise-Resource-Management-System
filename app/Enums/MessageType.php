<?php

namespace App\Enums;

/**
 * Feature #15 Phase 1 supports free-form text only — `Template` exists as a
 * forward-declared value so the column doesn't need a migration the moment
 * template messaging is eventually approved for a later phase, but no
 * template-sending code path exists yet (see OutboundEligibilityService —
 * template-based sends are explicitly out of Phase 1 scope).
 */
enum MessageType: string
{
    case Text = 'text';
    case Template = 'template';
}
