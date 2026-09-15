<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Inbound Webhook Logical Message Cap
    |--------------------------------------------------------------------------
    |
    | The maximum number of logical inbound messages (Messenger/Instagram
    | messaging[] items, WhatsApp messages[] items — never delivery/read
    | receipts or WhatsApp statuses[]) LeadWebhookController will process
    | from a single request, summed across every entry[] in the payload.
    | Checked before any per-message DB/API work begins. A request over
    | this limit is rejected in full (no partial processing) but still
    | acknowledged with the controller's existing 200 response, matching
    | its established every-failure-mode-returns-200 convention.
    |
    | No repository or provider evidence supports a specific number — 100
    | mirrors this app's own existing MAX_BULK_SELECTION precedent
    | (App\Livewire\Tenant\Leads\Index) for "a reasonable single-request
    | work ceiling," not a documented platform limit.
    |
    */

    'max_inbound_messages_per_request' => env('WEBHOOK_MAX_INBOUND_MESSAGES_PER_REQUEST', 100),

];
