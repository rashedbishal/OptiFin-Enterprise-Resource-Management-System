<?php

namespace App\Enums;

/**
 * Feature #15 Phase 1 supports free-form text only — `Template` exists as a
 * forward-declared value so the column doesn't need a migration the moment
 * template messaging is eventually approved for a later phase, but no
 * template-sending code path exists yet (see OutboundEligibilityService —
 * template-based sends are explicitly out of Phase 1 scope).
 *
 * `Image`/`Document` are outbound-only media messages (one file each, the
 * file's own columns live on the Message row); an inbound file is still a
 * LeadAttachment, never a Message of these types.
 */
enum MessageType: string
{
    case Text = 'text';
    case Template = 'template';
    case Image = 'image';
    case Document = 'document';
}
