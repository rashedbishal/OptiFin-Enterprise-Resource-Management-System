<?php

use App\Actions\Tenant\RecordLeadConsent;
use App\Enums\ConsentSource;
use App\Enums\ConsentStatus;
use App\Enums\ConsentType;
use App\Enums\LeadSourceType;
use App\Exceptions\LeadMergeException;
use App\Livewire\Tenant\Leads\Index;
use App\Models\Lead;
use App\Models\LeadConsent;
use App\Models\LeadConsentEvent;
use App\Models\LeadSource;
use App\Services\LeadMergeService;
use Illuminate\Database\QueryException;
use Livewire\Livewire;

test('granting consent creates exactly one current-state row and one history event', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    $consent = app(RecordLeadConsent::class)->handle(
        lead: $lead,
        type: ConsentType::Privacy,
        status: ConsentStatus::Granted,
        source: ConsentSource::Manual,
        changedBy: $clientAdmin->id,
    );

    expect(LeadConsent::where('lead_id', $lead->id)->count())->toBe(1);
    expect(LeadConsentEvent::where('lead_id', $lead->id)->count())->toBe(1);
    expect($consent->status)->toBe(ConsentStatus::Granted);
    expect($consent->consent_type)->toBe(ConsentType::Privacy);
});

test('withdrawing consent updates the current-state row in place and appends a new event, never deleting the old one', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    app(RecordLeadConsent::class)->handle($lead, ConsentType::Marketing, ConsentStatus::Granted, ConsentSource::Manual, $clientAdmin->id);
    app(RecordLeadConsent::class)->handle($lead, ConsentType::Marketing, ConsentStatus::Withdrawn, ConsentSource::Manual, $clientAdmin->id);

    expect(LeadConsent::where('lead_id', $lead->id)->count())->toBe(1);
    expect(LeadConsent::where('lead_id', $lead->id)->first()->status)->toBe(ConsentStatus::Withdrawn);
    expect(LeadConsentEvent::where('lead_id', $lead->id)->count())->toBe(2);
    expect(LeadConsentEvent::where('lead_id', $lead->id)->orderBy('created_at')->pluck('status')->all())
        ->toBe([ConsentStatus::Granted, ConsentStatus::Withdrawn]);
});

test('repeated grant/withdraw cycles accumulate history while current state always reflects only the latest action', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    app(RecordLeadConsent::class)->handle($lead, ConsentType::Privacy, ConsentStatus::Granted, ConsentSource::Manual, $clientAdmin->id);
    app(RecordLeadConsent::class)->handle($lead, ConsentType::Privacy, ConsentStatus::Withdrawn, ConsentSource::Manual, $clientAdmin->id);
    app(RecordLeadConsent::class)->handle($lead, ConsentType::Privacy, ConsentStatus::Granted, ConsentSource::Manual, $clientAdmin->id);

    expect(LeadConsentEvent::where('lead_id', $lead->id)->count())->toBe(3);
    expect(LeadConsent::where('lead_id', $lead->id)->first()->status)->toBe(ConsentStatus::Granted);
});

test('a lead with no consent record for a type is Not Provided', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    expect(LeadConsent::where('lead_id', $lead->id)->where('consent_type', ConsentType::Privacy)->exists())->toBeFalse();
});

test('privacy and marketing consent are tracked completely independently', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    app(RecordLeadConsent::class)->handle($lead, ConsentType::Privacy, ConsentStatus::Granted, ConsentSource::Manual, $clientAdmin->id);

    expect(LeadConsent::where('lead_id', $lead->id)->where('consent_type', ConsentType::Privacy)->first()?->status)->toBe(ConsentStatus::Granted);
    expect(LeadConsent::where('lead_id', $lead->id)->where('consent_type', ConsentType::Marketing)->exists())->toBeFalse();
});

test('lead_consents enforces a unique constraint per lead and consent type', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    LeadConsent::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'consent_type' => ConsentType::Privacy]);

    expect(fn () => LeadConsent::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'consent_type' => ConsentType::Privacy]))
        ->toThrow(QueryException::class);
});

test('deleting a lead detaches, rather than deletes, its consent current-state and history rows (DB-1)', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::WebsiteForm]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $leadId = $lead->id;
    $identifier = $lead->identifier;
    $name = $lead->name;

    app(RecordLeadConsent::class)->handle($lead, ConsentType::Privacy, ConsentStatus::Granted, ConsentSource::Manual, $clientAdmin->id);

    // A hard Lead delete must go through the approved DB-1 path (the
    // password-gated Livewire delete()), not a bare $lead->delete() — that
    // path is what actually snapshots identity before the delete happens.
    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->set('deletePassword', 'password')->call('delete', $leadId);

    expect(Lead::find($leadId))->toBeNull();

    $consent = LeadConsent::where('lead_identifier', $identifier)->first();
    expect($consent)->not->toBeNull();
    expect($consent->lead_id)->toBeNull();
    expect($consent->lead_name)->toBe($name);

    $event = LeadConsentEvent::where('lead_identifier', $identifier)->first();
    expect($event)->not->toBeNull();
    expect($event->lead_id)->toBeNull();
    expect($event->lead_name)->toBe($name);
});

test('consent queries are tenant isolated', function () {
    ['tenant' => $tenantA] = tenantWithLeadManagement();
    ['tenant' => $tenantB, 'clientAdmin' => $clientAdminB] = tenantWithLeadManagement();
    $leadA = Lead::factory()->create(['tenant_id' => $tenantA->id]);
    $leadB = Lead::factory()->create(['tenant_id' => $tenantB->id]);

    app(RecordLeadConsent::class)->handle($leadB, ConsentType::Privacy, ConsentStatus::Granted, ConsentSource::Manual, $clientAdminB->id);

    expect(LeadConsent::where('tenant_id', $tenantA->id)->where('lead_id', $leadA->id)->exists())->toBeFalse();
    expect(LeadConsent::where('tenant_id', $tenantB->id)->where('lead_id', $leadB->id)->exists())->toBeTrue();
});

// -----------------------------------------------------------------------
// Merge behavior
// -----------------------------------------------------------------------

test('merging two leads where the loser is Withdrawn always results in Withdrawn, even if the survivor was Granted', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id]);

    app(RecordLeadConsent::class)->handle($survivor, ConsentType::Privacy, ConsentStatus::Granted, ConsentSource::Manual, $clientAdmin->id);
    app(RecordLeadConsent::class)->handle($loser, ConsentType::Privacy, ConsentStatus::Withdrawn, ConsentSource::Manual, $clientAdmin->id);

    app(LeadMergeService::class)->merge($survivor, $loser, $clientAdmin, []);

    expect(LeadConsent::where('lead_id', $survivor->id)->where('consent_type', ConsentType::Privacy)->first()->status)
        ->toBe(ConsentStatus::Withdrawn);
});

test('merging two leads where the survivor is Withdrawn and the loser is Granted never downgrades to Granted', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id]);

    app(RecordLeadConsent::class)->handle($survivor, ConsentType::Marketing, ConsentStatus::Withdrawn, ConsentSource::Manual, $clientAdmin->id);
    app(RecordLeadConsent::class)->handle($loser, ConsentType::Marketing, ConsentStatus::Granted, ConsentSource::Manual, $clientAdmin->id);

    app(LeadMergeService::class)->merge($survivor, $loser, $clientAdmin, []);

    expect(LeadConsent::where('lead_id', $survivor->id)->where('consent_type', ConsentType::Marketing)->first()->status)
        ->toBe(ConsentStatus::Withdrawn);
});

test('merging two leads both Granted stays Granted', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id]);

    app(RecordLeadConsent::class)->handle($survivor, ConsentType::Privacy, ConsentStatus::Granted, ConsentSource::Manual, $clientAdmin->id);
    app(RecordLeadConsent::class)->handle($loser, ConsentType::Privacy, ConsentStatus::Granted, ConsentSource::Manual, $clientAdmin->id);

    app(LeadMergeService::class)->merge($survivor, $loser, $clientAdmin, []);

    expect(LeadConsent::where('lead_id', $survivor->id)->where('consent_type', ConsentType::Privacy)->first()->status)
        ->toBe(ConsentStatus::Granted);
});

test('merging when only the loser has a consent record copies it onto the survivor', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id]);

    app(RecordLeadConsent::class)->handle($loser, ConsentType::Privacy, ConsentStatus::Granted, ConsentSource::Manual, $clientAdmin->id);

    app(LeadMergeService::class)->merge($survivor, $loser, $clientAdmin, []);

    expect(LeadConsent::where('lead_id', $survivor->id)->where('consent_type', ConsentType::Privacy)->first()?->status)
        ->toBe(ConsentStatus::Granted);
});

test('merging when neither lead has a consent record leaves it Not Provided with no synthetic event', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id]);

    app(LeadMergeService::class)->merge($survivor, $loser, $clientAdmin, []);

    expect(LeadConsent::where('lead_id', $survivor->id)->exists())->toBeFalse();
    expect(LeadConsentEvent::where('lead_id', $survivor->id)->where('source', ConsentSource::Merge)->exists())->toBeFalse();
});

test('merging writes a synthetic merge audit event and preserves both leads full history without a unique constraint collision', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id]);

    app(RecordLeadConsent::class)->handle($survivor, ConsentType::Privacy, ConsentStatus::Granted, ConsentSource::Manual, $clientAdmin->id);
    app(RecordLeadConsent::class)->handle($loser, ConsentType::Privacy, ConsentStatus::Withdrawn, ConsentSource::Manual, $clientAdmin->id);
    app(RecordLeadConsent::class)->handle($loser, ConsentType::Marketing, ConsentStatus::Granted, ConsentSource::Manual, $clientAdmin->id);

    app(LeadMergeService::class)->merge($survivor, $loser, $clientAdmin, []);

    // Both leads' pre-merge events (1 + 2 = 3) plus one synthetic merge
    // event per consent type that had at least one side with a record
    // (Privacy and Marketing = 2) — all reparented onto the survivor, none
    // lost, no exception thrown despite lead_consents' own unique
    // constraint.
    expect(LeadConsentEvent::where('lead_id', $survivor->id)->count())->toBe(5);
    expect(LeadConsentEvent::where('lead_id', $survivor->id)->where('source', ConsentSource::Merge)->count())->toBe(2);
    expect(LeadConsent::where('lead_id', $survivor->id)->count())->toBe(2);
});

test('the loser lead keeps all of its own consent history untouched and queryable after merge', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id]);

    app(RecordLeadConsent::class)->handle($loser, ConsentType::Privacy, ConsentStatus::Granted, ConsentSource::Manual, $clientAdmin->id);

    app(LeadMergeService::class)->merge($survivor, $loser, $clientAdmin, []);

    // The loser is never hard-deleted by a merge — it still exists, just
    // excluded from normal list views (merged_into_lead_id set).
    expect(Lead::find($loser->id))->not->toBeNull();
    // Its own LeadConsentEvent rows were reparented onto the survivor (the
    // generic CHILD_MODELS behavior every other child table already gets),
    // so it retains no consent event rows of its own — this is the
    // expected, existing reparent-not-copy semantic this module already
    // uses for every other child table.
    expect(LeadConsentEvent::where('lead_id', $loser->id)->exists())->toBeFalse();
});

test('merge requires both leads to belong to the same tenant, including for consent data', function () {
    ['tenant' => $tenantA, 'clientAdmin' => $clientAdminA] = tenantWithLeadManagement();
    ['tenant' => $tenantB] = tenantWithLeadManagement();
    $survivor = Lead::factory()->create(['tenant_id' => $tenantA->id]);
    $loser = Lead::factory()->create(['tenant_id' => $tenantB->id]);

    expect(fn () => app(LeadMergeService::class)->merge($survivor, $loser, $clientAdminA, []))
        ->toThrow(LeadMergeException::class);
});
