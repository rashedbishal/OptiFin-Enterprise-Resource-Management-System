<?php

use App\Enums\LeadSourceType;
use App\Models\Lead;
use App\Models\LeadSource;
use Illuminate\Contracts\Cache\Lock as LockContract;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * @param  array<string, mixed>  $extraValue
 */
function leadgenPayload(string $leadgenId, string $pageId = '1087258341146975', ?string $formId = 'form-1', array $extraValue = []): array
{
    return [
        'object' => 'page',
        'entry' => [[
            'id' => $pageId,
            'changes' => [[
                'field' => 'leadgen',
                'value' => array_merge(array_filter([
                    'leadgen_id' => $leadgenId,
                    'page_id' => $pageId,
                    'form_id' => $formId,
                    'created_time' => now()->timestamp,
                ]), $extraValue),
            ]],
        ]],
    ];
}

test('a facebook lead ad webhook fetches the submitted fields and creates a lead', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookLeadAds,
        'config' => ['page_id' => '1087258341146975', 'page_access_token' => 'page-token', 'verify_token' => 'secret'],
    ]);

    Http::fake([
        'graph.facebook.com/v20.0/leadgen-1*' => Http::response([
            'field_data' => [
                ['name' => 'full_name', 'values' => ['Rahim Uddin']],
                ['name' => 'email', 'values' => ['rahim@example.com']],
                ['name' => 'phone_number', 'values' => ['+8801700000000']],
            ],
            'ad_id' => 'ad-1',
        ]),
        'graph.facebook.com/v20.0/form-1*' => Http::response(['name' => 'Spring Promo Form']),
    ]);

    $this->postJson(route('api.webhook.facebook', $tenant->tenant_code), leadgenPayload('leadgen-1'))->assertOk();

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    expect($lead->name)->toBe('Rahim Uddin');
    expect($lead->email)->toBe('rahim@example.com');
    expect($lead->phone)->toBe('+8801700000000');
    expect($lead->source_data['leadgen_id'])->toBe('leadgen-1');
    expect($lead->source_data['form_id'])->toBe('form-1');
    expect($lead->source_data['form_name'])->toBe('Spring Promo Form');
    expect($lead->source_data['ad_id'])->toBe('ad-1');
    expect($lead->source_data['fetch_failed'])->toBeFalse();
});

test('a lead ad name falls back to first+last name, then email, when there is no full_name field', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookLeadAds,
        'config' => ['page_id' => '1087258341146975', 'page_access_token' => 'page-token', 'verify_token' => 'secret'],
    ]);

    Http::fake([
        'graph.facebook.com/v20.0/leadgen-2*' => Http::response([
            'field_data' => [
                ['name' => 'first_name', 'values' => ['Karim']],
                ['name' => 'last_name', 'values' => ['Ahmed']],
                ['name' => 'email', 'values' => ['karim@example.com']],
            ],
        ]),
    ]);

    $this->postJson(route('api.webhook.facebook', $tenant->tenant_code), leadgenPayload('leadgen-2', formId: null))->assertOk();

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    expect($lead->name)->toBe('Karim Ahmed');
});

test('a permission error (code 100 subcode 33) falls back to a minimal lead keyed only by leadgen_id', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookLeadAds,
        'config' => ['page_id' => '1087258341146975', 'page_access_token' => 'page-token', 'verify_token' => 'secret'],
    ]);

    Http::fake(['graph.facebook.com/v20.0/leadgen-perm*' => Http::response([
        'error' => ['message' => 'permission denied', 'code' => 100, 'error_subcode' => 33],
    ], 400)]);

    $this->postJson(route('api.webhook.facebook', $tenant->tenant_code), leadgenPayload('leadgen-perm', formId: null))->assertOk();

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    expect($lead->name)->toBe('Facebook Lead perm');
    expect($lead->email)->toBeNull();
    expect($lead->source_data['leadgen_id'])->toBe('leadgen-perm');
    expect($lead->source_data['fetch_failed'])->toBeTrue();
});

test('a duplicate leadgen webhook delivery is not processed twice', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookLeadAds,
        'config' => ['page_id' => '1087258341146975', 'page_access_token' => 'page-token', 'verify_token' => 'secret'],
    ]);

    Http::fake(['graph.facebook.com/*' => Http::response(['field_data' => [['name' => 'email', 'values' => ['a@example.com']]]])]);

    $payload = leadgenPayload('leadgen-dup', formId: null);

    $this->postJson(route('api.webhook.facebook', $tenant->tenant_code), $payload)->assertOk();
    $this->postJson(route('api.webhook.facebook', $tenant->tenant_code), $payload)->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('a dedupe lock timeout drops the delivery instead of creating a lead, with a distinguishable log entry, and never 500s', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookLeadAds,
        'config' => ['page_id' => '1087258341146975', 'page_access_token' => 'page-token', 'verify_token' => 'secret'],
    ]);

    $realCache = Cache::getFacadeRoot();

    Cache::shouldReceive('lock')->once()->andReturnUsing(function (string $name, int $seconds) use ($realCache): LockContract {
        if (str_contains($name, 'lead-webhook-dedupe:')) {
            $lock = Mockery::mock(LockContract::class);
            $lock->shouldReceive('block')->once()->andThrow(new LockTimeoutException);

            return $lock;
        }

        return $realCache->lock($name, $seconds);
    });

    // Never reached: the lock times out before the callback (and so this
    // Graph fetch) ever runs. Faking nothing here would fail the test hard
    // on an unexpected real HTTP call if the fix regressed to fail OPEN.
    Http::fake(['graph.facebook.com/*' => Http::response(['field_data' => []])]);

    Log::shouldReceive('error')->once()->with(Mockery::pattern('/dedupe lock timed out under contention/'));

    $this->postJson(route('api.webhook.facebook', $tenant->tenant_code), leadgenPayload('leadgen-lock-timeout'))->assertOk();

    // Dropped, not created — and critically, never treated as a duplicate
    // skip (that path logs 'already processed', a different message, and
    // this mock only ever expects the timeout message above).
    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('a leadgen event for a tenant with no facebook lead ads source configured is logged and skipped, not a 500', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $this->postJson(route('api.webhook.facebook', $tenant->tenant_code), leadgenPayload('leadgen-none', formId: null))
        ->assertOk()
        ->assertJson(['status' => 'ok']);

    expect(Lead::count())->toBe(0);
});

test('a lead-ads-only tenant with no messenger source configured can still receive leadgen events', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookLeadAds,
        'config' => ['page_id' => '1087258341146975', 'page_access_token' => 'page-token', 'verify_token' => 'secret'],
    ]);

    Http::fake(['graph.facebook.com/*' => Http::response(['field_data' => [['name' => 'email', 'values' => ['a@example.com']]]])]);

    $this->postJson(route('api.webhook.facebook', $tenant->tenant_code), leadgenPayload('leadgen-only', formId: null))
        ->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('messenger events and leadgen events delivered in the same webhook request are both processed', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '1087258341146975', 'page_access_token' => 'page-token', 'verify_token' => 'secret'],
    ]);
    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookLeadAds,
        'config' => ['page_id' => '1087258341146975', 'page_access_token' => 'page-token', 'verify_token' => 'secret'],
    ]);

    Http::fake(['graph.facebook.com/*' => Http::response(['field_data' => [['name' => 'email', 'values' => ['a@example.com']]]])]);

    $payload = [
        'object' => 'page',
        'entry' => [[
            'id' => '1087258341146975',
            'messaging' => [[
                'sender' => ['id' => 'psid-mixed'],
                'message' => ['text' => 'Hello'],
            ]],
            'changes' => [[
                'field' => 'leadgen',
                'value' => ['leadgen_id' => 'leadgen-mixed', 'page_id' => '1087258341146975'],
            ]],
        ]],
    ];

    $this->postJson(route('api.webhook.facebook', $tenant->tenant_code), $payload)->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(2);
    expect(Lead::where('source_data->facebook_user_id', 'psid-mixed')->exists())->toBeTrue();
    expect(Lead::where('source_data->leadgen_id', 'leadgen-mixed')->exists())->toBeTrue();
});

test('the facebook hub challenge verification succeeds for a lead-ads-only tenant with no messenger source', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookLeadAds,
        'config' => ['page_id' => '1087258341146975', 'page_access_token' => 'page-token', 'verify_token' => 'secret-token'],
    ]);

    $this->get(route('api.webhook.facebook.verify', $tenant->tenant_code).'?hub_mode=subscribe&hub_verify_token=secret-token&hub_challenge=42')
        ->assertOk()
        ->assertSee('42');
});
