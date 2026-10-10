<?php

use App\Actions\Tenant\RecordLeadConsent;
use App\Actions\Tenant\RecordLeadDeletionAudit;
use App\Enums\ConsentSource;
use App\Enums\ConsentStatus;
use App\Enums\ConsentType;
use App\Enums\LeadSourceType;
use App\Livewire\Tenant\Leads\Index;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\LeadConsent;
use App\Models\LeadConsentEvent;
use App\Models\LeadDeletionAudit;
use App\Models\LeadDuplicateLink;
use App\Models\LeadNote;
use App\Models\LeadSource;
use App\Models\TenantUser;
use Livewire\Livewire;

/**
 * DB-1 — App\Actions\Tenant\RecordLeadDeletionAudit is the single shared
 * primitive behind every Lead hard-delete call site. These tests exercise
 * it through the real Livewire entry points (Index::delete()/
 * performBulkDelete(), Show::delete()) rather than calling it directly, so
 * the same-transaction/fail-closed guarantee is verified exactly as a user
 * would trigger it.
 */
test('a single delete creates exactly one LeadDeletionAudit with the correct snapshot fields', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::WebsiteForm]);
    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'source_id' => $source->id,
        'name' => 'Jane Prospect',
        'email' => 'jane@example.com',
        'phone' => '+15551234567',
    ]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('delete', $lead->id);

    expect(LeadDeletionAudit::count())->toBe(1);

    $audit = LeadDeletionAudit::first();

    expect($audit->tenant_id)->toBe($tenant->id);
    expect($audit->deleted_by)->toBe($clientAdmin->id);
    expect($audit->lead_identifier)->toBe($lead->identifier);
    expect($audit->lead_name)->toBe('Jane Prospect');
    expect($audit->lead_email)->toBe('jane@example.com');
    expect($audit->lead_phone)->toBe('+15551234567');
    expect($audit->source_type)->toBe(LeadSourceType::WebsiteForm->value);
    expect($audit->is_bulk)->toBeFalse();
    expect($audit->lead_id)->toBeNull();
});

test('the audit snapshot is taken from the Lead exactly as it was immediately before deletion', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Manual]);
    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'source_id' => $source->id,
        'name' => 'Original Name',
        'email' => 'original@example.com',
        'phone' => '+15559998888',
        'status_id' => leadStatus('unqualified')->id,
    ]);
    $preDeleteIdentifier = $lead->identifier;

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->call('confirmDelete')
        ->set('deletePassword', 'password')
        ->call('deleteConfirmed');

    $audit = LeadDeletionAudit::where('lead_identifier', $preDeleteIdentifier)->first();

    expect($audit)->not->toBeNull();
    expect($audit->lead_name)->toBe('Original Name');
    expect($audit->lead_email)->toBe('original@example.com');
    expect($audit->lead_phone)->toBe('+15559998888');
    expect($audit->is_bulk)->toBeFalse();
});

test('bulk delete creates one LeadDeletionAudit per successfully deleted lead with is_bulk true', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::WebsiteForm]);
    $leads = Lead::factory()->count(3)->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->set('selectedLeadIds', $leads->pluck('id')->all())
        ->call('confirmBulkDelete')
        ->set('deletePassword', 'password')
        ->call('deleteLeadConfirmed');

    expect(Lead::whereIn('id', $leads->pluck('id'))->count())->toBe(0);
    expect(LeadDeletionAudit::count())->toBe(3);
    expect(LeadDeletionAudit::where('is_bulk', true)->count())->toBe(3);
    expect(LeadDeletionAudit::whereIn('lead_identifier', $leads->pluck('identifier'))->count())->toBe(3);
});

test('an audit-write failure prevents the lead delete and rolls back the whole per-lead transaction', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::WebsiteForm]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    app(RecordLeadConsent::class)->handle($lead, ConsentType::Privacy, ConsentStatus::Granted, ConsentSource::Manual, $clientAdmin->id);

    app()->bind(RecordLeadDeletionAudit::class, fn () => new class extends RecordLeadDeletionAudit
    {
        protected function createAudit(Lead $lead, ?TenantUser $deletedBy, bool $isBulk): LeadDeletionAudit
        {
            throw new RuntimeException('simulated audit write failure');
        }
    });

    expect(fn () => Livewire::actingAs($clientAdmin)->test(Index::class)->call('completeInitialLoad')->call('delete', $lead->id))
        ->toThrow(RuntimeException::class, 'simulated audit write failure');

    expect(Lead::find($lead->id))->not->toBeNull();
    expect(LeadDeletionAudit::count())->toBe(0);
    expect(LeadConsent::where('lead_id', $lead->id)->exists())->toBeTrue();
});

test('a consent-snapshot failure prevents the lead delete and rolls back the transaction, including the audit row already written in it', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::WebsiteForm]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    app(RecordLeadConsent::class)->handle($lead, ConsentType::Privacy, ConsentStatus::Granted, ConsentSource::Manual, $clientAdmin->id);

    app()->bind(RecordLeadDeletionAudit::class, fn () => new class extends RecordLeadDeletionAudit
    {
        protected function snapshotConsentIdentity(Lead $lead): void
        {
            throw new RuntimeException('simulated consent snapshot failure');
        }
    });

    expect(fn () => Livewire::actingAs($clientAdmin)->test(Index::class)->call('completeInitialLoad')->call('delete', $lead->id))
        ->toThrow(RuntimeException::class, 'simulated consent snapshot failure');

    expect(Lead::find($lead->id))->not->toBeNull();
    // The audit row this same call already wrote must not survive either —
    // it was written inside the same transaction as the later, failing
    // consent snapshot step, so the whole transaction (audit included)
    // rolls back together.
    expect(LeadDeletionAudit::count())->toBe(0);

    $consent = LeadConsent::where('lead_id', $lead->id)->first();
    expect($consent)->not->toBeNull();
    expect($consent->lead_identifier)->toBeNull();
});

test('two separately deleted leads can both have detached lead_consents rows with lead_id null and the same consent_type, with no unique constraint violation', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::WebsiteForm]);
    $leadOne = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $leadTwo = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    app(RecordLeadConsent::class)->handle($leadOne, ConsentType::Privacy, ConsentStatus::Granted, ConsentSource::Manual, $clientAdmin->id);
    app(RecordLeadConsent::class)->handle($leadTwo, ConsentType::Privacy, ConsentStatus::Granted, ConsentSource::Manual, $clientAdmin->id);

    $component = Livewire::actingAs($clientAdmin)->test(Index::class)->call('completeInitialLoad');
    $component->call('delete', $leadOne->id);
    $component->call('delete', $leadTwo->id);

    expect(LeadConsent::where('lead_id', null)->where('consent_type', ConsentType::Privacy)->count())->toBe(2);
});

test('a detached consent row remains directly queryable and identifiable by its snapshot fields after its lead is deleted', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::WebsiteForm]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'name' => 'Detached Person']);
    $identifier = $lead->identifier;

    app(RecordLeadConsent::class)->handle($lead, ConsentType::Marketing, ConsentStatus::Withdrawn, ConsentSource::Manual, $clientAdmin->id);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('delete', $lead->id);

    $consent = LeadConsent::where('lead_identifier', $identifier)->first();
    $event = LeadConsentEvent::where('lead_identifier', $identifier)->first();

    expect($consent)->not->toBeNull();
    expect($consent->lead_id)->toBeNull();
    expect($consent->lead_name)->toBe('Detached Person');

    expect($event)->not->toBeNull();
    expect($event->lead_id)->toBeNull();
    expect($event->lead_name)->toBe('Detached Person');
});

test('deleting a lead still cascade-deletes lead_duplicate_links exactly as before', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::WebsiteForm]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $other = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    $link = LeadDuplicateLink::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'duplicate_lead_id' => $other->id,
    ]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('delete', $lead->id);

    expect(LeadDuplicateLink::find($link->id))->toBeNull();
});

test('deleting a lead still cascade-deletes notes and deals exactly as before', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::WebsiteForm]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    $note = LeadNote::factory()->create(['lead_id' => $lead->id, 'tenant_user_id' => $clientAdmin->id]);
    $deal = Deal::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('delete', $lead->id);

    expect(LeadNote::find($note->id))->toBeNull();
    expect(Deal::find($deal->id))->toBeNull();
});

test('the audit cascade_summary records accurate counts of what was cascade-destroyed', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::WebsiteForm]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    LeadNote::factory()->count(2)->create(['lead_id' => $lead->id, 'tenant_user_id' => $clientAdmin->id]);
    Deal::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('delete', $lead->id);

    $summary = LeadDeletionAudit::first()->cascade_summary;

    expect($summary['notes'])->toBe(2);
    expect($summary['deals'])->toBe(1);
});

// -----------------------------------------------------------------------
// Concurrency follow-up — RecordLeadDeletionAudit::handle()'s lockForUpdate()
// re-fetch and its nullable return, closing the race where two concurrent
// delete requests for the same Lead could otherwise both write a durable
// audit row for what is physically only one deletion. See
// tests/Feature/Postgres/LeadDeletionAuditConcurrencyTest.php for the real,
// cross-connection Postgres exclusivity proof — these tests cover the
// deterministic "row already gone" branch, which SQLite can exercise fine
// (only genuine cross-session blocking is unprovable under SQLite).
// -----------------------------------------------------------------------

test('handle() returns a LeadDeletionAudit when the Lead still exists', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::WebsiteForm]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    $result = app(RecordLeadDeletionAudit::class)->handle($lead, null, isBulk: false);

    expect($result)->toBeInstanceOf(LeadDeletionAudit::class);
    expect(LeadDeletionAudit::count())->toBe(1);
});

test('handle() returns null and writes no audit when the Lead row is already gone', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::WebsiteForm]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    // Simulates a concurrent transaction having already won: the Lead row
    // is physically gone, but this test still holds a stale, pre-race
    // in-memory $lead instance — exactly the shape every real call site
    // passes into handle().
    $lead->delete();

    $result = app(RecordLeadDeletionAudit::class)->handle($lead, null, isBulk: false);

    expect($result)->toBeNull();
    expect(LeadDeletionAudit::count())->toBe(0);
});

test('a missing Lead means the consent-identity snapshot step never runs', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::WebsiteForm]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    app(RecordLeadConsent::class)->handle($lead, ConsentType::Privacy, ConsentStatus::Granted, ConsentSource::Manual, $clientAdmin->id);
    $lead->delete();

    // The bare delete() above already nulled lead_id (nullOnDelete) but,
    // critically, never touched lead_identifier/lead_name — only
    // RecordLeadDeletionAudit does that, and it must not run here.
    expect(LeadConsent::first()->lead_identifier)->toBeNull();

    app(RecordLeadDeletionAudit::class)->handle($lead, $clientAdmin, isBulk: false);

    expect(LeadConsent::first()->lead_identifier)->toBeNull();
    expect(LeadConsent::first()->lead_id)->toBeNull();
});

test('when the audit action reports the Lead already gone, Index::delete() does not attempt to delete it again', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::WebsiteForm]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    app()->bind(RecordLeadDeletionAudit::class, fn () => new class extends RecordLeadDeletionAudit
    {
        public function handle(Lead $lead, ?TenantUser $deletedBy, bool $isBulk): ?LeadDeletionAudit
        {
            return null;
        }
    });

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('delete', $lead->id);

    expect(Lead::find($lead->id))->not->toBeNull();
    expect(LeadDeletionAudit::count())->toBe(0);
});

test('when the audit action reports the Lead already gone, Show::delete() does not attempt to delete it again', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::WebsiteForm]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'status_id' => leadStatus('unqualified')->id]);

    app()->bind(RecordLeadDeletionAudit::class, fn () => new class extends RecordLeadDeletionAudit
    {
        public function handle(Lead $lead, ?TenantUser $deletedBy, bool $isBulk): ?LeadDeletionAudit
        {
            return null;
        }
    });

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->call('confirmDelete')
        ->set('deletePassword', 'password')
        ->call('deleteConfirmed');

    expect(Lead::find($lead->id))->not->toBeNull();
    expect(LeadDeletionAudit::count())->toBe(0);
});

test('when the audit action reports a Lead already gone, performBulkDelete() does not attempt to delete it again', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::WebsiteForm]);
    $leads = Lead::factory()->count(2)->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    app()->bind(RecordLeadDeletionAudit::class, fn () => new class extends RecordLeadDeletionAudit
    {
        public function handle(Lead $lead, ?TenantUser $deletedBy, bool $isBulk): ?LeadDeletionAudit
        {
            return null;
        }
    });

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->set('selectedLeadIds', $leads->pluck('id')->all())
        ->call('confirmBulkDelete')
        ->set('deletePassword', 'password')
        ->call('deleteLeadConfirmed');

    expect(Lead::whereIn('id', $leads->pluck('id'))->count())->toBe(2);
    expect(LeadDeletionAudit::count())->toBe(0);
});
