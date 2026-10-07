<?php

use App\Actions\Tenant\RecordLeadConsent;
use App\Enums\ConsentSource;
use App\Enums\ConsentStatus;
use App\Enums\ConsentType;
use App\Enums\LeadSourceType;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Services\OutboundEligibilityService;

test('a conversation within the messaging window with valid credentials is eligible', function () {
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

    $result = app(OutboundEligibilityService::class)->evaluate($conversation);

    expect($result->eligible)->toBeTrue();
});

test('a conversation with an expired messaging window is ineligible', function () {
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

    $result = app(OutboundEligibilityService::class)->evaluate($conversation);

    expect($result->eligible)->toBeFalse();
    expect($result->reason)->toContain('window has expired');
});

test('a conversation that never received an inbound message is ineligible', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '1', 'access_token' => 'token'],
    ]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $conversation = Conversation::factory()->neverContacted()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'lead_source_id' => $source->id,
    ]);

    $result = app(OutboundEligibilityService::class)->evaluate($conversation);

    expect($result->eligible)->toBeFalse();
});

test('a merged-away lead is ineligible', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '1', 'access_token' => 'token'],
    ]);
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'merged_into_lead_id' => $survivor->id]);
    $conversation = Conversation::factory()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'lead_source_id' => $source->id,
    ]);

    $result = app(OutboundEligibilityService::class)->evaluate($conversation);

    expect($result->eligible)->toBeFalse();
    expect($result->reason)->toContain('merged');
});

test('withdrawn privacy consent blocks outbound sending regardless of window', function () {
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

    app(RecordLeadConsent::class)->handle($lead, ConsentType::Privacy, ConsentStatus::Withdrawn, ConsentSource::Manual, $clientAdmin->id);

    $result = app(OutboundEligibilityService::class)->evaluate($conversation);

    expect($result->eligible)->toBeFalse();
    expect($result->reason)->toContain('Privacy consent');
});

test('withdrawn marketing consent alone does not block a within-window service reply', function () {
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

    app(RecordLeadConsent::class)->handle($lead, ConsentType::Marketing, ConsentStatus::Withdrawn, ConsentSource::Manual, $clientAdmin->id);

    $result = app(OutboundEligibilityService::class)->evaluate($conversation);

    expect($result->eligible)->toBeTrue();
});

test('missing whatsapp credentials make a conversation ineligible', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Whatsapp, 'config' => []]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $conversation = Conversation::factory()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'lead_source_id' => $source->id, 'last_inbound_at' => now(),
    ]);

    $result = app(OutboundEligibilityService::class)->evaluate($conversation);

    expect($result->eligible)->toBeFalse();
    expect($result->reason)->toContain("isn't configured");
});

test('evaluateForTemplate is eligible even with an expired messaging window', function () {
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

    $result = app(OutboundEligibilityService::class)->evaluateForTemplate($conversation);

    expect($result->eligible)->toBeTrue();
});

test('evaluateForTemplate is still ineligible for a merged-away lead', function () {
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

    $result = app(OutboundEligibilityService::class)->evaluateForTemplate($conversation);

    expect($result->eligible)->toBeFalse();
    expect($result->reason)->toContain('merged');
});

test('evaluateForTemplate is still ineligible when withdrawn privacy consent blocks outbound sending', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '1', 'access_token' => 'token'],
    ]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $conversation = Conversation::factory()->windowExpired()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'lead_source_id' => $source->id,
    ]);

    app(RecordLeadConsent::class)->handle($lead, ConsentType::Privacy, ConsentStatus::Withdrawn, ConsentSource::Manual, $clientAdmin->id);

    $result = app(OutboundEligibilityService::class)->evaluateForTemplate($conversation);

    expect($result->eligible)->toBeFalse();
    expect($result->reason)->toContain('Privacy consent');
});

test('evaluateForTemplate is still ineligible when whatsapp credentials are missing', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Whatsapp, 'config' => []]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $conversation = Conversation::factory()->windowExpired()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'lead_source_id' => $source->id,
    ]);

    $result = app(OutboundEligibilityService::class)->evaluateForTemplate($conversation);

    expect($result->eligible)->toBeFalse();
    expect($result->reason)->toContain("isn't configured");
});

test('an inactive lead source makes a conversation ineligible', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'is_active' => false,
        'config' => ['phone_number_id' => '1', 'access_token' => 'token'],
    ]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $conversation = Conversation::factory()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'lead_source_id' => $source->id, 'last_inbound_at' => now(),
    ]);

    $result = app(OutboundEligibilityService::class)->evaluate($conversation);

    expect($result->eligible)->toBeFalse();
    expect($result->reason)->toContain('disabled');
});
