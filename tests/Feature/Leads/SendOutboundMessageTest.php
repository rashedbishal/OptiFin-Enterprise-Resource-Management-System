<?php

use App\Enums\LeadSourceType;
use App\Enums\MessageChannel;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Jobs\SendOutboundMessage;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Message;
use App\Services\MessageSenderFactory;
use App\Services\OutboundEligibilityService;
use Illuminate\Support\Facades\Http;

function makeOutboundMessage(string $tenantId, string $leadId, Conversation $conversation, MessageChannel $channel): Message
{
    return Message::factory()->outbound()->create([
        'tenant_id' => $tenantId,
        'lead_id' => $leadId,
        'conversation_id' => $conversation->id,
        'channel' => $channel,
        'body' => 'Thanks for reaching out!',
    ]);
}

test('a successful whatsapp send marks the message sent and captures the external message id', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '1', 'access_token' => 'token'],
    ]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $conversation = Conversation::factory()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'lead_source_id' => $source->id, 'last_inbound_at' => now(),
    ]);
    $message = makeOutboundMessage($tenant->id, $lead->id, $conversation, MessageChannel::Whatsapp);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.NEW']]], 200)]);

    (new SendOutboundMessage($message))->handle(app(OutboundEligibilityService::class), app(MessageSenderFactory::class));

    $message->refresh();
    expect($message->status)->toBe(MessageStatus::Sent);
    expect($message->external_message_id)->toBe('wamid.NEW');
    expect($conversation->fresh()->last_outbound_at)->not->toBeNull();
});

test('a platform rejection marks the message failed without retrying', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '1', 'access_token' => 'token'],
    ]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $conversation = Conversation::factory()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'lead_source_id' => $source->id, 'last_inbound_at' => now(),
    ]);
    $message = makeOutboundMessage($tenant->id, $lead->id, $conversation, MessageChannel::Whatsapp);

    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Recipient not on WhatsApp']], 400)]);

    (new SendOutboundMessage($message))->handle(app(OutboundEligibilityService::class), app(MessageSenderFactory::class));

    $message->refresh();
    expect($message->status)->toBe(MessageStatus::Failed);
    expect($message->failure_reason)->toBe('Recipient not on WhatsApp');
    Http::assertSentCount(1);
});

test('a messenger send uses the RESPONSE messaging type and the me/messages endpoint', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '1', 'page_access_token' => 'page-token'],
    ]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $conversation = Conversation::factory()->messenger()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'lead_source_id' => $source->id, 'last_inbound_at' => now(),
    ]);
    $message = makeOutboundMessage($tenant->id, $lead->id, $conversation, MessageChannel::Messenger);

    Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'mid.NEW'], 200)]);

    (new SendOutboundMessage($message))->handle(app(OutboundEligibilityService::class), app(MessageSenderFactory::class));

    Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/v20.0/me/messages'
        && $request['messaging_type'] === 'RESPONSE'
        && $request['recipient']['id'] === $conversation->external_thread_id);
    expect($message->fresh()->status)->toBe(MessageStatus::Sent);
});

test('an instagram sender uses graph.instagram.com when auth_mode is instagram', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Instagram,
        'config' => ['auth_mode' => 'instagram', 'instagram_account_id' => '1', 'access_token' => 'ig-token'],
    ]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $conversation = Conversation::factory()->instagram()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'lead_source_id' => $source->id, 'last_inbound_at' => now(),
    ]);
    $message = makeOutboundMessage($tenant->id, $lead->id, $conversation, MessageChannel::Instagram);

    Http::fake(['graph.instagram.com/*' => Http::response(['message_id' => 'mid.IG'], 200)]);

    (new SendOutboundMessage($message))->handle(app(OutboundEligibilityService::class), app(MessageSenderFactory::class));

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://graph.instagram.com/'));
    expect($message->fresh()->status)->toBe(MessageStatus::Sent);
});

test('an instagram sender uses graph.facebook.com when auth_mode is facebook (page-linked)', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Instagram,
        'config' => ['auth_mode' => 'facebook', 'instagram_account_id' => '1', 'access_token' => 'ig-token'],
    ]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $conversation = Conversation::factory()->instagram()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'lead_source_id' => $source->id, 'last_inbound_at' => now(),
    ]);
    $message = makeOutboundMessage($tenant->id, $lead->id, $conversation, MessageChannel::Instagram);

    Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'mid.IG2'], 200)]);

    (new SendOutboundMessage($message))->handle(app(OutboundEligibilityService::class), app(MessageSenderFactory::class));

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://graph.facebook.com/'));
    expect($message->fresh()->status)->toBe(MessageStatus::Sent);
});

test('an ineligible conversation (expired window) is marked failed without ever calling the platform API', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '1', 'access_token' => 'token'],
    ]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $conversation = Conversation::factory()->windowExpired()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'lead_source_id' => $source->id,
    ]);
    $message = makeOutboundMessage($tenant->id, $lead->id, $conversation, MessageChannel::Whatsapp);

    Http::fake();

    (new SendOutboundMessage($message))->handle(app(OutboundEligibilityService::class), app(MessageSenderFactory::class));

    Http::assertNothingSent();
    expect($message->fresh()->status)->toBe(MessageStatus::Failed);
});

test('a template message sends successfully even with an expired messaging window', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '1', 'access_token' => 'token'],
    ]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $conversation = Conversation::factory()->windowExpired()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'lead_source_id' => $source->id,
    ]);
    $message = Message::factory()->outbound()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'conversation_id' => $conversation->id,
        'channel' => MessageChannel::Whatsapp,
        'message_type' => MessageType::Template,
        'body' => null,
        'template_name' => 'order_update',
        'template_language' => 'en_US',
        'template_variables' => ['12345'],
    ]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.TEMPLATE']]], 200)]);

    (new SendOutboundMessage($message))->handle(app(OutboundEligibilityService::class), app(MessageSenderFactory::class));

    Http::assertSent(fn ($request) => $request['type'] === 'template'
        && $request['template']['name'] === 'order_update'
        && $request['template']['language']['code'] === 'en_US'
        && $request['template']['components'][0]['parameters'][0]['text'] === '12345');
    expect($message->fresh()->status)->toBe(MessageStatus::Sent);
    expect($message->fresh()->external_message_id)->toBe('wamid.TEMPLATE');
});

test('a template message on a merged lead is still marked failed without calling the platform API', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '1', 'access_token' => 'token'],
    ]);
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'merged_into_lead_id' => $survivor->id]);
    $conversation = Conversation::factory()->windowExpired()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'lead_source_id' => $source->id,
    ]);
    $message = Message::factory()->outbound()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'conversation_id' => $conversation->id,
        'channel' => MessageChannel::Whatsapp,
        'message_type' => MessageType::Template,
        'body' => null,
        'template_name' => 'order_update',
        'template_language' => 'en_US',
        'template_variables' => [],
    ]);

    Http::fake();

    (new SendOutboundMessage($message))->handle(app(OutboundEligibilityService::class), app(MessageSenderFactory::class));

    Http::assertNothingSent();
    expect($message->fresh()->status)->toBe(MessageStatus::Failed);
});

test('a job run against an already-resolved message is a no-op', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Whatsapp, 'config' => ['phone_number_id' => '1', 'access_token' => 'token']]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $conversation = Conversation::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'lead_source_id' => $source->id, 'last_inbound_at' => now()]);
    $message = makeOutboundMessage($tenant->id, $lead->id, $conversation, MessageChannel::Whatsapp);
    $message->update(['status' => MessageStatus::Sent, 'external_message_id' => 'wamid.ALREADY']);

    Http::fake();

    (new SendOutboundMessage($message))->handle(app(OutboundEligibilityService::class), app(MessageSenderFactory::class));

    Http::assertNothingSent();
    expect(Message::count())->toBe(1);
});
