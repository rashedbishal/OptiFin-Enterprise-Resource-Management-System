<?php

namespace App\Enums;

/**
 * Feature #15 — the one genuinely new concept nothing in this module had a
 * reason to need before: whether a Message was sent by the customer
 * (inbound, the only direction that has ever existed until this feature) or
 * by this app on the tenant's behalf (outbound).
 */
enum MessageDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';
}
