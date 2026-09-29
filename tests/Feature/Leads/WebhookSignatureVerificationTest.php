<?php

use App\Enums\LeadSourceType;
use App\Livewire\Platform\Clients\LeadSources;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\PlatformUser;

function signedWhatsappPayload(): array
{
    return [
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
}

function metaSignatureHeader(array $payload, string $appSecret): string
{
    $body = json_encode($payload);

    return 'sha256='.hash_hmac('sha256', $body, $appSecret);
}

test('a whatsapp webhook without a configured app secret is processed with no signature required', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '123', 'access_token' => 'x', 'verify_token' => 'y'],
    ]);

    $this->postJson(route('api.webhook.whatsapp', $tenant->tenant_code), signedWhatsappPayload())
        ->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('a whatsapp webhook with a configured app secret and a valid signature is processed', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '123', 'access_token' => 'x', 'verify_token' => 'y', 'app_secret' => 'wa-app-secret'],
    ]);

    $payload = signedWhatsappPayload();

    $this->postJson(
        route('api.webhook.whatsapp', $tenant->tenant_code),
        $payload,
        ['X-Hub-Signature-256' => metaSignatureHeader($payload, 'wa-app-secret')],
    )->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('a whatsapp webhook with a configured app secret and a missing signature is rejected and recorded on the source', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '123', 'access_token' => 'x', 'verify_token' => 'y', 'app_secret' => 'wa-app-secret'],
    ]);

    $this->postJson(route('api.webhook.whatsapp', $tenant->tenant_code), signedWhatsappPayload())
        ->assertForbidden();

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(0);

    $source->refresh();
    expect($source->last_signature_rejection_at)->not->toBeNull();
    expect($source->last_signature_rejection_reason)->toContain('Signature mismatch');
});

test('a whatsapp webhook with a configured app secret and a wrong signature is rejected', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '123', 'access_token' => 'x', 'verify_token' => 'y', 'app_secret' => 'wa-app-secret'],
    ]);

    $payload = signedWhatsappPayload();

    $this->postJson(
        route('api.webhook.whatsapp', $tenant->tenant_code),
        $payload,
        ['X-Hub-Signature-256' => metaSignatureHeader($payload, 'wrong-secret')],
    )->assertForbidden();

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('a facebook webhook with a configured app secret and a valid signature is processed', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '123', 'page_access_token' => 'x', 'verify_token' => 'y', 'app_secret' => 'fb-app-secret'],
    ]);

    $payload = [
        'entry' => [[
            'messaging' => [[
                'sender' => ['id' => 'psid-1'],
                'message' => ['text' => 'Hello there'],
            ]],
        ]],
    ];

    $this->postJson(
        route('api.webhook.facebook', $tenant->tenant_code),
        $payload,
        ['X-Hub-Signature-256' => metaSignatureHeader($payload, 'fb-app-secret')],
    )->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(1);
});

// --- F1 remediation (forensic audit Batch A) — opaque webhook token -------
//
// LeadSource.webhook_token_hash (see
// 2026_09_29_070000_add_webhook_token_hash_to_lead_sources_table.php) plus
// the new api.webhook.{platform}.token route shape and
// LeadWebhookController::resolveSource()'s own $webhookToken branch — see
// that method's docblock for the exact resolution/failure contract these
// tests assert against.

test('the legacy tenant_code-only URL still works for a source that has no webhook token yet (deprecation window)', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '123', 'access_token' => 'x', 'verify_token' => 'y'],
    ]);

    test()->postJson(route('api.webhook.whatsapp', $tenant->tenant_code), signedWhatsappPayload())
        ->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('the legacy tenant_code-only URL still works even for a source that HAS a webhook token', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '123', 'access_token' => 'x', 'verify_token' => 'y'],
        'webhook_token_hash' => hash('sha256', 'a-real-token-nobody-should-need-here'),
    ]);

    test()->postJson(route('api.webhook.whatsapp', $tenant->tenant_code), signedWhatsappPayload())
        ->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('a valid opaque webhook token on the new token-bearing route resolves the correct source and processes the webhook', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $plaintextToken = Str::random(40);

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '123', 'access_token' => 'x', 'verify_token' => 'y'],
        'webhook_token_hash' => hash('sha256', $plaintextToken),
    ]);

    test()->postJson(route('api.webhook.whatsapp.token', [$tenant->tenant_code, $plaintextToken]), signedWhatsappPayload())
        ->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('a wrong token on the token-bearing route is rejected and creates no lead', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '123', 'access_token' => 'x', 'verify_token' => 'y'],
        'webhook_token_hash' => hash('sha256', 'the-real-token'),
    ]);

    // resolveSource()'s abort(404) is thrown INSIDE whatsapp()'s own
    // try/catch, so — matching this app's established, unchanged "always
    // acknowledge Meta with 200" policy for every internal failure on this
    // route (see LeadWebhookController::whatsapp()'s own docblock) — the
    // HTTP response still comes back 200; the real signal that the token
    // was rejected is that no Lead was created, asserted below.
    test()->postJson(route('api.webhook.whatsapp.token', [$tenant->tenant_code, 'a-completely-wrong-token']), signedWhatsappPayload())
        ->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('a missing token segment on the token-bearing route shape 404s at the routing layer', function () {
    // The token-bearing route requires the {webhook_token} segment to be
    // present at all — Laravel simply has no matching route without it, so
    // this is never reachable at the controller/resolveSource() level in
    // the first place. Asserted against the raw URL (not route()) since
    // route() cannot generate a URL missing a required segment.
    test()->postJson('/api/webhook/whatsapp//', ['entry' => []])->assertNotFound();
});

test('a token that belongs to a different tenant cannot resolve this tenant\'s source', function () {
    ['tenant' => $tenantA] = tenantWithLeadManagement();
    ['tenant' => $tenantB] = tenantWithLeadManagement();
    $plaintextToken = Str::random(40);

    LeadSource::factory()->create([
        'tenant_id' => $tenantB->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '123', 'access_token' => 'x', 'verify_token' => 'y'],
        'webhook_token_hash' => hash('sha256', $plaintextToken),
    ]);

    // Tenant B's own token, posted against Tenant A's tenant_code segment —
    // still 200 (see the wrong-token test's identical comment above); the
    // real assertion is that no Lead was created for either tenant.
    test()->postJson(route('api.webhook.whatsapp.token', [$tenantA->tenant_code, $plaintextToken]), signedWhatsappPayload())
        ->assertOk();

    expect(Lead::count())->toBe(0);
});

test('a token that belongs to a different Meta platform cannot resolve a source on this platform\'s route', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $plaintextToken = Str::random(40);

    // The token belongs to a WhatsApp source...
    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '123', 'access_token' => 'x', 'verify_token' => 'y'],
        'webhook_token_hash' => hash('sha256', $plaintextToken),
    ]);

    // ...but the request is posted to the Facebook Messenger route — still
    // 200 (see the wrong-token test's identical comment above); the real
    // assertion is that no Lead was created.
    test()->postJson(route('api.webhook.facebook.token', [$tenant->tenant_code, $plaintextToken]), [
        'entry' => [['messaging' => [['sender' => ['id' => 'psid-1'], 'message' => ['text' => 'hi']]]]],
    ])->assertOk();

    expect(Lead::count())->toBe(0);
});

test('a source with no app_secret works through the new token-bearing route exactly as it does through the legacy one — no-secret is still intentionally supported', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $plaintextToken = Str::random(40);

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '123', 'access_token' => 'x', 'verify_token' => 'y'],
        'webhook_token_hash' => hash('sha256', $plaintextToken),
    ]);

    // No X-Hub-Signature-256 header — this must still succeed: F1 keeps
    // app_secret entirely optional, the token closes the routing-guess gap
    // independently of whether signature verification is also configured.
    test()->postJson(route('api.webhook.whatsapp.token', [$tenant->tenant_code, $plaintextToken]), signedWhatsappPayload())
        ->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('a source with both a webhook token and a configured app_secret still enforces signature verification on every route shape', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $plaintextToken = Str::random(40);

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '123', 'access_token' => 'x', 'verify_token' => 'y', 'app_secret' => 'wa-app-secret'],
        'webhook_token_hash' => hash('sha256', $plaintextToken),
    ]);

    $payload = signedWhatsappPayload();

    // Correct token, but no signature — still rejected, exactly like the
    // existing "app secret configured + missing signature" case above.
    test()->postJson(route('api.webhook.whatsapp.token', [$tenant->tenant_code, $plaintextToken]), $payload)
        ->assertForbidden();

    // Correct token AND a valid signature — succeeds.
    test()->postJson(
        route('api.webhook.whatsapp.token', [$tenant->tenant_code, $plaintextToken]),
        $payload,
        ['X-Hub-Signature-256' => metaSignatureHeader($payload, 'wa-app-secret')],
    )->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('generating a webhook token from the Platform Lead Sources UI never persists the plaintext, only its SHA-256 hash', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '123', 'access_token' => 'x', 'verify_token' => 'y'],
    ]);

    $component = Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('generateWebhookToken', $source->id);

    $plaintextToken = $component->get('revealedToken');

    expect($plaintextToken)->not->toBeEmpty();

    $source->refresh();

    expect($source->webhook_token_hash)
        ->not->toBe($plaintextToken)
        ->toBe(hash('sha256', $plaintextToken))
        ->toHaveLength(64);
});

test('an instagram webhook with a configured app secret and a valid signature is processed', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Instagram,
        'config' => ['auth_mode' => 'facebook', 'instagram_account_id' => '456', 'access_token' => 'x', 'verify_token' => 'y', 'app_secret' => 'ig-app-secret'],
    ]);

    $payload = [
        'entry' => [[
            'messaging' => [[
                'sender' => ['id' => 'igsid-1'],
                'message' => ['text' => 'Hi, is this available?'],
            ]],
        ]],
    ];

    $this->postJson(
        route('api.webhook.instagram', $tenant->tenant_code),
        $payload,
        ['X-Hub-Signature-256' => metaSignatureHeader($payload, 'ig-app-secret')],
    )->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(1);
});
