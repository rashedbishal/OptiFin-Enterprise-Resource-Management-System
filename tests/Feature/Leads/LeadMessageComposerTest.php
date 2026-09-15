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

test('a manual resend of a failed message creates a brand new message row, links it back, and leaves the failed one otherwise untouched', function () {
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
    expect($failed->resend_message_id)->not->toBeNull();

    $resent = Message::where('lead_id', $lead->id)->where('id', '!=', $failed->id)->first();
    expect($resent->status)->toBe(MessageStatus::Sent);
    expect($resent->external_message_id)->toBe('wamid.RESENT');
    expect($failed->resend_message_id)->toBe($resent->id);
});

test('a second resend attempt on an already-resent failed message is rejected and creates no second resend', function () {
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

    Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.'.Str::random(10)]]], 200)]);

    $component = Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('resendMessage', $failed->id);

    expect(Message::where('lead_id', $lead->id)->count())->toBe(2);
    $failed->refresh();
    $firstResendId = $failed->resend_message_id;
    expect($firstResendId)->not->toBeNull();

    // Same request, called again — sequentially, not concurrently — against
    // the same already-resent original. No second row, no second send, and
    // the link keeps pointing at the same first resend.
    $component->call('resendMessage', $failed->id)->assertDispatched('toast-show');

    expect(Message::where('lead_id', $lead->id)->count())->toBe(2);
    $failed->refresh();
    expect($failed->resend_message_id)->toBe($firstResendId);
});

/**
 * Proves the create-resend-row + mark-original-as-resent pair is atomic —
 * mirrors KanbanBoardTest's "a failure after both writes complete rolls
 * back..." technique (M2 closure): a real (non-queued) Eloquent `created`
 * event, firing synchronously strictly AFTER Message::create() has already
 * run and BEFORE resendMessage()'s own raw resend_message_id update,
 * deliberately throws. This can only pass against genuinely atomic code —
 * without DB::transaction() wrapping both writes, the resend row created
 * just before the throw would survive as an orphan.
 */
test('a failure after the resend row is created rolls back both the resend row and the original link', function () {
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

    Message::created(function (): void {
        throw new RuntimeException('Forcing a failure after the resend row was created but before the original was linked.');
    });

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ROLLBACK']]], 200)]);

    expect(fn () => Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('resendMessage', $failed->id))
        ->toThrow(RuntimeException::class);

    // Both writes rolled back together: no orphaned resend row survives
    // even though its insert executed successfully before the listener
    // threw, and the original was never marked as resent.
    expect(Message::where('lead_id', $lead->id)->count())->toBe(1);
    $failed->refresh();
    expect($failed->resend_message_id)->toBeNull();
    expect($failed->status)->toBe(MessageStatus::Failed);
});

test('a resend that itself fails becomes its own independently resendable failed message', function () {
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

    // First resend is itself rejected by the platform; the second (of the
    // first resend, once it's Failed too) succeeds. A single stateful fake
    // — Http::fake() merges rather than replaces stub callbacks across
    // repeated calls with an array argument, so two separate array-form
    // fake() calls would both stay registered and the earlier one would
    // keep winning for every request.
    $attempt = 0;
    Http::fake(function () use (&$attempt) {
        $attempt++;

        return $attempt === 1
            ? Http::response(['error' => ['message' => 'Recipient not on WhatsApp']], 400)
            : Http::response(['messages' => [['id' => 'wamid.SECOND-RESEND']]], 200);
    });

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('resendMessage', $failed->id);

    $failed->refresh();
    $firstResend = Message::find($failed->resend_message_id);
    expect($firstResend->status)->toBe(MessageStatus::Failed);

    // The first resend, now itself Failed, is independently resendable —
    // the same uniform per-row rule, not a special-cased chain.
    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->call('resendMessage', $firstResend->id)
        ->assertDispatched('toast-show');

    expect(Message::where('lead_id', $lead->id)->count())->toBe(3);
    $firstResend->refresh();
    expect($firstResend->resend_message_id)->not->toBeNull();

    $secondResend = Message::find($firstResend->resend_message_id);
    expect($secondResend->status)->toBe(MessageStatus::Sent);
    expect($secondResend->external_message_id)->toBe('wamid.SECOND-RESEND');
});

test('the resend button disappears once the original failed message has been resent', function () {
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

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.HIDE']]], 200)]);

    $component = Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad');

    $component->assertSee(__('Resend'));

    $component->call('resendMessage', $failed->id);

    $component->assertDontSee(__('Resend'));
});
