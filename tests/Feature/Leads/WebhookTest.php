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
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

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
