<?php

use App\Enums\LeadSourceType;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Message;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

test('a client admin can send a within-window reply and it is queued as pending then sent', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '1', 'access_token' => 'token'],
    ]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    Conversation::factory()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'lead_source_id' => $source->id, 'last_inbound_at' => now(),
    ]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.SENT']]], 200)]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->set('newMessageBody', 'Thanks for your message!')
        ->call('sendMessage')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show');

    $message = Message::where('lead_id', $lead->id)->where('direction', MessageDirection::Outbound)->first();
    expect($message)->not->toBeNull();
    expect($message->body)->toBe('Thanks for your message!');
    expect($message->status)->toBe(MessageStatus::Sent);
    expect($message->sent_by)->toBe($clientAdmin->id);
});

test('the composer is not shown at all for a lead whose source is not a conversational channel', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::WebsiteForm]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    $channel = Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->instance()
        ->messagingChannel();

    expect($channel)->toBeNull();
});

test('sending is blocked when the messaging window has expired', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '1', 'access_token' => 'token'],
    ]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    Conversation::factory()->windowExpired()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'lead_source_id' => $source->id,
    ]);

    Http::fake();

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->set('newMessageBody', 'Hello')
        ->call('sendMessage')
        ->assertDispatched('toast-show');

    Http::assertNothingSent();
    expect(Message::where('lead_id', $lead->id)->exists())->toBeFalse();
});

test('a client user without update permission cannot send a message', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view']);
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '1', 'access_token' => 'token'],
    ]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'assigned_to' => $rep->id]);
    Conversation::factory()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'lead_source_id' => $source->id, 'last_inbound_at' => now(),
    ]);

    Http::fake();

    Livewire::actingAs($rep)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->set('newMessageBody', 'Hello')
        ->call('sendMessage');

    Http::assertNothingSent();
    expect(Message::where('lead_id', $lead->id)->exists())->toBeFalse();
});

test('resubmitting the exact same message body while the first send is still in flight is prevented', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '1', 'access_token' => 'token'],
    ]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $conversation = Conversation::factory()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'lead_source_id' => $source->id, 'last_inbound_at' => now(),
    ]);

    // Simulate a send already in flight by holding the exact same lock the
    // component itself would acquire.
    $lockKey = 'outbound-message:'.$conversation->id.':'.md5('Duplicate text');
    $lock = Cache::lock($lockKey, 10);
    $lock->get();

    Http::fake();

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->set('newMessageBody', 'Duplicate text')
        ->call('sendMessage')
        ->assertDispatched('toast-show');

    Http::assertNothingSent();
    expect(Message::where('lead_id', $lead->id)->count())->toBe(0);

    $lock->release();
});

test('a rep is blocked from sending once the per-user rate limit is exceeded', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '1', 'access_token' => 'token'],
    ]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    Conversation::factory()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'lead_source_id' => $source->id, 'last_inbound_at' => now(),
    ]);

    // A unique id per call — a real Meta response never repeats a wamid,
    // and this test's own unique(tenant_id, channel, external_message_id)
    // constraint would otherwise collide on the second send.
    Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.'.Str::random(10)]]], 200)]);

    $component = Livewire::actingAs($clientAdmin)->test(Show::class, ['lead' => $lead])->call('completeInitialLoad');

    for ($i = 0; $i < 30; $i++) {
        $component->set('newMessageBody', "Message {$i}")->call('sendMessage');
    }

    expect(Message::where('lead_id', $lead->id)->count())->toBe(30);

    $component->set('newMessageBody', 'One too many')->call('sendMessage')->assertDispatched('toast-show');

    expect(Message::where('lead_id', $lead->id)->count())->toBe(30);
});

test('a manual resend of a failed message creates a brand new message row and leaves the failed one untouched', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '1', 'access_token' => 'token'],
    ]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $conversation = Conversation::factory()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'lead_source_id' => $source->id, 'last_inbound_at' => now(),
    ]);
    $failed = Message::factory()->outbound()->withStatus(MessageStatus::Failed)->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversation->id,
        'lead_id' => $lead->id,
        'channel' => MessageChannel::Whatsapp,
        'body' => 'Original text',
        'failure_reason' => 'Recipient not on WhatsApp',
    ]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.RESENT']]], 200)]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('resendMessage', $failed->id)
        ->assertDispatched('toast-show');

    expect(Message::where('lead_id', $lead->id)->count())->toBe(2);

    $failed->refresh();
    expect($failed->status)->toBe(MessageStatus::Failed);
    expect($failed->failure_reason)->toBe('Recipient not on WhatsApp');

    $resent = Message::where('lead_id', $lead->id)->where('id', '!=', $failed->id)->first();
    expect($resent->status)->toBe(MessageStatus::Sent);
    expect($resent->external_message_id)->toBe('wamid.RESENT');
});
