<?php

use App\Enums\LeadSourceType;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Message;

test('a facebook messenger message creates a conversation and a normalized inbound message alongside the legacy source_data write', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '123', 'verify_token' => 'secret-token'],
    ]);

    $this->postJson(route('api.webhook.facebook', $tenant->tenant_code), [
        'entry' => [['messaging' => [[
            'sender' => ['id' => 'psid-1'],
            'message' => ['text' => 'Hello there', 'mid' => 'mid.123'],
        ]]]],
    ])->assertOk();

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();

    // Legacy write is completely unaffected.
    expect($lead->message)->toBe('Hello there');
    expect($lead->source_data['facebook_user_id'])->toBe('psid-1');
    expect($lead->source_data['messages'])->toHaveCount(1);

    $conversation = Conversation::where('lead_id', $lead->id)->first();
    expect($conversation)->not->toBeNull();
    expect($conversation->channel)->toBe(MessageChannel::Messenger);
    expect($conversation->lead_source_id)->toBe($source->id);
    expect($conversation->external_thread_id)->toBe('psid-1');
    expect($conversation->last_inbound_at)->not->toBeNull();

    $message = Message::where('conversation_id', $conversation->id)->first();
    expect($message->direction)->toBe(MessageDirection::Inbound);
    expect($message->body)->toBe('Hello there');
    expect($message->external_message_id)->toBe('mid.123');
    expect($message->lead_id)->toBe($lead->id);
    expect($message->tenant_id)->toBe($tenant->id);
});

test('a follow-up messenger message reuses the same conversation and appends a second message row', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '123', 'verify_token' => 'secret-token'],
    ]);

    $payload = [
        'entry' => [['messaging' => [[
            'sender' => ['id' => 'psid-1'],
            'message' => ['text' => 'First'],
        ]]]],
    ];

    $this->postJson(route('api.webhook.facebook', $tenant->tenant_code), $payload)->assertOk();

    $payload['entry'][0]['messaging'][0]['message']['text'] = 'Second';
    $this->postJson(route('api.webhook.facebook', $tenant->tenant_code), $payload)->assertOk();

    expect(Conversation::count())->toBe(1);
    expect(Message::count())->toBe(2);
});

test('an instagram dm creates a conversation and normalized message using the IGSID', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Instagram,
        'config' => ['auth_mode' => 'facebook', 'instagram_account_id' => '999', 'verify_token' => 'secret-token'],
    ]);

    $this->postJson(route('api.webhook.instagram', $tenant->tenant_code), [
        'entry' => [['messaging' => [[
            'sender' => ['id' => 'igsid-1'],
            'message' => ['text' => 'Hi from IG'],
        ]]]],
    ])->assertOk();

    $conversation = Conversation::first();
    expect($conversation->channel)->toBe(MessageChannel::Instagram);
    expect($conversation->external_thread_id)->toBe('igsid-1');
    expect(Message::first()->body)->toBe('Hi from IG');
});

test('a whatsapp message creates a conversation and normalized message keyed by phone number', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '111', 'verify_token' => 'secret-token'],
    ]);

    $this->postJson(route('api.webhook.whatsapp', $tenant->tenant_code), [
        'entry' => [['changes' => [['value' => [
            'messages' => [['from' => '15551234567', 'id' => 'wamid.ABC', 'type' => 'text', 'text' => ['body' => 'Hello WA']]],
        ]]]]],
    ])->assertOk();

    $conversation = Conversation::first();
    expect($conversation->channel)->toBe(MessageChannel::Whatsapp);
    expect($conversation->external_thread_id)->toBe('15551234567');

    $message = Message::first();
    expect($message->body)->toBe('Hello WA');
    expect($message->external_message_id)->toBe('wamid.ABC');
});

test('Lead::messageTimeline merges legacy source_data entries with normalized message rows in chronological order', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '123', 'verify_token' => 'secret-token'],
    ]);

    $this->postJson(route('api.webhook.facebook', $tenant->tenant_code), [
        'entry' => [['messaging' => [[
            'sender' => ['id' => 'psid-1'],
            'message' => ['text' => 'Hello there'],
        ]]]],
    ])->assertOk();

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    $timeline = $lead->messageTimeline();

    expect($timeline)->toHaveCount(1);
    expect($timeline[0]['text'])->toBe('Hello there');
    expect($timeline[0]['direction'])->toBe(MessageDirection::Inbound);
    expect($timeline[0]['status'])->toBeNull();
});

test('a lead with only legacy source_data messages and no normalized rows still renders its timeline exactly as before', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookMessenger]);
    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'source_id' => $source->id,
        'source_data' => ['facebook_user_id' => 'psid-9', 'messages' => [['message' => ['text' => 'Pre-Feature-15 message']]]],
    ]);

    $timeline = $lead->messageTimeline();

    expect($timeline)->toHaveCount(1);
    expect($timeline[0]['text'])->toBe('Pre-Feature-15 message');
});

test('pre-existing legacy history is NOT hidden once a lead receives its first post-cutover dual-written message', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '123', 'verify_token' => 'secret-token'],
    ]);
    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'source_id' => $source->id,
        'source_data' => [
            'facebook_user_id' => 'psid-1',
            'messages' => [
                ['message' => ['text' => 'Old message 1'], 'timestamp' => now()->subDays(5)->getTimestampMs()],
                ['message' => ['text' => 'Old message 2'], 'timestamp' => now()->subDays(4)->getTimestampMs()],
            ],
        ],
    ]);

    expect($lead->messageTimeline())->toHaveCount(2);

    $this->postJson(route('api.webhook.facebook', $tenant->tenant_code), [
        'entry' => [['messaging' => [[
            'sender' => ['id' => 'psid-1'],
            'message' => ['text' => 'New message after cutover'],
        ]]]],
    ])->assertOk();

    $lead->refresh();
    $timeline = $lead->messageTimeline();

    expect($timeline)->toHaveCount(3);
    expect(array_column($timeline, 'text'))->toBe(['Old message 1', 'Old message 2', 'New message after cutover']);
});

test('an outbound reply appears in the timeline alongside pre-existing legacy inbound history', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookMessenger]);
    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'source_id' => $source->id,
        'source_data' => ['facebook_user_id' => 'psid-1', 'messages' => [['message' => ['text' => 'Legacy inbound']]]],
    ]);
    $conversation = Conversation::factory()->messenger()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'lead_source_id' => $source->id, 'external_thread_id' => 'psid-1',
    ]);
    Message::factory()->outbound()->withStatus(MessageStatus::Sent)->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversation->id,
        'lead_id' => $lead->id,
        'channel' => MessageChannel::Messenger,
        'body' => 'Our reply',
    ]);

    $timeline = $lead->messageTimeline();

    expect($timeline)->toHaveCount(2);
    expect(array_column($timeline, 'text'))->toBe(['Legacy inbound', 'Our reply']);
    expect($timeline[1]['direction'])->toBe(MessageDirection::Outbound);
});
