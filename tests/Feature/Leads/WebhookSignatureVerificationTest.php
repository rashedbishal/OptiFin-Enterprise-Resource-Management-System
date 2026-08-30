<?php

use App\Enums\LeadSourceType;
use App\Models\Lead;
use App\Models\LeadSource;

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
