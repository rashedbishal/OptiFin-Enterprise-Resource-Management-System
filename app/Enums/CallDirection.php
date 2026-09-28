<?php

namespace App\Enums;

/**
 * WhatsApp Outbound Calling — which way a LeadCall went. Inbound is the
 * default for every pre-existing row (see the migration); Outbound rows are
 * created only by App\Actions\Tenant\InitiateOutboundCall.
 */
enum CallDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';

    public function label(): string
    {
        return match ($this) {
            self::Inbound => 'Incoming',
            self::Outbound => 'Outgoing',
        };
    }
}
