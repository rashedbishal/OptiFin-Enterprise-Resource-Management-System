<?php

use App\Enums\LeadSourceType;
use App\Enums\TenantStatus;
use App\Events\LeadReceived;
use App\Events\LeadUpdated;
use App\Jobs\DownloadLeadAttachment;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Message;
use App\Services\LeadMergeService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\Lock as LockContract;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * REL-1 — a real Cache::lock()->block() timeout takes the full configured
 * blockSeconds (5s in production) to actually occur, which would make a
 * committed regression test that waits for one both slow and, at CI scale,
 * a real cost — per the approved architecture brief, these tests instead
 * mock exactly the one Cache::lock() call for one specific sender/phone
 * identifier to throw the SAME real Illuminate\Contracts\Cache\
 * LockTimeoutException a genuine timeout produces, immediately — proving
 * the controller's catch-and-continue logic against the real exception
 * class, without waiting for a real 5-8s timeout. Every other lock key in
 * these tests delegates to the real, unmocked cache store (captured via
 * Cache::getFacadeRoot() before the mock is installed), so unrelated
 * items are processed through completely real locking, exactly as
 * production does. The one genuine, real (not mocked) lock-timeout
 * reproduction — using two real, separate processes, per the
 * investigation's own methodology — was performed once, manually, outside
 * this committed suite; see the REL-1 Implementation Report.
 *
 * $realCache MUST be captured via Cache::getFacadeRoot() BEFORE
 * Cache::shouldReceive() swaps the facade root to a mock — fetching it
 * from inside this function (after the swap) would return the mock
 * itself, not the real store.
 *
 * @return LockContract
 */
function realOrThrowingLock(mixed $realCache, string $name, int $seconds, ?string $owner, string $blockedNeedle): mixed
{
    if (str_contains($name, $blockedNeedle)) {
        $lock = Mockery::mock(LockContract::class);
        $lock->shouldReceive('block')->once()->andThrow(new LockTimeoutException);

        return $lock;
    }

    return $realCache->lock($name, $seconds, $owner);
}

test('a facebook webhook verify challenge succeeds with the correct token', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '123', 'verify_token' => 'secret-token'],
    ]);

    $this->get(route('api.webhook.facebook.verify', $tenant->tenant_code).'?hub_mode=subscribe&hub_verify_token=secret-token&hub_challenge=42')
        ->assertOk()
        ->assertSee('42');
});

test('a facebook webhook verify challenge fails with the wrong token', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '123', 'verify_token' => 'secret-token'],
    ]);

    $this->get(route('api.webhook.facebook.verify', $tenant->tenant_code).'?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=42')
        ->assertForbidden();
});

test('a facebook message creates a lead, and a follow-up message is appended instead of duplicated', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '123', 'verify_token' => 'secret-token'],
    ]);

    $payload = [
        'entry' => [[
            'messaging' => [[
                'sender' => ['id' => 'psid-1'],
                'message' => ['text' => 'Hello there'],
            ]],
        ]],
    ];

    Event::fake([LeadReceived::class, LeadUpdated::class]);

    $this->postJson(route('api.webhook.facebook', $tenant->tenant_code), $payload)->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(1);
    Event::assertDispatched(LeadReceived::class);
    Event::assertNotDispatched(LeadUpdated::class);

    $payload['entry'][0]['messaging'][0]['message']['text'] = 'Second message';
    $this->postJson(route('api.webhook.facebook', $tenant->tenant_code), $payload)->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(1);
    Event::assertDispatched(LeadReceived::class, 1);
    Event::assertDispatched(LeadUpdated::class, 1);

    $lead = Lead::where('tenant_id', $tenant->id)->first();
    expect($lead->message)->toContain('Hello there')->toContain('Second message');
});

test('a facebook message from a sender whose lead has since been merged continues onto the survivor instead of creating a new lead', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '123', 'verify_token' => 'secret-token'],
    ]);

    $payload = [
        'entry' => [[
            'messaging' => [[
                'sender' => ['id' => 'psid-1'],
                'message' => ['text' => 'Hello there'],
            ]],
        ]],
    ];

    $this->postJson(route('api.webhook.facebook', $tenant->tenant_code), $payload)->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(1);
    $leadA = Lead::where('tenant_id', $tenant->id)->firstOrFail();

    $leadB = Lead::factory()->create(['tenant_id' => $tenant->id]);
    app(LeadMergeService::class)->merge($leadB, $leadA, $clientAdmin, []);

    $leadAMessageAfterMerge = $leadA->fresh()->message;
    $leadASourceDataAfterMerge = $leadA->fresh()->source_data;

    // The merge already reparented the Conversation this sender's first
    // message created onto the survivor (see LeadMergeTest.php).
    $conversation = Conversation::where('tenant_id', $tenant->id)->where('external_thread_id', 'psid-1')->firstOrFail();
    expect($conversation->lead_id)->toBe($leadB->id);

    $payload['entry'][0]['messaging'][0]['message']['text'] = 'Second message';
    $this->postJson(route('api.webhook.facebook', $tenant->tenant_code), $payload)->assertOk();

    // No spurious new lead — the second message continues onto the
    // survivor via the already-reparented Conversation.
    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(2);

    // No duplicate Conversation was created for the same thread.
    expect(Conversation::where('tenant_id', $tenant->id)->where('external_thread_id', 'psid-1')->count())->toBe(1);

    $conversation->refresh();
    expect($conversation->lead_id)->toBe($leadB->id);

    $newMessage = Message::where('conversation_id', $conversation->id)->latest('created_at')->firstOrFail();
    expect($newMessage->lead_id)->toBe($conversation->lead_id);
    expect($newMessage->body)->toBe('Second message');

    // The loser's own legacy fields are completely untouched by the
    // follow-up — it was never passed to appendFacebookMessage().
    expect($leadA->fresh()->message)->toBe($leadAMessageAfterMerge);
    expect($leadA->fresh()->source_data)->toBe($leadASourceDataAfterMerge);
});

test('a facebook messenger webhook with no active messenger source still acknowledges with 200', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $payload = [
        'entry' => [[
            'messaging' => [[
                'sender' => ['id' => 'psid-1'],
                'message' => ['text' => 'Hello there'],
            ]],
        ]],
    ];

    $this->postJson(route('api.webhook.facebook', $tenant->tenant_code), $payload)->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('a facebook messenger webhook for an unknown tenant code still acknowledges with 200', function () {
    $payload = [
        'entry' => [[
            'messaging' => [[
                'sender' => ['id' => 'psid-1'],
                'message' => ['text' => 'Hello there'],
            ]],
        ]],
    ];

    $this->postJson(route('api.webhook.facebook', 'no-such-tenant'), $payload)->assertOk();
});

test('an instagram webhook verify challenge succeeds with the correct token', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Instagram,
        'config' => ['instagram_account_id' => '456', 'verify_token' => 'secret-token'],
    ]);

    $this->get(route('api.webhook.instagram.verify', $tenant->tenant_code).'?hub_mode=subscribe&hub_verify_token=secret-token&hub_challenge=42')
        ->assertOk()
        ->assertSee('42');
});

test('an instagram webhook verify challenge fails with the wrong token', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Instagram,
        'config' => ['instagram_account_id' => '456', 'verify_token' => 'secret-token'],
    ]);

    $this->get(route('api.webhook.instagram.verify', $tenant->tenant_code).'?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=42')
        ->assertForbidden();
});

test('an instagram dm creates a lead, and a follow-up message is appended instead of duplicated', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Instagram,
        'config' => ['instagram_account_id' => '456', 'verify_token' => 'secret-token'],
    ]);

    $payload = [
        'entry' => [[
            'messaging' => [[
                'sender' => ['id' => 'igsid-1'],
                'message' => ['text' => 'Hi, is this available?'],
            ]],
        ]],
    ];

    Event::fake([LeadReceived::class, LeadUpdated::class]);

    $this->postJson(route('api.webhook.instagram', $tenant->tenant_code), $payload)->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(1);
    Event::assertDispatched(LeadReceived::class);
    Event::assertNotDispatched(LeadUpdated::class);

    $payload['entry'][0]['messaging'][0]['message']['text'] = 'Still interested';
    $this->postJson(route('api.webhook.instagram', $tenant->tenant_code), $payload)->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(1);
    Event::assertDispatched(LeadReceived::class, 1);
    Event::assertDispatched(LeadUpdated::class, 1);

    $lead = Lead::where('tenant_id', $tenant->id)->first();
    expect($lead->message)->toContain('Hi, is this available?')->toContain('Still interested');
});

test('an instagram dm from a sender whose lead has since been merged continues onto the survivor instead of creating a new lead', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Instagram,
        'config' => ['instagram_account_id' => '456', 'verify_token' => 'secret-token'],
    ]);

    $payload = [
        'entry' => [[
            'messaging' => [[
                'sender' => ['id' => 'igsid-1'],
                'message' => ['text' => 'Hi, is this available?'],
            ]],
        ]],
    ];

    $this->postJson(route('api.webhook.instagram', $tenant->tenant_code), $payload)->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(1);
    $leadA = Lead::where('tenant_id', $tenant->id)->firstOrFail();

    $leadB = Lead::factory()->create(['tenant_id' => $tenant->id]);
    app(LeadMergeService::class)->merge($leadB, $leadA, $clientAdmin, []);

    $leadAMessageAfterMerge = $leadA->fresh()->message;
    $leadASourceDataAfterMerge = $leadA->fresh()->source_data;

    // The merge already reparented the Conversation this sender's first
    // message created onto the survivor (see LeadMergeTest.php).
    $conversation = Conversation::where('tenant_id', $tenant->id)->where('external_thread_id', 'igsid-1')->firstOrFail();
    expect($conversation->lead_id)->toBe($leadB->id);

    $payload['entry'][0]['messaging'][0]['message']['text'] = 'Still interested';
    $this->postJson(route('api.webhook.instagram', $tenant->tenant_code), $payload)->assertOk();

    // No spurious new lead — the second message continues onto the
    // survivor via the already-reparented Conversation.
    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(2);

    // No duplicate Conversation was created for the same thread.
    expect(Conversation::where('tenant_id', $tenant->id)->where('external_thread_id', 'igsid-1')->count())->toBe(1);

    $conversation->refresh();
    expect($conversation->lead_id)->toBe($leadB->id);

    $newMessage = Message::where('conversation_id', $conversation->id)->latest('created_at')->firstOrFail();
    expect($newMessage->lead_id)->toBe($conversation->lead_id);
    expect($newMessage->body)->toBe('Still interested');

    // The loser's own legacy fields are completely untouched by the
    // follow-up — it was never passed to appendInstagramMessage().
    expect($leadA->fresh()->message)->toBe($leadAMessageAfterMerge);
    expect($leadA->fresh()->source_data)->toBe($leadASourceDataAfterMerge);
});

test('an instagram webhook with no active instagram source still acknowledges with 200', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $payload = [
        'entry' => [[
            'messaging' => [[
                'sender' => ['id' => 'igsid-1'],
                'message' => ['text' => 'Hi, is this available?'],
            ]],
        ]],
    ];

    $this->postJson(route('api.webhook.instagram', $tenant->tenant_code), $payload)->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('an instagram webhook for an unknown tenant code still acknowledges with 200', function () {
    $payload = [
        'entry' => [[
            'messaging' => [[
                'sender' => ['id' => 'igsid-1'],
                'message' => ['text' => 'Hi, is this available?'],
            ]],
        ]],
    ];

    $this->postJson(route('api.webhook.instagram', 'no-such-tenant'), $payload)->assertOk();
});

test('a whatsapp message creates a lead', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '123', 'access_token' => 'x', 'verify_token' => 'y'],
    ]);

    $payload = [
        'entry' => [[
            'changes' => [[
                'value' => [
                    'messages' => [[
                        'from' => '8801700000000',
                        'text' => ['body' => 'Hi, interested in your product'],
                    ]],
                ],
            ]],
        ]],
    ];

    $this->postJson(route('api.webhook.whatsapp', $tenant->tenant_code), $payload)->assertOk();

    $lead = Lead::where('tenant_id', $tenant->id)->first();
    expect($lead)->not->toBeNull();
    expect($lead->phone)->toBe('8801700000000');
    expect($lead->name)->toBe('WhatsApp User 8801700000000');
});

test('the whatsapp log channel rotates daily with bounded retention instead of growing forever', function () {
    expect(config('logging.channels.whatsapp.driver'))->toBe('daily');
    expect(config('logging.channels.whatsapp.max_files'))->not->toBeNull();
});

test('a whatsapp webhook logs only the approved minimal diagnostic fields, never the raw payload, headers, body text, or full phone number', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '123', 'access_token' => 'x', 'verify_token' => 'y'],
    ]);

    Log::shouldReceive('channel')->with('whatsapp')->andReturnSelf();
    Log::shouldReceive('info')
        ->once()
        ->withArgs(function (string $message, array $context) use ($tenant): bool {
            expect($message)->toBe('WA INCOMING');
            expect($context['tenant_code'])->toBe($tenant->tenant_code);
            expect($context['entry_count'])->toBe(1);
            expect($context['messages'])->toHaveCount(1);

            $loggedMessage = $context['messages'][0];
            expect($loggedMessage['message_type'])->toBe('text');
            expect($loggedMessage['has_body'])->toBeTrue();
            expect($loggedMessage['wamid'])->toBe('wamid.ABC');
            expect($loggedMessage['timestamp'])->toBe('1700000000');
            expect($loggedMessage['phone_last4'])->toBe('****4567');

            $encoded = json_encode($context);
            expect($encoded)->not->toContain('Super secret message content');
            expect($encoded)->not->toContain('15551234567');
            expect($encoded)->not->toContain('headers');

            return true;
        });

    $this->postJson(route('api.webhook.whatsapp', $tenant->tenant_code), [
        'entry' => [['changes' => [['value' => [
            'messages' => [[
                'from' => '15551234567',
                'id' => 'wamid.ABC',
                'type' => 'text',
                'timestamp' => '1700000000',
                'text' => ['body' => 'Super secret message content'],
            ]],
        ]]]]],
    ])->assertOk();

    // Message processing itself is unaffected by the logging change.
    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    expect($lead->phone)->toBe('15551234567');
});

test('a whatsapp message with a contacts profile name uses that name instead of the generic fallback', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '123', 'access_token' => 'x', 'verify_token' => 'y'],
    ]);

    $payload = [
        'entry' => [[
            'changes' => [[
                'value' => [
                    'contacts' => [[
                        'wa_id' => '8801700000000',
                        'profile' => ['name' => 'Rahim Uddin'],
                    ]],
                    'messages' => [[
                        'from' => '8801700000000',
                        'text' => ['body' => 'Hi, interested in your product'],
                    ]],
                ],
            ]],
        ]],
    ];

    $this->postJson(route('api.webhook.whatsapp', $tenant->tenant_code), $payload)->assertOk();

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    expect($lead->name)->toBe('Rahim Uddin');
});

test('a whatsapp message creates a lead, and a follow-up message from the same number is appended instead of duplicated', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '123', 'access_token' => 'x', 'verify_token' => 'y'],
    ]);

    $payload = [
        'entry' => [[
            'changes' => [[
                'value' => [
                    'messages' => [[
                        'from' => '8801700000000',
                        'text' => ['body' => 'Hi, interested in your product'],
                    ]],
                ],
            ]],
        ]],
    ];

    Event::fake([LeadReceived::class, LeadUpdated::class]);

    $this->postJson(route('api.webhook.whatsapp', $tenant->tenant_code), $payload)->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(1);
    Event::assertDispatched(LeadReceived::class);
    Event::assertNotDispatched(LeadUpdated::class);

    $payload['entry'][0]['changes'][0]['value']['messages'][0]['text']['body'] = 'What is the price?';
    $this->postJson(route('api.webhook.whatsapp', $tenant->tenant_code), $payload)->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(1);
    Event::assertDispatched(LeadReceived::class, 1);
    Event::assertDispatched(LeadUpdated::class, 1);

    $lead = Lead::where('tenant_id', $tenant->id)->first();
    expect($lead->message)->toContain('Hi, interested in your product')->toContain('What is the price?');
});

test('a whatsapp message from a phone number whose lead has since been merged continues onto the survivor instead of creating a new lead', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '123', 'access_token' => 'x', 'verify_token' => 'y'],
    ]);

    $payload = [
        'entry' => [[
            'changes' => [[
                'value' => [
                    'messages' => [[
                        'from' => '8801700000000',
                        'text' => ['body' => 'Hi, interested in your product'],
                    ]],
                ],
            ]],
        ]],
    ];

    $this->postJson(route('api.webhook.whatsapp', $tenant->tenant_code), $payload)->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(1);
    $leadA = Lead::where('tenant_id', $tenant->id)->firstOrFail();

    $leadB = Lead::factory()->create(['tenant_id' => $tenant->id]);
    app(LeadMergeService::class)->merge($leadB, $leadA, $clientAdmin, []);

    $leadAMessageAfterMerge = $leadA->fresh()->message;
    $leadASourceDataAfterMerge = $leadA->fresh()->source_data;

    // The merge already reparented the Conversation this sender's first
    // message created onto the survivor (see LeadMergeTest.php).
    $conversation = Conversation::where('tenant_id', $tenant->id)->where('external_thread_id', '8801700000000')->firstOrFail();
    expect($conversation->lead_id)->toBe($leadB->id);

    $payload['entry'][0]['changes'][0]['value']['messages'][0]['text']['body'] = 'What is the price?';
    $this->postJson(route('api.webhook.whatsapp', $tenant->tenant_code), $payload)->assertOk();

    // No spurious new lead — the second message continues onto the
    // survivor via the already-reparented Conversation.
    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(2);

    // No duplicate Conversation was created for the same thread.
    expect(Conversation::where('tenant_id', $tenant->id)->where('external_thread_id', '8801700000000')->count())->toBe(1);

    $conversation->refresh();
    expect($conversation->lead_id)->toBe($leadB->id);

    $newMessage = Message::where('conversation_id', $conversation->id)->latest('created_at')->firstOrFail();
    expect($newMessage->lead_id)->toBe($conversation->lead_id);
    expect($newMessage->body)->toBe('What is the price?');

    // The loser's own legacy fields are completely untouched by the
    // follow-up — it was never passed to appendWhatsappMessage().
    expect($leadA->fresh()->message)->toBe($leadAMessageAfterMerge);
    expect($leadA->fresh()->source_data)->toBe($leadASourceDataAfterMerge);
});

test('a whatsapp webhook for an unknown tenant code still acknowledges with 200 instead of failing Meta\'s retry', function () {
    $payload = [
        'entry' => [[
            'changes' => [[
                'value' => [
                    'messages' => [[
                        'from' => '8801700000000',
                        'text' => ['body' => 'Hello'],
                    ]],
                ],
            ]],
        ]],
    ];

    $this->postJson(route('api.webhook.whatsapp', 'TEN-DOES-NOT-EXIST'), $payload)
        ->assertOk()
        ->assertJson(['status' => 'ok']);

    expect(Lead::count())->toBe(0);
});

test('a whatsapp webhook with no active whatsapp source still acknowledges with 200', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'is_active' => false,
        'config' => ['phone_number_id' => '123', 'access_token' => 'x', 'verify_token' => 'y'],
    ]);

    $payload = [
        'entry' => [[
            'changes' => [[
                'value' => [
                    'messages' => [[
                        'from' => '8801700000000',
                        'text' => ['body' => 'Hello'],
                    ]],
                ],
            ]],
        ]],
    ];

    $this->postJson(route('api.webhook.whatsapp', $tenant->tenant_code), $payload)
        ->assertOk()
        ->assertJson(['status' => 'ok']);

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('a website form submission with a valid api key creates a lead', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::WebsiteForm,
        'config' => ['api_key' => 'test-key-123'],
    ]);

    $this->postJson(route('api.webhook.website-form', $tenant->tenant_code), [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'message' => 'I would like a quote.',
    ], ['X-Api-Key' => 'test-key-123'])->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->where('email', 'jane@example.com')->exists())->toBeTrue();
});

test('a website form submission with an invalid api key is rejected', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::WebsiteForm,
        'config' => ['api_key' => 'test-key-123'],
    ]);

    $this->postJson(route('api.webhook.website-form', $tenant->tenant_code), [
        'name' => 'Jane Doe',
    ], ['X-Api-Key' => 'wrong-key'])->assertUnauthorized();

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('a deactivated tenant does not accept webhook leads', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $tenant->update(['status' => TenantStatus::Suspended]);

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::WebsiteForm,
        'config' => ['api_key' => 'test-key-123'],
    ]);

    $this->postJson(route('api.webhook.website-form', $tenant->tenant_code), [
        'name' => 'Jane Doe',
    ], ['X-Api-Key' => 'test-key-123'])->assertNotFound();
});

test('a website form submission from an allowed origin is accepted when allowed_domains is configured', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::WebsiteForm,
        'config' => ['api_key' => 'test-key-123', 'allowed_domains' => ['example.com']],
    ]);

    $this->postJson(route('api.webhook.website-form', $tenant->tenant_code), [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
    ], ['X-Api-Key' => 'test-key-123', 'Origin' => 'https://example.com'])->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->where('email', 'jane@example.com')->exists())->toBeTrue();
});

test('a website form submission from a subdomain of an allowed domain is accepted', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::WebsiteForm,
        'config' => ['api_key' => 'test-key-123', 'allowed_domains' => ['example.com']],
    ]);

    $this->postJson(route('api.webhook.website-form', $tenant->tenant_code), [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
    ], ['X-Api-Key' => 'test-key-123', 'Origin' => 'https://www.example.com'])->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->where('email', 'jane@example.com')->exists())->toBeTrue();
});

test('a website form submission falls back to the Referer header when Origin is absent', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::WebsiteForm,
        'config' => ['api_key' => 'test-key-123', 'allowed_domains' => ['example.com']],
    ]);

    $this->postJson(route('api.webhook.website-form', $tenant->tenant_code), [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
    ], ['X-Api-Key' => 'test-key-123', 'Referer' => 'https://example.com/contact'])->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->where('email', 'jane@example.com')->exists())->toBeTrue();
});

test('a website form submission from a domain not in allowed_domains is rejected and recorded', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::WebsiteForm,
        'config' => ['api_key' => 'test-key-123', 'allowed_domains' => ['example.com']],
    ]);

    $this->postJson(route('api.webhook.website-form', $tenant->tenant_code), [
        'name' => 'Jane Doe',
    ], ['X-Api-Key' => 'test-key-123', 'Origin' => 'https://not-allowed.test'])->assertForbidden();

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(0);

    $source->refresh();
    expect($source->last_signature_rejection_at)->not->toBeNull();
    expect($source->last_signature_rejection_reason)->toContain('not-allowed.test');
});

test('a website form submission with no Origin or Referer header is rejected when allowed_domains is configured', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::WebsiteForm,
        'config' => ['api_key' => 'test-key-123', 'allowed_domains' => ['example.com']],
    ]);

    $this->postJson(route('api.webhook.website-form', $tenant->tenant_code), [
        'name' => 'Jane Doe',
    ], ['X-Api-Key' => 'test-key-123'])->assertForbidden();

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('a website form submission with no Origin header is accepted when allowed_domains is left blank', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::WebsiteForm,
        'config' => ['api_key' => 'test-key-123', 'allowed_domains' => []],
    ]);

    $this->postJson(route('api.webhook.website-form', $tenant->tenant_code), [
        'name' => 'Jane Doe',
        'email' => 'server-side@example.com',
    ], ['X-Api-Key' => 'test-key-123'])->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->where('email', 'server-side@example.com')->exists())->toBeTrue();
});

/**
 * Redelivery-idempotency gate (alreadyRecordedMessage()) — Meta/WhatsApp
 * webhooks are documented at-least-once delivery. These tests send the
 * SAME message.mid/id twice, unlike every "follow-up message... appended
 * instead of duplicated" test above (which deliberately sends a second,
 * DIFFERENT message with no id at all, to prove two distinct messages
 * still both append). The two deliveries below are two separate,
 * sequential postJson() calls — by the time the second call starts, the
 * first request has already fully returned, so the per-sender
 * Cache::lock() from the first call has already released (Illuminate\
 * Cache\Lock::block() releases in a finally block the instant its
 * callback returns, not after the lock's TTL) — this is the realistic
 * "redelivery after the dedupe lock has already released" case, not a
 * same-instant race.
 */
test('a duplicate facebook messenger message (same mid) is not appended twice', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '123', 'verify_token' => 'secret-token'],
    ]);

    $payload = [
        'entry' => [[
            'messaging' => [[
                'sender' => ['id' => 'psid-dup'],
                'message' => ['mid' => 'mid.DUPLICATE-1', 'text' => 'Hello there'],
            ]],
        ]],
    ];

    $this->postJson(route('api.webhook.facebook', $tenant->tenant_code), $payload)->assertOk();

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    expect($lead->message)->toBe('Hello there');
    expect($lead->source_data['messages'])->toHaveCount(1);
    expect(Message::where('lead_id', $lead->id)->count())->toBe(1);

    $this->postJson(route('api.webhook.facebook', $tenant->tenant_code), $payload)->assertOk();

    $lead->refresh();
    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(1);
    expect($lead->message)->toBe('Hello there');
    expect($lead->source_data['messages'])->toHaveCount(1);
    expect(Message::where('lead_id', $lead->id)->count())->toBe(1);
});

test('a duplicate instagram dm (same mid) is not appended twice', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Instagram,
        'config' => ['instagram_account_id' => '456', 'verify_token' => 'secret-token'],
    ]);

    $payload = [
        'entry' => [[
            'messaging' => [[
                'sender' => ['id' => 'igsid-dup'],
                'message' => ['mid' => 'mid.DUPLICATE-2', 'text' => 'Hi, is this available?'],
            ]],
        ]],
    ];

    $this->postJson(route('api.webhook.instagram', $tenant->tenant_code), $payload)->assertOk();

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    expect($lead->message)->toBe('Hi, is this available?');
    expect($lead->source_data['messages'])->toHaveCount(1);
    expect(Message::where('lead_id', $lead->id)->count())->toBe(1);

    $this->postJson(route('api.webhook.instagram', $tenant->tenant_code), $payload)->assertOk();

    $lead->refresh();
    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(1);
    expect($lead->message)->toBe('Hi, is this available?');
    expect($lead->source_data['messages'])->toHaveCount(1);
    expect(Message::where('lead_id', $lead->id)->count())->toBe(1);
});

test('a duplicate whatsapp message (same id) delivered after the dedupe lock has already released is not appended twice', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '123', 'access_token' => 'x', 'verify_token' => 'y'],
    ]);

    $payload = [
        'entry' => [[
            'changes' => [[
                'value' => [
                    'messages' => [[
                        'id' => 'wamid.DUPLICATE-1',
                        'from' => '8801700000001',
                        'text' => ['body' => 'Hi, interested in your product'],
                    ]],
                ],
            ]],
        ]],
    ];

    // First delivery: fully completes (and its dedupe lock releases) before
    // the second postJson() call is ever made — not a same-instant race.
    $this->postJson(route('api.webhook.whatsapp', $tenant->tenant_code), $payload)->assertOk();

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    expect($lead->message)->toBe('Hi, interested in your product');
    expect($lead->source_data['messages'])->toHaveCount(1);
    expect(Message::where('lead_id', $lead->id)->count())->toBe(1);

    // Redelivery of the exact same message.
    $this->postJson(route('api.webhook.whatsapp', $tenant->tenant_code), $payload)->assertOk();

    $lead->refresh();
    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(1);
    expect($lead->message)->toBe('Hi, interested in your product');
    expect($lead->source_data['messages'])->toHaveCount(1);
    expect(Message::where('lead_id', $lead->id)->count())->toBe(1);
});

test('a duplicate whatsapp message with an attachment does not create a second attachment or queue a second download', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '123', 'access_token' => 'x', 'verify_token' => 'y'],
    ]);

    $payload = [
        'entry' => [[
            'changes' => [[
                'value' => [
                    'messages' => [[
                        'id' => 'wamid.DUPLICATE-ATTACH-1',
                        'from' => '8801700000002',
                        'type' => 'image',
                        'image' => ['id' => 'MEDIA-DUPLICATE-1', 'caption' => 'See attached'],
                    ]],
                ],
            ]],
        ]],
    ];

    Queue::fake();

    $this->postJson(route('api.webhook.whatsapp', $tenant->tenant_code), $payload)->assertOk();

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    expect($lead->attachments)->toHaveCount(1);
    Queue::assertPushed(DownloadLeadAttachment::class, 1);

    $this->postJson(route('api.webhook.whatsapp', $tenant->tenant_code), $payload)->assertOk();

    $lead->refresh();
    expect($lead->attachments)->toHaveCount(1);
    Queue::assertPushed(DownloadLeadAttachment::class, 1);
    expect(Message::where('lead_id', $lead->id)->count())->toBe(1);
});

test('a duplicate first-ever whatsapp message (no lead exists yet) creates exactly one lead and fires LeadReceived exactly once', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '123', 'access_token' => 'x', 'verify_token' => 'y'],
    ]);

    $payload = [
        'entry' => [[
            'changes' => [[
                'value' => [
                    'messages' => [[
                        'id' => 'wamid.DUPLICATE-FIRST-1',
                        'from' => '8801700000003',
                        'text' => ['body' => 'Hi, interested in your product'],
                    ]],
                ],
            ]],
        ]],
    ];

    Event::fake([LeadReceived::class, LeadUpdated::class]);

    $this->postJson(route('api.webhook.whatsapp', $tenant->tenant_code), $payload)->assertOk();
    $this->postJson(route('api.webhook.whatsapp', $tenant->tenant_code), $payload)->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(1);
    Event::assertDispatched(LeadReceived::class, 1);
    Event::assertNotDispatched(LeadUpdated::class);

    $lead = Lead::where('tenant_id', $tenant->id)->first();
    expect(Message::where('lead_id', $lead->id)->count())->toBe(1);
});

test('two genuinely different whatsapp messages (different ids) from the same sender both continue to append normally', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '123', 'access_token' => 'x', 'verify_token' => 'y'],
    ]);

    $payload = [
        'entry' => [[
            'changes' => [[
                'value' => [
                    'messages' => [[
                        'id' => 'wamid.DIFFERENT-1',
                        'from' => '8801700000004',
                        'text' => ['body' => 'First message'],
                    ]],
                ],
            ]],
        ]],
    ];

    $this->postJson(route('api.webhook.whatsapp', $tenant->tenant_code), $payload)->assertOk();

    $payload['entry'][0]['changes'][0]['value']['messages'][0]['id'] = 'wamid.DIFFERENT-2';
    $payload['entry'][0]['changes'][0]['value']['messages'][0]['text']['body'] = 'Second message';
    $this->postJson(route('api.webhook.whatsapp', $tenant->tenant_code), $payload)->assertOk();

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    expect($lead->message)->toBe("First message\nSecond message");
    expect($lead->source_data['messages'])->toHaveCount(2);
    expect(Message::where('lead_id', $lead->id)->count())->toBe(2);
});

/**
 * L2 — inbound message cap (LeadWebhookController::exceedsInboundMessageCap()).
 * A single webhook request must not be able to trigger unbounded synchronous
 * processing based solely on how many logical messages its payload claims to
 * contain. Config('webhooks.max_inbound_messages_per_request') is overridden
 * to a small number in most tests below purely for test speed/determinism —
 * one dedicated test confirms the real shipped default is 100.
 */
test('the inbound message cap defaults to 100', function () {
    expect((int) config('webhooks.max_inbound_messages_per_request'))->toBe(100);
});

test('a facebook messenger webhook under the inbound message cap is processed normally', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '123', 'verify_token' => 'secret-token'],
    ]);

    $payload = ['entry' => [['messaging' => array_map(
        fn (int $i): array => ['sender' => ['id' => "psid-cap-{$i}"], 'message' => ['text' => "Message {$i}"]],
        range(1, 3),
    )]]];

    $this->postJson(route('api.webhook.facebook', $tenant->tenant_code), $payload)->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(3);
});

test('an instagram webhook under the inbound message cap is processed normally', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Instagram,
        'config' => ['instagram_account_id' => '456', 'verify_token' => 'secret-token'],
    ]);

    $payload = ['entry' => [['messaging' => array_map(
        fn (int $i): array => ['sender' => ['id' => "igsid-cap-{$i}"], 'message' => ['text' => "Message {$i}"]],
        range(1, 3),
    )]]];

    $this->postJson(route('api.webhook.instagram', $tenant->tenant_code), $payload)->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(3);
});

test('a whatsapp webhook under the inbound message cap is processed normally', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '123', 'access_token' => 'x', 'verify_token' => 'y'],
    ]);

    $payload = ['entry' => [['changes' => [['value' => ['messages' => array_map(
        fn (int $i): array => ['from' => "880170000{$i}", 'text' => ['body' => "Message {$i}"]],
        range(1, 3),
    )]]]]]];

    $this->postJson(route('api.webhook.whatsapp', $tenant->tenant_code), $payload)->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(3);
});

test('a webhook payload at exactly the configured cap is fully accepted', function () {
    config(['webhooks.max_inbound_messages_per_request' => 3]);
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '123', 'access_token' => 'x', 'verify_token' => 'y'],
    ]);

    $payload = ['entry' => [['changes' => [['value' => ['messages' => array_map(
        fn (int $i): array => ['from' => "880170001{$i}", 'text' => ['body' => "Message {$i}"]],
        range(1, 3),
    )]]]]]];

    $this->postJson(route('api.webhook.whatsapp', $tenant->tenant_code), $payload)->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(3);
});

test('a facebook messenger webhook exceeding the inbound message cap creates no leads and returns the existing acknowledgement', function () {
    config(['webhooks.max_inbound_messages_per_request' => 2]);
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '123', 'verify_token' => 'secret-token'],
    ]);

    $payload = ['entry' => [['messaging' => array_map(
        fn (int $i): array => ['sender' => ['id' => "psid-over-{$i}"], 'message' => ['text' => "Message {$i}"]],
        range(1, 3),
    )]]];

    $this->postJson(route('api.webhook.facebook', $tenant->tenant_code), $payload)
        ->assertOk()
        ->assertJson(['status' => 'ok']);

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(0);
    expect(Message::count())->toBe(0);
});

test('an instagram webhook exceeding the inbound message cap creates no leads and returns the existing acknowledgement', function () {
    config(['webhooks.max_inbound_messages_per_request' => 2]);
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Instagram,
        'config' => ['instagram_account_id' => '456', 'verify_token' => 'secret-token'],
    ]);

    $payload = ['entry' => [['messaging' => array_map(
        fn (int $i): array => ['sender' => ['id' => "igsid-over-{$i}"], 'message' => ['text' => "Message {$i}"]],
        range(1, 3),
    )]]];

    $this->postJson(route('api.webhook.instagram', $tenant->tenant_code), $payload)
        ->assertOk()
        ->assertJson(['status' => 'ok']);

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(0);
    expect(Message::count())->toBe(0);
});

test('a whatsapp webhook exceeding the inbound message cap creates no leads, messages, conversations, or attachments, and dispatches no downloads or events', function () {
    config(['webhooks.max_inbound_messages_per_request' => 2]);
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '123', 'access_token' => 'x', 'verify_token' => 'y'],
    ]);

    $payload = ['entry' => [['changes' => [['value' => ['messages' => array_map(
        fn (int $i): array => [
            'from' => "880170002{$i}",
            'type' => 'image',
            'image' => ['id' => "MEDIA-OVER-{$i}"],
            'text' => ['body' => "Message {$i}"],
        ],
        range(1, 3),
    )]]]]]];

    Event::fake([LeadReceived::class, LeadUpdated::class]);
    Queue::fake();

    $this->postJson(route('api.webhook.whatsapp', $tenant->tenant_code), $payload)
        ->assertOk()
        ->assertJson(['status' => 'ok']);

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(0);
    expect(Message::count())->toBe(0);
    expect(Conversation::where('tenant_id', $tenant->id)->count())->toBe(0);
    Event::assertNotDispatched(LeadReceived::class);
    Event::assertNotDispatched(LeadUpdated::class);
    Queue::assertNothingPushed();
});

test('the inbound message cap is a total across all entries in the request, not a per-entry limit', function () {
    config(['webhooks.max_inbound_messages_per_request' => 5]);
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '123', 'access_token' => 'x', 'verify_token' => 'y'],
    ]);

    // Two entries, three messages each — 6 total, over the cap of 5, even
    // though neither entry alone exceeds it.
    $entry = fn (int $offset): array => ['changes' => [['value' => ['messages' => array_map(
        fn (int $i): array => ['from' => "88017000{$offset}{$i}", 'text' => ['body' => "Message {$i}"]],
        range(1, 3),
    )]]]];

    $payload = ['entry' => [$entry(1), $entry(2)]];

    $this->postJson(route('api.webhook.whatsapp', $tenant->tenant_code), $payload)->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('facebook delivery and read receipts do not count toward the inbound message cap', function () {
    config(['webhooks.max_inbound_messages_per_request' => 2]);
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '123', 'verify_token' => 'secret-token'],
    ]);

    // 2 real messages + 3 delivery/read receipts = 5 raw items, but only 2
    // logical messages — must stay under the cap of 2.
    $payload = ['entry' => [['messaging' => [
        ['sender' => ['id' => 'psid-receipt-1'], 'message' => ['text' => 'Real message one']],
        ['sender' => ['id' => 'page-1'], 'recipient' => ['id' => 'psid-receipt-1'], 'delivery' => ['watermark' => now()->timestamp * 1000]],
        ['sender' => ['id' => 'page-1'], 'recipient' => ['id' => 'psid-receipt-1'], 'delivery' => ['watermark' => now()->timestamp * 1000]],
        ['sender' => ['id' => 'psid-receipt-2'], 'message' => ['text' => 'Real message two']],
        ['sender' => ['id' => 'page-1'], 'recipient' => ['id' => 'psid-receipt-2'], 'read' => ['watermark' => now()->timestamp * 1000]],
    ]]]];

    $this->postJson(route('api.webhook.facebook', $tenant->tenant_code), $payload)->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(2);
});

test('whatsapp statuses do not count toward the inbound message cap', function () {
    config(['webhooks.max_inbound_messages_per_request' => 2]);
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '123', 'access_token' => 'x', 'verify_token' => 'y'],
    ]);

    // 2 real messages + 3 status callbacks = 5 raw items, but only 2
    // logical messages — must stay under the cap of 2.
    $payload = ['entry' => [['changes' => [['value' => [
        'messages' => [
            ['from' => '8801700031', 'text' => ['body' => 'Real message one']],
            ['from' => '8801700032', 'text' => ['body' => 'Real message two']],
        ],
        'statuses' => [
            ['id' => 'wamid.STATUS-1', 'status' => 'delivered', 'timestamp' => (string) now()->timestamp],
            ['id' => 'wamid.STATUS-2', 'status' => 'read', 'timestamp' => (string) now()->timestamp],
            ['id' => 'wamid.STATUS-3', 'status' => 'sent', 'timestamp' => (string) now()->timestamp],
        ],
    ]]]]]];

    // These synthetic status ids match no real outbound Message row, which
    // would make ReconcileOutboundMessageStatus's own unrelated, pre-existing
    // "let the job retry" behavior throw under the sync queue driver — faked
    // here purely to isolate this test to the message-count cap, not to
    // status reconciliation (already covered by its own dedicated test file).
    Queue::fake();

    $this->postJson(route('api.webhook.whatsapp', $tenant->tenant_code), $payload)->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(2);
});

test('a duplicate message id inside an under-cap batch is still skipped by the existing idempotency gate, without the batch itself being rejected', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '123', 'access_token' => 'x', 'verify_token' => 'y'],
    ]);

    // First delivery: a single message that will later be "redelivered".
    $this->postJson(route('api.webhook.whatsapp', $tenant->tenant_code), [
        'entry' => [['changes' => [['value' => ['messages' => [[
            'id' => 'wamid.CAP-DUP-1',
            'from' => '8801700041',
            'text' => ['body' => 'Original message'],
        ]]]]]]],
    ])->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(1);

    // Second delivery: the same message id again, PLUS one genuinely new
    // message — both under the cap. The duplicate must be skipped by the
    // existing alreadyRecordedMessage() gate; the new one must still land.
    $this->postJson(route('api.webhook.whatsapp', $tenant->tenant_code), [
        'entry' => [['changes' => [['value' => ['messages' => [
            ['id' => 'wamid.CAP-DUP-1', 'from' => '8801700041', 'text' => ['body' => 'Original message']],
            ['id' => 'wamid.CAP-DUP-2', 'from' => '8801700042', 'text' => ['body' => 'A new message']],
        ]]]]]],
    ])->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(2);
    expect(Message::whereHas('lead', fn ($q) => $q->where('tenant_id', $tenant->id))->count())->toBe(2);
});

test('an attachment-bearing message under the inbound message cap still creates its attachment and queues its download', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '123', 'access_token' => 'x', 'verify_token' => 'y'],
    ]);

    Queue::fake();

    $this->postJson(route('api.webhook.whatsapp', $tenant->tenant_code), [
        'entry' => [['changes' => [['value' => ['messages' => [[
            'id' => 'wamid.CAP-ATTACH-1',
            'from' => '8801700051',
            'type' => 'image',
            'image' => ['id' => 'MEDIA-CAP-1', 'caption' => 'See attached'],
        ]]]]]]],
    ])->assertOk();

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    expect($lead->attachments)->toHaveCount(1);
    Queue::assertPushed(DownloadLeadAttachment::class, 1);
});

test('a null-external-id message under the inbound message cap is processed exactly as before this fix', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '123', 'access_token' => 'x', 'verify_token' => 'y'],
    ]);

    // No 'id' field at all — mirrors every pre-existing fixture in this file.
    $this->postJson(route('api.webhook.whatsapp', $tenant->tenant_code), [
        'entry' => [['changes' => [['value' => ['messages' => [[
            'from' => '8801700061',
            'text' => ['body' => 'No id field on this message'],
        ]]]]]]],
    ])->assertOk();

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    expect($lead->message)->toBe('No id field on this message');
    expect(Message::where('lead_id', $lead->id)->first()->external_message_id)->toBeNull();
});

// --- REL-1: per-item exception isolation ------------------------------

test('a whatsapp middle-item lock timeout does not drop the trailing message, is logged at warning (not error), and the whole response becomes 503 so Meta retries', function () {
    // F2 remediation (forensic audit Batch C) — a lock timeout is no longer
    // treated identically to an ordinary per-item processing failure: it's
    // logged at warning (not error, since nothing was actually corrupted —
    // this item's own dedupe check never ran) under a distinct message,
    // and the WHOLE response becomes 503 so Meta's own retry delivers this
    // exact request again. Items 1 and 3, which already succeeded and were
    // durably recorded in THIS same request, are unaffected by that
    // retry — this test only proves this one request's own response/log
    // shape; WebhookSignatureVerificationTest-adjacent coverage of the
    // redelivery-is-idempotent behavior already exists elsewhere via
    // alreadyRecordedMessage(), untouched by this remediation.
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '123', 'access_token' => 'x', 'verify_token' => 'y'],
    ]);

    $realCache = Cache::getFacadeRoot();

    Cache::shouldReceive('lock')
        ->andReturnUsing(fn (string $name, int $seconds = 0, ?string $owner = null): mixed => realOrThrowingLock($realCache, $name, $seconds, $owner, '8801700000002'));

    Log::shouldReceive('channel')->with('whatsapp')->andReturnSelf();
    Log::shouldReceive('info');
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            expect($message)->toBe('WA ITEM LOCK TIMED OUT');
            expect($context['sender_id'])->toBe('8801700000002');
            expect($context['external_message_id'])->toBe('wamid.ITEM-2');

            $encoded = json_encode($context);
            expect($encoded)->not->toContain('This text must never reach the log');

            return true;
        });

    $this->postJson(route('api.webhook.whatsapp', $tenant->tenant_code), [
        'entry' => [['changes' => [['value' => ['messages' => [
            ['id' => 'wamid.ITEM-1', 'from' => '8801700000001', 'text' => ['body' => 'Item 1']],
            ['id' => 'wamid.ITEM-2', 'from' => '8801700000002', 'text' => ['body' => 'This text must never reach the log']],
            ['id' => 'wamid.ITEM-3', 'from' => '8801700000003', 'text' => ['body' => 'Item 3']],
        ]]]]]],
    ])->assertStatus(503)->assertExactJson(['status' => 'retry']);

    expect(Lead::where('tenant_id', $tenant->id)->where('phone', '8801700000001')->exists())->toBeTrue();
    expect(Lead::where('tenant_id', $tenant->id)->where('phone', '8801700000002')->exists())->toBeFalse();
    expect(Lead::where('tenant_id', $tenant->id)->where('phone', '8801700000003')->exists())->toBeTrue();
});

test('a whatsapp first-item lock timeout does not prevent the second item from processing', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '123', 'access_token' => 'x', 'verify_token' => 'y'],
    ]);

    $realCache = Cache::getFacadeRoot();

    Cache::shouldReceive('lock')
        ->andReturnUsing(fn (string $name, int $seconds = 0, ?string $owner = null): mixed => realOrThrowingLock($realCache, $name, $seconds, $owner, '8801700000091'));

    Log::shouldReceive('channel')->with('whatsapp')->andReturnSelf();
    Log::shouldReceive('info');
    Log::shouldReceive('warning')->once();

    $this->postJson(route('api.webhook.whatsapp', $tenant->tenant_code), [
        'entry' => [['changes' => [['value' => ['messages' => [
            ['id' => 'wamid.ORDER-1', 'from' => '8801700000091', 'text' => ['body' => 'First item fails']],
            ['id' => 'wamid.ORDER-2', 'from' => '8801700000092', 'text' => ['body' => 'Second item must still process']],
        ]]]]]],
    ])->assertStatus(503); // F2 remediation — a lock timeout now yields 503, not 200.

    expect(Lead::where('tenant_id', $tenant->id)->where('phone', '8801700000091')->exists())->toBeFalse();
    expect(Lead::where('tenant_id', $tenant->id)->where('phone', '8801700000092')->exists())->toBeTrue();
});

test('a whatsapp item that failed once can still be processed on a later, unblocked redelivery', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '123', 'access_token' => 'x', 'verify_token' => 'y'],
    ]);

    $realCache = Cache::getFacadeRoot();

    Cache::shouldReceive('lock')
        ->andReturnUsing(fn (string $name, int $seconds = 0, ?string $owner = null): mixed => realOrThrowingLock($realCache, $name, $seconds, $owner, '8801700000099'));

    Log::shouldReceive('channel')->with('whatsapp')->andReturnSelf();
    Log::shouldReceive('info');
    Log::shouldReceive('warning')->once();

    $this->postJson(route('api.webhook.whatsapp', $tenant->tenant_code), [
        'entry' => [['changes' => [['value' => ['messages' => [[
            'id' => 'wamid.REDELIVER-1',
            'from' => '8801700000099',
            'text' => ['body' => 'First attempt fails'],
        ]]]]]]],
    ])->assertStatus(503); // F2 remediation — a lock timeout now yields 503, not 200.

    expect(Lead::where('tenant_id', $tenant->id)->where('phone', '8801700000099')->exists())->toBeFalse();

    // Redelivery: explicitly restore the real, unmocked cache store before
    // the second request — Cache::shouldReceive('lock') above stays
    // installed for the rest of the test otherwise, which would make the
    // "redelivery" request fail identically to the first. Swapping back
    // the real instance proves the message now succeeds on its own
    // merits, with no duplicate-processing risk from the first, failed
    // attempt (it never got far enough to create any durable record — see
    // the Implementation Report's retry/idempotency verification).
    Cache::swap($realCache);

    $this->postJson(route('api.webhook.whatsapp', $tenant->tenant_code), [
        'entry' => [['changes' => [['value' => ['messages' => [[
            'id' => 'wamid.REDELIVER-1',
            'from' => '8801700000099',
            'text' => ['body' => 'Redelivered attempt succeeds'],
        ]]]]]]],
    ])->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->where('phone', '8801700000099')->count())->toBe(1);
});

test('a facebook messenger middle-item lock timeout does not drop the trailing message, and the failure is logged without leaking message content', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '123', 'verify_token' => 'secret-token'],
    ]);

    // Items 1 and 3 are follow-ups on ALREADY-NAMED existing leads — this
    // deliberately avoids appendFacebookMessage()'s own conditional
    // fetchFacebookProfile() call (only triggered when the lead still
    // carries its placeholder "Facebook User {id}" name), keeping this
    // test's only Cache:: touchpoint the one being verified.
    $lead1 = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'name' => 'Existing Contact One', 'source_data' => ['facebook_user_id' => 'psid-rel1-1']]);
    $lead3 = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'name' => 'Existing Contact Three', 'source_data' => ['facebook_user_id' => 'psid-rel1-3']]);

    $realCache = Cache::getFacadeRoot();

    Cache::shouldReceive('lock')
        ->andReturnUsing(fn (string $name, int $seconds = 0, ?string $owner = null): mixed => realOrThrowingLock($realCache, $name, $seconds, $owner, 'psid-rel1-2'));

    // F2 remediation (forensic audit Batch C) — see the WhatsApp
    // middle-item test's identical comment for the full reasoning: a lock
    // timeout is now logged at warning (a distinct message), not error,
    // and the whole response becomes 503.
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message): bool {
            expect($message)->toContain('Facebook Messenger webhook item lock timed out');
            expect($message)->toContain('psid-rel1-2');
            expect($message)->not->toContain('This text must never reach the log');

            return true;
        });

    $this->postJson(route('api.webhook.facebook', $tenant->tenant_code), [
        'entry' => [[
            'messaging' => [
                ['sender' => ['id' => 'psid-rel1-1'], 'message' => ['mid' => 'mid-rel1-1', 'text' => 'Item 1']],
                ['sender' => ['id' => 'psid-rel1-2'], 'message' => ['mid' => 'mid-rel1-2', 'text' => 'This text must never reach the log']],
                ['sender' => ['id' => 'psid-rel1-3'], 'message' => ['mid' => 'mid-rel1-3', 'text' => 'Item 3']],
            ],
        ]],
    ])->assertStatus(503)->assertExactJson(['status' => 'retry']);

    expect($lead1->fresh()->message)->toContain('Item 1');
    expect(Lead::where('tenant_id', $tenant->id)->where('source_data->facebook_user_id', 'psid-rel1-2')->exists())->toBeFalse();
    expect($lead3->fresh()->message)->toContain('Item 3');
});

test('a facebook messenger first-item lock timeout does not prevent the second item from processing', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '123', 'verify_token' => 'secret-token'],
    ]);

    // Item 2 is an existing-lead follow-up (already-real name) — same
    // reasoning as the middle-item test above: keeps this test's only
    // Cache:: touchpoint the withDedupeLock() call being verified.
    $lead2 = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'name' => 'Existing Contact Two', 'source_data' => ['facebook_user_id' => 'psid-order-2']]);

    $realCache = Cache::getFacadeRoot();

    Cache::shouldReceive('lock')
        ->andReturnUsing(fn (string $name, int $seconds = 0, ?string $owner = null): mixed => realOrThrowingLock($realCache, $name, $seconds, $owner, 'psid-order-1'));

    Log::shouldReceive('warning')->once();

    $this->postJson(route('api.webhook.facebook', $tenant->tenant_code), [
        'entry' => [[
            'messaging' => [
                ['sender' => ['id' => 'psid-order-1'], 'message' => ['mid' => 'mid-order-1', 'text' => 'First item fails']],
                ['sender' => ['id' => 'psid-order-2'], 'message' => ['mid' => 'mid-order-2', 'text' => 'Second item must still process']],
            ],
        ]],
    ])->assertStatus(503); // F2 remediation — a lock timeout now yields 503, not 200.

    expect(Lead::where('tenant_id', $tenant->id)->where('source_data->facebook_user_id', 'psid-order-1')->exists())->toBeFalse();
    expect($lead2->fresh()->message)->toContain('Second item must still process');
});

test('a facebook messenger item that failed once can still be processed on a later, unblocked redelivery', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '123', 'verify_token' => 'secret-token'],
    ]);

    $realCache = Cache::getFacadeRoot();

    Cache::shouldReceive('lock')
        ->andReturnUsing(fn (string $name, int $seconds = 0, ?string $owner = null): mixed => realOrThrowingLock($realCache, $name, $seconds, $owner, 'psid-redeliver'));

    Log::shouldReceive('warning')->once();

    $this->postJson(route('api.webhook.facebook', $tenant->tenant_code), [
        'entry' => [['messaging' => [
            ['sender' => ['id' => 'psid-redeliver'], 'message' => ['mid' => 'mid-redeliver-1', 'text' => 'First attempt fails']],
        ]]],
    ])->assertStatus(503); // F2 remediation — a lock timeout now yields 503, not 200.

    expect(Lead::where('tenant_id', $tenant->id)->where('source_data->facebook_user_id', 'psid-redeliver')->exists())->toBeFalse();

    Cache::swap($realCache);

    $this->postJson(route('api.webhook.facebook', $tenant->tenant_code), [
        'entry' => [['messaging' => [
            ['sender' => ['id' => 'psid-redeliver'], 'message' => ['mid' => 'mid-redeliver-1', 'text' => 'Redelivered attempt succeeds']],
        ]]],
    ])->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->where('source_data->facebook_user_id', 'psid-redeliver')->count())->toBe(1);
});

test('an instagram middle-item lock timeout does not drop the trailing message, and the failure is logged without leaking message content', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Instagram,
        'config' => ['page_id' => '123', 'verify_token' => 'secret-token'],
    ]);

    $lead1 = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'name' => 'Existing Contact One', 'source_data' => ['instagram_user_id' => 'igsid-rel1-1']]);
    $lead3 = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'name' => 'Existing Contact Three', 'source_data' => ['instagram_user_id' => 'igsid-rel1-3']]);

    $realCache = Cache::getFacadeRoot();

    Cache::shouldReceive('lock')
        ->andReturnUsing(fn (string $name, int $seconds = 0, ?string $owner = null): mixed => realOrThrowingLock($realCache, $name, $seconds, $owner, 'igsid-rel1-2'));

    // F2 remediation (forensic audit Batch C) — see the WhatsApp
    // middle-item test's identical comment for the full reasoning.
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message): bool {
            expect($message)->toContain('Instagram webhook item lock timed out');
            expect($message)->toContain('igsid-rel1-2');
            expect($message)->not->toContain('This text must never reach the log');

            return true;
        });

    $this->postJson(route('api.webhook.instagram', $tenant->tenant_code), [
        'entry' => [[
            'messaging' => [
                ['sender' => ['id' => 'igsid-rel1-1'], 'message' => ['mid' => 'mid-ig-rel1-1', 'text' => 'Item 1']],
                ['sender' => ['id' => 'igsid-rel1-2'], 'message' => ['mid' => 'mid-ig-rel1-2', 'text' => 'This text must never reach the log']],
                ['sender' => ['id' => 'igsid-rel1-3'], 'message' => ['mid' => 'mid-ig-rel1-3', 'text' => 'Item 3']],
            ],
        ]],
    ])->assertStatus(503)->assertExactJson(['status' => 'retry']);

    expect($lead1->fresh()->message)->toContain('Item 1');
    expect(Lead::where('tenant_id', $tenant->id)->where('source_data->instagram_user_id', 'igsid-rel1-2')->exists())->toBeFalse();
    expect($lead3->fresh()->message)->toContain('Item 3');
});

test('an instagram first-item lock timeout does not prevent the second item from processing', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Instagram,
        'config' => ['page_id' => '123', 'verify_token' => 'secret-token'],
    ]);

    // Item 2 is an existing-lead follow-up — same reasoning as the
    // Messenger equivalent above.
    $lead2 = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'name' => 'Existing Contact Two', 'source_data' => ['instagram_user_id' => 'igsid-order-2']]);

    $realCache = Cache::getFacadeRoot();

    Cache::shouldReceive('lock')
        ->andReturnUsing(fn (string $name, int $seconds = 0, ?string $owner = null): mixed => realOrThrowingLock($realCache, $name, $seconds, $owner, 'igsid-order-1'));

    Log::shouldReceive('warning')->once();

    $this->postJson(route('api.webhook.instagram', $tenant->tenant_code), [
        'entry' => [[
            'messaging' => [
                ['sender' => ['id' => 'igsid-order-1'], 'message' => ['mid' => 'mid-ig-order-1', 'text' => 'First item fails']],
                ['sender' => ['id' => 'igsid-order-2'], 'message' => ['mid' => 'mid-ig-order-2', 'text' => 'Second item must still process']],
            ],
        ]],
    ])->assertStatus(503); // F2 remediation — a lock timeout now yields 503, not 200.

    expect(Lead::where('tenant_id', $tenant->id)->where('source_data->instagram_user_id', 'igsid-order-1')->exists())->toBeFalse();
    expect($lead2->fresh()->message)->toContain('Second item must still process');
});

test('an instagram item that failed once can still be processed on a later, unblocked redelivery', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Instagram,
        'config' => ['page_id' => '123', 'verify_token' => 'secret-token'],
    ]);

    $realCache = Cache::getFacadeRoot();

    Cache::shouldReceive('lock')
        ->andReturnUsing(fn (string $name, int $seconds = 0, ?string $owner = null): mixed => realOrThrowingLock($realCache, $name, $seconds, $owner, 'igsid-redeliver'));

    Log::shouldReceive('warning')->once();

    $this->postJson(route('api.webhook.instagram', $tenant->tenant_code), [
        'entry' => [['messaging' => [
            ['sender' => ['id' => 'igsid-redeliver'], 'message' => ['mid' => 'mid-ig-redeliver-1', 'text' => 'First attempt fails']],
        ]]],
    ])->assertStatus(503); // F2 remediation — a lock timeout now yields 503, not 200.

    expect(Lead::where('tenant_id', $tenant->id)->where('source_data->instagram_user_id', 'igsid-redeliver')->exists())->toBeFalse();

    Cache::swap($realCache);

    $this->postJson(route('api.webhook.instagram', $tenant->tenant_code), [
        'entry' => [['messaging' => [
            ['sender' => ['id' => 'igsid-redeliver'], 'message' => ['mid' => 'mid-ig-redeliver-1', 'text' => 'Redelivered attempt succeeds']],
        ]]],
    ])->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->where('source_data->instagram_user_id', 'igsid-redeliver')->count())->toBe(1);
});

// --- F2 remediation (forensic audit Batch C): an ORDINARY per-item failure
// (never a lock timeout) must keep this app's existing, unmodified 200
// policy — only a genuine LockTimeoutException flips the response. Each
// test below forces the exact same withDedupeLock() call site to throw a
// plain RuntimeException instead of LockTimeoutException, proving the two
// exception types are handled by genuinely distinct catch blocks, not
// merely different log wording on the same path. ---

test('an ordinary (non-lock) whatsapp item failure is still acknowledged with 200, never 503', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '123', 'access_token' => 'x', 'verify_token' => 'y'],
    ]);

    $realCache = Cache::getFacadeRoot();

    Cache::shouldReceive('lock')->andReturnUsing(function (string $name, int $seconds = 0, ?string $owner = null) use ($realCache): mixed {
        if (str_contains($name, '8801700099999')) {
            $lock = Mockery::mock(Lock::class);
            $lock->shouldReceive('block')->once()->andThrow(new RuntimeException('a genuine, unrelated processing bug'));

            return $lock;
        }

        return $realCache->lock($name, $seconds, $owner);
    });

    Log::shouldReceive('channel')->with('whatsapp')->andReturnSelf();
    Log::shouldReceive('info');
    Log::shouldReceive('error')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            expect($message)->toBe('WA ITEM PROCESSING FAILED');
            expect($context['error'])->toBe('a genuine, unrelated processing bug');

            return true;
        });

    $this->postJson(route('api.webhook.whatsapp', $tenant->tenant_code), [
        'entry' => [['changes' => [['value' => ['messages' => [[
            'id' => 'wamid.ORDINARY-FAIL',
            'from' => '8801700099999',
            'text' => ['body' => 'This item fails for an unrelated reason'],
        ]]]]]]],
    ])->assertOk()->assertExactJson(['status' => 'ok']);

    expect(Lead::where('tenant_id', $tenant->id)->where('phone', '8801700099999')->exists())->toBeFalse();
});

test('an ordinary (non-lock) facebook messenger item failure is still acknowledged with 200, never 503', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '123', 'verify_token' => 'secret-token'],
    ]);

    $realCache = Cache::getFacadeRoot();

    Cache::shouldReceive('lock')->andReturnUsing(function (string $name, int $seconds = 0, ?string $owner = null) use ($realCache): mixed {
        if (str_contains($name, 'psid-ordinary-fail')) {
            $lock = Mockery::mock(Lock::class);
            $lock->shouldReceive('block')->once()->andThrow(new RuntimeException('a genuine, unrelated processing bug'));

            return $lock;
        }

        return $realCache->lock($name, $seconds, $owner);
    });

    Log::shouldReceive('error')->once()->withArgs(function (string $message): bool {
        expect($message)->toContain('Facebook Messenger webhook item processing failed');
        expect($message)->toContain('psid-ordinary-fail');

        return true;
    });

    $this->postJson(route('api.webhook.facebook', $tenant->tenant_code), [
        'entry' => [['messaging' => [
            ['sender' => ['id' => 'psid-ordinary-fail'], 'message' => ['mid' => 'mid-ordinary-fail', 'text' => 'This item fails for an unrelated reason']],
        ]]],
    ])->assertOk()->assertExactJson(['status' => 'ok']);

    expect(Lead::where('tenant_id', $tenant->id)->where('source_data->facebook_user_id', 'psid-ordinary-fail')->exists())->toBeFalse();
});

test('an ordinary (non-lock) instagram item failure is still acknowledged with 200, never 503', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Instagram,
        'config' => ['page_id' => '123', 'verify_token' => 'secret-token'],
    ]);

    $realCache = Cache::getFacadeRoot();

    Cache::shouldReceive('lock')->andReturnUsing(function (string $name, int $seconds = 0, ?string $owner = null) use ($realCache): mixed {
        if (str_contains($name, 'igsid-ordinary-fail')) {
            $lock = Mockery::mock(Lock::class);
            $lock->shouldReceive('block')->once()->andThrow(new RuntimeException('a genuine, unrelated processing bug'));

            return $lock;
        }

        return $realCache->lock($name, $seconds, $owner);
    });

    Log::shouldReceive('error')->once()->withArgs(function (string $message): bool {
        expect($message)->toContain('Instagram webhook item processing failed');
        expect($message)->toContain('igsid-ordinary-fail');

        return true;
    });

    $this->postJson(route('api.webhook.instagram', $tenant->tenant_code), [
        'entry' => [['messaging' => [
            ['sender' => ['id' => 'igsid-ordinary-fail'], 'message' => ['mid' => 'mid-ig-ordinary-fail', 'text' => 'This item fails for an unrelated reason']],
        ]]],
    ])->assertOk()->assertExactJson(['status' => 'ok']);

    expect(Lead::where('tenant_id', $tenant->id)->where('source_data->instagram_user_id', 'igsid-ordinary-fail')->exists())->toBeFalse();
});

test('a whatsapp redelivery of an already-successfully-processed item does not create a duplicate lead', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '123', 'access_token' => 'x', 'verify_token' => 'y'],
    ]);

    $payload = [
        'entry' => [['changes' => [['value' => ['messages' => [[
            'id' => 'wamid.ALREADY-OK-1',
            'from' => '8801700000199',
            'text' => ['body' => 'Original message'],
        ]]]]]]],
    ];

    $this->postJson(route('api.webhook.whatsapp', $tenant->tenant_code), $payload)->assertOk();
    expect(Lead::where('tenant_id', $tenant->id)->where('phone', '8801700000199')->count())->toBe(1);

    // Redelivery of the exact same message id — alreadyRecordedMessage()
    // must still skip it, exactly as before this fix; REL-1's per-item
    // catch does not change this pre-existing idempotency guarantee.
    $this->postJson(route('api.webhook.whatsapp', $tenant->tenant_code), $payload)->assertOk();
    expect(Lead::where('tenant_id', $tenant->id)->where('phone', '8801700000199')->count())->toBe(1);
});
