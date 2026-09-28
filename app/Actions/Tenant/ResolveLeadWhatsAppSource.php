<?php

namespace App\Actions\Tenant;

use App\Enums\LeadSourceType;
use App\Models\Lead;
use App\Models\LeadSource;

/**
 * WhatsApp Outbound Calling — a Lead's own WhatsApp presence: an existing
 * WhatsApp conversation's source first (a Lead may have arrived through a
 * different channel but already exchanged WhatsApp messages), else the
 * Lead's own source when IT is a WhatsApp one. Never anything the browser
 * supplies. Shared by InitiateOutboundCall and the Lead detail page's
 * permission-check/request UI, both of which need exactly this same
 * resolution — kept as one small action rather than duplicated.
 */
class ResolveLeadWhatsAppSource
{
    public function handle(Lead $lead): ?LeadSource
    {
        $viaConversation = $lead->conversations()
            ->whereHas('leadSource', fn ($query) => $query->where('type', LeadSourceType::Whatsapp->value))
            ->with('leadSource')
            ->latest('last_inbound_at')
            ->first()
            ?->leadSource;

        if ($viaConversation instanceof LeadSource) {
            return $viaConversation;
        }

        return $lead->source?->type === LeadSourceType::Whatsapp ? $lead->source : null;
    }
}
