<?php

use App\Enums\LeadSourceType;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Jobs\ReconcileOutboundMessageStatus;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Message;

test('a whatsapp status webhook correlates by wamid and advances the message status', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Whatsapp]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $conversation = Conversation::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'lead_source_id' => $source->id]);
    $message = Message::factory()->outbound()->withStatus(MessageStatus::Sent)->withExternalId('wamid.ABC')->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversation->id,
        'lead_id' => $lead->id,
        'channel' => MessageChannel::Whatsapp,
    ]);

    $this->postJson(route('api.webhook.whatsapp', $tenant->tenant_code), [
        'entry' => [['changes' => [['value' => [
            'statuses' => [['id' => 'wamid.ABC', 'status' => 'delivered', 'timestamp' => (string) now()->timestamp]],
        ]]]]],
    ])->assertOk();

    expect($message->fresh()->status)->toBe(MessageStatus::Delivered);
});

test('a whatsapp failed status records the failure reason', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Whatsapp]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $conversation = Conversation::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'lead_source_id' => $source->id]);
    $message = Message::factory()->outbound()->withStatus(MessageStatus::Sent)->withExternalId('wamid.FAIL')->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversation->id,
        'lead_id' => $lead->id,
        'channel' => MessageChannel::Whatsapp,
    ]);

    $this->postJson(route('api.webhook.whatsapp', $tenant->tenant_code), [
        'entry' => [['changes' => [['value' => [
            'statuses' => [['id' => 'wamid.FAIL', 'status' => 'failed', 'errors' => [['title' => 'Recipient number not on WhatsApp']]]],
        ]]]]],
    ])->assertOk();

    $message->refresh();
    expect($message->status)->toBe(MessageStatus::Failed);
    expect($message->failure_reason)->toBe('Recipient number not on WhatsApp');
});

test('a messenger delivery webhook correlates by watermark across all outbound messages up to that time', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookMessenger, 'config' => ['page_id' => '1', 'verify_token' => 't']]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $conversation = Conversation::factory()->messenger()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'lead_source_id' => $source->id,
        'external_thread_id' => 'psid-1',
    ]);
    $message = Message::factory()->outbound()->withStatus(MessageStatus::Sent)->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversation->id,
        'lead_id' => $lead->id,
        'channel' => MessageChannel::Messenger,
        'created_at' => now()->subMinute(),
    ]);

    $this->postJson(route('api.webhook.facebook', $tenant->tenant_code), [
        'entry' => [['messaging' => [[
            'sender' => ['id' => 'page-1'],
            'recipient' => ['id' => 'psid-1'],
            'delivery' => ['watermark' => now()->timestamp * 1000],
        ]]]],
    ])->assertOk();

    expect($message->fresh()->status)->toBe(MessageStatus::Delivered);
});

test('an out-of-order status event never downgrades an already-read message back to delivered', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Whatsapp]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $conversation = Conversation::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'lead_source_id' => $source->id]);
    $message = Message::factory()->outbound()->withStatus(MessageStatus::Read)->withExternalId('wamid.READ')->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversation->id,
        'lead_id' => $lead->id,
        'channel' => MessageChannel::Whatsapp,
    ]);

    ReconcileOutboundMessageStatus::dispatchSync(
        tenantId: $tenant->id,
        channel: MessageChannel::Whatsapp,
        status: MessageStatus::Delivered,
        externalMessageId: 'wamid.READ',
    );

    expect($message->fresh()->status)->toBe(MessageStatus::Read);
});

test('a duplicate status webhook delivery is idempotent', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Whatsapp]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $conversation = Conversation::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'lead_source_id' => $source->id]);
    $message = Message::factory()->outbound()->withStatus(MessageStatus::Sent)->withExternalId('wamid.DUP')->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversation->id,
        'lead_id' => $lead->id,
        'channel' => MessageChannel::Whatsapp,
    ]);

    $dispatch = fn () => ReconcileOutboundMessageStatus::dispatchSync(
        tenantId: $tenant->id,
        channel: MessageChannel::Whatsapp,
        status: MessageStatus::Delivered,
        externalMessageId: 'wamid.DUP',
    );

    $dispatch();
    $dispatch();

    expect($message->fresh()->status)->toBe(MessageStatus::Delivered);
});

test('a status event for an unknown tenant/message combination never touches another tenants message', function () {
    ['tenant' => $tenantA] = tenantWithLeadManagement();
    ['tenant' => $tenantB] = tenantWithLeadManagement();
    $sourceB = LeadSource::factory()->create(['tenant_id' => $tenantB->id, 'type' => LeadSourceType::Whatsapp]);
    $leadB = Lead::factory()->create(['tenant_id' => $tenantB->id, 'source_id' => $sourceB->id]);
    $conversationB = Conversation::factory()->create(['tenant_id' => $tenantB->id, 'lead_id' => $leadB->id, 'lead_source_id' => $sourceB->id]);
    $messageB = Message::factory()->outbound()->withStatus(MessageStatus::Sent)->withExternalId('wamid.SHARED')->create([
        'tenant_id' => $tenantB->id,
        'conversation_id' => $conversationB->id,
        'lead_id' => $leadB->id,
        'channel' => MessageChannel::Whatsapp,
    ]);

    // Same external_message_id, but dispatched under tenant A — the lookup
    // is scoped by tenant_id, so no row matches (indistinguishable, by
    // design, from a genuine not-yet-arrived race — see the job's own
    // docblock), which throws to trigger a retry rather than silently
    // succeeding. The important assertion is what it does NOT do: touch
    // tenant B's row.
    expect(fn () => ReconcileOutboundMessageStatus::dispatchSync(
        tenantId: $tenantA->id,
        channel: MessageChannel::Whatsapp,
        status: MessageStatus::Delivered,
        externalMessageId: 'wamid.SHARED',
    ))->toThrow(RuntimeException::class);

    expect($messageB->fresh()->status)->toBe(MessageStatus::Sent);
});

test('an inbound message is never touched by outbound status reconciliation', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Whatsapp]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $conversation = Conversation::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'lead_source_id' => $source->id]);
    $inbound = Message::factory()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversation->id,
        'lead_id' => $lead->id,
        'channel' => MessageChannel::Whatsapp,
        'direction' => MessageDirection::Inbound,
        'external_message_id' => 'wamid.INBOUND',
    ]);

    ReconcileOutboundMessageStatus::dispatchSync(
        tenantId: $tenant->id,
        channel: MessageChannel::Whatsapp,
        status: MessageStatus::Delivered,
        externalMessageId: 'wamid.INBOUND',
    );

    expect($inbound->fresh()->status)->toBeNull();
});
