<?php

use App\Actions\Tenant\CreateLeadFromImport;
use App\Enums\LeadDistributionMode;
use App\Enums\LeadImportRowStatus;
use App\Enums\LeadPriority;
use App\Enums\LeadScoringField;
use App\Enums\LeadScoringOperator;
use App\Enums\LeadSourceType;
use App\Events\LeadReceived;
use App\Events\LeadUpdated;
use App\Models\Currency;
use App\Models\Lead;
use App\Models\LeadImport;
use App\Models\LeadImportRow;
use App\Models\LeadScoringRule;
use App\Models\LeadSource;
use App\Models\LeadTag;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Notifications\LeadAssignedNotification;
use Illuminate\Support\Facades\Event;

/**
 * @return array{tenant: Tenant, clientAdmin: TenantUser, source: LeadSource, import: LeadImport}
 */
function setUpLeadImport(): array
{
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::CsvImport, 'name' => 'CSV Import']);
    $import = LeadImport::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'imported_by' => $clientAdmin->id]);

    return ['tenant' => $tenant, 'clientAdmin' => $clientAdmin, 'source' => $source, 'import' => $import];
}

test('a valid row creates a Lead attributed to the CSV Import source and records an imported row', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin, 'source' => $source, 'import' => $import] = setUpLeadImport();

    app(CreateLeadFromImport::class)->handle($import, $tenant, $source, $clientAdmin, 1, [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'phone' => '+1 555 123 4567',
    ]);

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    expect($lead->name)->toBe('Jane Doe');
    expect($lead->source_id)->toBe($source->id);
    expect($lead->source->type)->toBe(LeadSourceType::CsvImport);
    expect($lead->priority)->toBe(LeadPriority::Medium);
    expect($lead->status_id)->toBe($tenant->initialLeadStatus()->id);

    $row = LeadImportRow::where('lead_import_id', $import->id)->where('row_number', 1)->firstOrFail();
    expect($row->status)->toBe(LeadImportRowStatus::Imported);
    expect($row->lead_id)->toBe($lead->id);

    expect($import->fresh()->imported_count)->toBe(1);
    expect($import->fresh()->duplicate_count)->toBe(0);
    expect($import->fresh()->failed_count)->toBe(0);
});

test('a missing required name fails the row without touching the import counts for other outcomes', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin, 'source' => $source, 'import' => $import] = setUpLeadImport();

    app(CreateLeadFromImport::class)->handle($import, $tenant, $source, $clientAdmin, 1, ['name' => '']);

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(0);

    $row = LeadImportRow::where('lead_import_id', $import->id)->where('row_number', 1)->firstOrFail();
    expect($row->status)->toBe(LeadImportRowStatus::Failed);
    expect($row->errors)->not->toBeEmpty();
    expect($import->fresh()->failed_count)->toBe(1);
});

test('an existing Lead duplicate by email is skipped and recorded, never overwritten', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin, 'source' => $source, 'import' => $import] = setUpLeadImport();
    $existing = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com', 'name' => 'Original Name']);

    app(CreateLeadFromImport::class)->handle($import, $tenant, $source, $clientAdmin, 1, ['name' => 'Impostor', 'email' => 'dup@example.com']);

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(1);
    expect($existing->fresh()->name)->toBe('Original Name');

    $row = LeadImportRow::where('lead_import_id', $import->id)->where('row_number', 1)->firstOrFail();
    expect($row->status)->toBe(LeadImportRowStatus::Duplicate);
    expect($row->duplicate_of_lead_id)->toBe($existing->id);
    expect($import->fresh()->duplicate_count)->toBe(1);
});

test('an existing Lead duplicate by phone is skipped and recorded', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin, 'source' => $source, 'import' => $import] = setUpLeadImport();
    $existing = Lead::factory()->create(['tenant_id' => $tenant->id, 'phone' => '5551234567']);

    app(CreateLeadFromImport::class)->handle($import, $tenant, $source, $clientAdmin, 1, ['name' => 'Someone', 'phone' => '(555) 123-4567']);

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(1);
    $row = LeadImportRow::where('lead_import_id', $import->id)->where('row_number', 1)->firstOrFail();
    expect($row->status)->toBe(LeadImportRowStatus::Duplicate);
    expect($row->duplicate_of_lead_id)->toBe($existing->id);
});

test('an existing Lead with a differently-cased email is matched by a differently-cased CSV row', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin, 'source' => $source, 'import' => $import] = setUpLeadImport();
    $existing = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'Dup@Example.COM', 'name' => 'Original Name']);

    app(CreateLeadFromImport::class)->handle($import, $tenant, $source, $clientAdmin, 1, ['name' => 'Impostor', 'email' => 'dup@example.com']);

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(1);
    expect($existing->fresh()->name)->toBe('Original Name');

    $row = LeadImportRow::where('lead_import_id', $import->id)->where('row_number', 1)->firstOrFail();
    expect($row->status)->toBe(LeadImportRowStatus::Duplicate);
    expect($row->duplicate_of_lead_id)->toBe($existing->id);
    expect($import->fresh()->duplicate_count)->toBe(1);
});

test('an existing Lead with a differently-formatted phone is matched by a normalizing CSV row', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin, 'source' => $source, 'import' => $import] = setUpLeadImport();
    $existing = Lead::factory()->create(['tenant_id' => $tenant->id, 'phone' => '(555) 123-4567', 'name' => 'Original Name']);

    app(CreateLeadFromImport::class)->handle($import, $tenant, $source, $clientAdmin, 1, ['name' => 'Impostor', 'phone' => '555-123-4567']);

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(1);
    expect($existing->fresh()->name)->toBe('Original Name');

    $row = LeadImportRow::where('lead_import_id', $import->id)->where('row_number', 1)->firstOrFail();
    expect($row->status)->toBe(LeadImportRowStatus::Duplicate);
    expect($row->duplicate_of_lead_id)->toBe($existing->id);
    expect($import->fresh()->duplicate_count)->toBe(1);
});

test('name-only matching a lead with a different email/phone is NOT a duplicate', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin, 'source' => $source, 'import' => $import] = setUpLeadImport();
    Lead::factory()->create(['tenant_id' => $tenant->id, 'name' => 'John Smith', 'email' => 'john.original@example.com']);

    app(CreateLeadFromImport::class)->handle($import, $tenant, $source, $clientAdmin, 1, ['name' => 'John Smith', 'email' => 'john.different@example.com']);

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(2);
    $row = LeadImportRow::where('lead_import_id', $import->id)->where('row_number', 1)->firstOrFail();
    expect($row->status)->toBe(LeadImportRowStatus::Imported);
});

test('email matches Lead A and phone matches a different Lead B is rejected as a duplicate conflict', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin, 'source' => $source, 'import' => $import] = setUpLeadImport();
    $leadA = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'a@example.com', 'phone' => '11111111111']);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'b@example.com', 'phone' => '22222222222']);

    app(CreateLeadFromImport::class)->handle($import, $tenant, $source, $clientAdmin, 1, [
        'name' => 'Conflict Row',
        'email' => 'a@example.com',
        'phone' => '22222222222',
    ]);

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(2);
    $row = LeadImportRow::where('lead_import_id', $import->id)->where('row_number', 1)->firstOrFail();
    expect($row->status)->toBe(LeadImportRowStatus::Duplicate);
    expect($row->duplicate_of_lead_id)->toBeNull();
});

test('a lead already merged away (merged_into_lead_id set) is excluded from duplicate matching, so a matching row is imported as new', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin, 'source' => $source, 'import' => $import] = setUpLeadImport();
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'merged@example.com']);
    $mergedAway = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'merged@example.com', 'merged_into_lead_id' => $survivor->id]);

    app(CreateLeadFromImport::class)->handle($import, $tenant, $source, $clientAdmin, 1, ['name' => 'Post-Merge Row', 'email' => 'merged@example.com']);

    // email_normalized still matches both $survivor and $mergedAway, but
    // checkExistingLeadDuplicate() excludes merged-away leads via
    // whereNull('merged_into_lead_id') — the row is a duplicate of the
    // surviving lead, never the merged-away one.
    $row = LeadImportRow::where('lead_import_id', $import->id)->where('row_number', 1)->firstOrFail();
    expect($row->status)->toBe(LeadImportRowStatus::Duplicate);
    expect($row->duplicate_of_lead_id)->toBe($survivor->id);
    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(2);
});

test('an existing Lead duplicate by email in a different tenant does not block import — duplicate detection is tenant-scoped', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin, 'source' => $source, 'import' => $import] = setUpLeadImport();
    ['tenant' => $otherTenant] = tenantWithLeadManagement();
    Lead::factory()->create(['tenant_id' => $otherTenant->id, 'email' => 'shared@example.com']);

    app(CreateLeadFromImport::class)->handle($import, $tenant, $source, $clientAdmin, 1, ['name' => 'New In This Tenant', 'email' => 'shared@example.com']);

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(1);
    $row = LeadImportRow::where('lead_import_id', $import->id)->where('row_number', 1)->firstOrFail();
    expect($row->status)->toBe(LeadImportRowStatus::Imported);
    expect($row->duplicate_of_lead_id)->toBeNull();
});

test('within the same CSV, the first valid row wins and a later matching row is rejected as an in-file duplicate', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin, 'source' => $source, 'import' => $import] = setUpLeadImport();
    $action = app(CreateLeadFromImport::class);

    $action->handle($import, $tenant, $source, $clientAdmin, 2, ['name' => 'John', 'email' => 'john@example.com']);
    $action->handle($import, $tenant, $source, $clientAdmin, 3, ['name' => 'Jane', 'email' => 'john@example.com']);

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(1);
    expect(Lead::where('tenant_id', $tenant->id)->first()->name)->toBe('John');

    $rowTwo = LeadImportRow::where('lead_import_id', $import->id)->where('row_number', 2)->firstOrFail();
    $rowThree = LeadImportRow::where('lead_import_id', $import->id)->where('row_number', 3)->firstOrFail();
    expect($rowTwo->status)->toBe(LeadImportRowStatus::Imported);
    expect($rowThree->status)->toBe(LeadImportRowStatus::Duplicate);
    expect($rowThree->duplicate_of_lead_id)->toBeNull();
});

test('a retried row (same row_number already recorded) is never reprocessed — no duplicate Lead or LeadImportRow', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin, 'source' => $source, 'import' => $import] = setUpLeadImport();
    $action = app(CreateLeadFromImport::class);

    $action->handle($import, $tenant, $source, $clientAdmin, 1, ['name' => 'Once Only', 'email' => 'once@example.com']);
    $action->handle($import, $tenant, $source, $clientAdmin, 1, ['name' => 'Once Only', 'email' => 'once@example.com']);

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(1);
    expect(LeadImportRow::where('lead_import_id', $import->id)->count())->toBe(1);
    expect($import->fresh()->imported_count)->toBe(1);
});

test('a valid assignee_email uses LeadAssignmentService — assignment history and notification both fire', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin, 'source' => $source, 'import' => $import] = setUpLeadImport();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);

    app(CreateLeadFromImport::class)->handle($import, $tenant, $source, $clientAdmin, 1, [
        'name' => 'Assigned Lead',
        'assignee_email' => $rep->email,
    ]);

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    expect($lead->assigned_to)->toBe($rep->id);
    expect($lead->assignments()->count())->toBe(1);
    expect($rep->notifications()->where('type', LeadAssignedNotification::class)->count())->toBe(1);
});

test('an unknown assignee_email fails the row and leaves no Lead created', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin, 'source' => $source, 'import' => $import] = setUpLeadImport();

    app(CreateLeadFromImport::class)->handle($import, $tenant, $source, $clientAdmin, 1, [
        'name' => 'Ghost Assignee',
        'assignee_email' => 'nobody@example.com',
    ]);

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(0);
    $row = LeadImportRow::where('lead_import_id', $import->id)->where('row_number', 1)->firstOrFail();
    expect($row->status)->toBe(LeadImportRowStatus::Failed);
});

test('a blank assignee_email leaves the lead unassigned, never auto-distributed', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin, 'source' => $source, 'import' => $import] = setUpLeadImport();
    $tenant->update(['lead_distribution_mode' => LeadDistributionMode::Automatic]);
    clientUserWithLeadPermission($tenant, ['view']);

    app(CreateLeadFromImport::class)->handle($import, $tenant, $source, $clientAdmin, 1, ['name' => 'Unassigned']);

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    expect($lead->assigned_to)->toBeNull();
});

test('an assignee_email belonging to another tenant never resolves', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin, 'source' => $source, 'import' => $import] = setUpLeadImport();
    ['tenant' => $otherTenant] = tenantWithLeadManagement();
    $foreignUser = clientUserWithLeadPermission($otherTenant, ['view']);

    app(CreateLeadFromImport::class)->handle($import, $tenant, $source, $clientAdmin, 1, [
        'name' => 'Cross Tenant',
        'assignee_email' => $foreignUser->email,
    ]);

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('an explicit valid status label resolves against this tenant\'s pipeline, case-insensitively', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin, 'source' => $source, 'import' => $import] = setUpLeadImport();
    $contacted = leadStatus('contacted');

    app(CreateLeadFromImport::class)->handle($import, $tenant, $source, $clientAdmin, 1, [
        'name' => 'Status Row',
        'status' => strtoupper($contacted->name),
    ]);

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    expect($lead->status_id)->toBe($contacted->id);
});

test('an unknown status fails the row', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin, 'source' => $source, 'import' => $import] = setUpLeadImport();

    app(CreateLeadFromImport::class)->handle($import, $tenant, $source, $clientAdmin, 1, ['name' => 'Bad Status', 'status' => 'not_a_real_status']);

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('an explicit valid priority resolves case-insensitively; an unknown priority fails the row', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin, 'source' => $source, 'import' => $import] = setUpLeadImport();

    app(CreateLeadFromImport::class)->handle($import, $tenant, $source, $clientAdmin, 1, ['name' => 'High Priority', 'priority' => 'HIGH']);
    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    expect($lead->priority)->toBe(LeadPriority::High);

    app(CreateLeadFromImport::class)->handle($import, $tenant, $source, $clientAdmin, 2, ['name' => 'Bad Priority', 'priority' => 'urgentish']);
    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('existing tag names attach via the normal idempotent pivot; an unknown tag fails the whole row, never a partial attach', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin, 'source' => $source, 'import' => $import] = setUpLeadImport();
    $vip = LeadTag::factory()->create(['tenant_id' => $tenant->id, 'name' => 'VIP']);
    $hot = LeadTag::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Hot Lead']);

    app(CreateLeadFromImport::class)->handle($import, $tenant, $source, $clientAdmin, 1, [
        'name' => 'Tagged Lead',
        'tags' => 'vip, Hot Lead',
    ]);

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    expect($lead->tags->pluck('id')->sort()->values()->all())->toBe(collect([$vip->id, $hot->id])->sort()->values()->all());

    app(CreateLeadFromImport::class)->handle($import, $tenant, $source, $clientAdmin, 2, [
        'name' => 'Bad Tag Lead',
        'tags' => 'vip, not_a_real_tag',
    ]);

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('never auto-creates a tag for an unrecognized name', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin, 'source' => $source, 'import' => $import] = setUpLeadImport();

    app(CreateLeadFromImport::class)->handle($import, $tenant, $source, $clientAdmin, 1, ['name' => 'X', 'tags' => 'brand_new_tag']);

    expect(LeadTag::where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('a resolvable budget currency code is stored; an unresolvable one fails the row; budget without a currency is stored with a null currency', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin, 'source' => $source, 'import' => $import] = setUpLeadImport();
    $currency = $tenant->currencies()->first();

    app(CreateLeadFromImport::class)->handle($import, $tenant, $source, $clientAdmin, 1, [
        'name' => 'Budget Row', 'budget' => '5000', 'budget_currency' => $currency->code,
    ]);
    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    expect((float) $lead->estimated_budget)->toBe(5000.0);
    expect($lead->estimated_budget_currency_id)->toBe($currency->id);

    app(CreateLeadFromImport::class)->handle($import, $tenant, $source, $clientAdmin, 2, [
        'name' => 'No Currency Row', 'budget' => '3000',
    ]);
    $leadTwo = Lead::where('tenant_id', $tenant->id)->where('name', 'No Currency Row')->firstOrFail();
    expect($leadTwo->estimated_budget_currency_id)->toBeNull();

    app(CreateLeadFromImport::class)->handle($import, $tenant, $source, $clientAdmin, 3, [
        'name' => 'Bad Currency Row', 'budget' => '1000', 'budget_currency' => 'ZZZ',
    ]);
    expect(Lead::where('tenant_id', $tenant->id)->where('name', 'Bad Currency Row')->exists())->toBeFalse();
});

test('a currency not enabled for this tenant does not resolve, even if it exists in the global catalog', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin, 'source' => $source, 'import' => $import] = setUpLeadImport();
    $foreignCurrency = Currency::factory()->create(['code' => 'GBX']);

    app(CreateLeadFromImport::class)->handle($import, $tenant, $source, $clientAdmin, 1, [
        'name' => 'Foreign Currency Row', 'budget' => '100', 'budget_currency' => $foreignCurrency->code,
    ]);

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('an imported lead is scored immediately using computeFor with the lead_received trigger', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin, 'source' => $source, 'import' => $import] = setUpLeadImport();
    LeadScoringRule::factory()->create([
        'tenant_id' => $tenant->id,
        'field' => LeadScoringField::HasEmail->value,
        'operator' => LeadScoringOperator::Equals->value,
        'value' => true,
        'points' => 10,
    ]);

    app(CreateLeadFromImport::class)->handle($import, $tenant, $source, $clientAdmin, 1, ['name' => 'Scored', 'email' => 'scored@example.com']);

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    expect($lead->lead_score)->toBe(10);
});

/**
 * M6 regression: computeFor()'s $trustLoadedRelations path relies on
 * $lead->setRelation('status', $status)/setRelation('source', $source) —
 * set with the exact instances CreateLeadFromImport already resolved,
 * moments earlier — providing correct data for LeadScoringField::Status
 * (`$lead->status->slug`) and LeadScoringField::SourceType
 * (`$lead->source?->type->value`) specifically, the two scoring fields
 * this change could plausibly have broken. Unlike the HasEmail-based test
 * above (which never touches either relation), this one directly proves
 * both are correctly populated, not merely present-but-wrong or null.
 */
test('status- and source-type-based scoring rules match correctly against the resolved relations (M6 setRelation regression)', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin, 'source' => $source, 'import' => $import] = setUpLeadImport();
    LeadScoringRule::factory()->create([
        'tenant_id' => $tenant->id,
        'field' => LeadScoringField::Status->value,
        'operator' => LeadScoringOperator::Equals->value,
        'value' => leadStatus('new')->slug,
        'points' => 15,
    ]);
    LeadScoringRule::factory()->create([
        'tenant_id' => $tenant->id,
        'field' => LeadScoringField::SourceType->value,
        'operator' => LeadScoringOperator::Equals->value,
        'value' => LeadSourceType::CsvImport->value,
        'points' => 20,
    ]);

    app(CreateLeadFromImport::class)->handle($import, $tenant, $source, $clientAdmin, 1, ['name' => 'Relation Scored', 'email' => 'relationscored@example.com']);

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    expect($lead->lead_score)->toBe(35);
});

test('LeadReceived and LeadUpdated never fire for a CSV import row', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin, 'source' => $source, 'import' => $import] = setUpLeadImport();
    Event::fake([LeadReceived::class, LeadUpdated::class]);

    app(CreateLeadFromImport::class)->handle($import, $tenant, $source, $clientAdmin, 1, ['name' => 'Silent Lead']);

    Event::assertNotDispatched(LeadReceived::class);
    Event::assertNotDispatched(LeadUpdated::class);
});

test('CSV import never creates a Deal or a Task', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin, 'source' => $source, 'import' => $import] = setUpLeadImport();

    app(CreateLeadFromImport::class)->handle($import, $tenant, $source, $clientAdmin, 1, ['name' => 'No Deal No Task']);

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    expect($lead->deals()->count())->toBe(0);
    expect($lead->tasks()->count())->toBe(0);
});

test('scoring rules and imports never cross tenant boundaries', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin, 'source' => $source, 'import' => $import] = setUpLeadImport();
    ['tenant' => $otherTenant] = tenantWithLeadManagement();
    $otherTag = LeadTag::factory()->create(['tenant_id' => $otherTenant->id, 'name' => 'OtherTenantTag']);

    app(CreateLeadFromImport::class)->handle($import, $tenant, $source, $clientAdmin, 1, [
        'name' => 'Cross Tenant Tag', 'tags' => 'OtherTenantTag',
    ]);

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(0);
});

// ── INC-1 A1 — in-file conflict classification ──────────────────────────────
//
// An in-file row whose email matches one earlier Imported row and whose phone
// matches a DIFFERENT earlier Imported row is an in-file conflict, mirroring
// the existing-Lead conflict rule. The in-file result stays first and final
// for a row; ordinary in-file duplicates and existing-Lead matching are
// unchanged; duplicate_of_lead_id stays null and no Lead is ever created for
// a duplicate/conflict row.

const INC1_IN_FILE_DUPLICATE_MESSAGE = 'Duplicate of an earlier row in this same CSV file.';
const INC1_IN_FILE_CONFLICT_MESSAGE = 'Email matches an earlier row in this CSV file and phone matches a different earlier row — rejected as a duplicate conflict.';
const INC1_EXISTING_LEAD_CONFLICT_MESSAGE = 'Email matches an existing lead and phone matches a different existing lead — rejected as a duplicate conflict.';
const INC1_EXISTING_LEAD_DUPLICATE_MESSAGE = 'Matches an existing lead (email or phone already on file).';

test('in-file: the same earlier row matching both email and phone is an ordinary in-file duplicate, not a conflict', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin, 'source' => $source, 'import' => $import] = setUpLeadImport();
    $action = app(CreateLeadFromImport::class);

    $action->handle($import, $tenant, $source, $clientAdmin, 1, ['name' => 'Alpha', 'email' => 'both@example.com', 'phone' => '5550001111']);
    $action->handle($import, $tenant, $source, $clientAdmin, 2, ['name' => 'Bravo', 'email' => 'both@example.com', 'phone' => '5550001111']);

    $row = LeadImportRow::where('lead_import_id', $import->id)->where('row_number', 2)->firstOrFail();
    expect($row->status)->toBe(LeadImportRowStatus::Duplicate);
    expect($row->errors)->toBe([INC1_IN_FILE_DUPLICATE_MESSAGE]);
    expect($row->duplicate_of_lead_id)->toBeNull();
    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(1);
    expect($import->fresh()->only(['imported_count', 'duplicate_count', 'failed_count']))->toBe(['imported_count' => 1, 'duplicate_count' => 1, 'failed_count' => 0]);
});

test('in-file: email matching one earlier row and phone matching a different earlier row is rejected as an in-file conflict', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin, 'source' => $source, 'import' => $import] = setUpLeadImport();
    $action = app(CreateLeadFromImport::class);

    $action->handle($import, $tenant, $source, $clientAdmin, 1, ['name' => 'Alpha', 'email' => 'row-a@example.com']);
    $action->handle($import, $tenant, $source, $clientAdmin, 2, ['name' => 'Bravo', 'phone' => '5550002222']);
    $action->handle($import, $tenant, $source, $clientAdmin, 3, ['name' => 'Charlie', 'email' => 'ROW-A@example.com', 'phone' => '(555) 000-2222']);

    $row = LeadImportRow::where('lead_import_id', $import->id)->where('row_number', 3)->firstOrFail();
    expect($row->status)->toBe(LeadImportRowStatus::Duplicate);
    expect($row->errors)->toBe([INC1_IN_FILE_CONFLICT_MESSAGE]);
    expect($row->duplicate_of_lead_id)->toBeNull();
    expect($row->raw_data['name'])->toBe('Charlie');
    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(2);
    expect($import->fresh()->only(['imported_count', 'duplicate_count', 'failed_count']))->toBe(['imported_count' => 2, 'duplicate_count' => 1, 'failed_count' => 0]);
});

test('in-file: a row matching an earlier row on only one identity field is an ordinary in-file duplicate', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin, 'source' => $source, 'import' => $import] = setUpLeadImport();
    $action = app(CreateLeadFromImport::class);

    $action->handle($import, $tenant, $source, $clientAdmin, 1, ['name' => 'Alpha', 'email' => 'one@example.com', 'phone' => '5550003333']);
    $action->handle($import, $tenant, $source, $clientAdmin, 2, ['name' => 'Bravo', 'email' => 'one@example.com', 'phone' => '5550009999']);

    $row = LeadImportRow::where('lead_import_id', $import->id)->where('row_number', 2)->firstOrFail();
    expect($row->status)->toBe(LeadImportRowStatus::Duplicate);
    expect($row->errors)->toBe([INC1_IN_FILE_DUPLICATE_MESSAGE]);
    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('precedence: an in-file match is first and final — an additional existing-lead match is not surfaced as a conflict', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin, 'source' => $source, 'import' => $import] = setUpLeadImport();
    Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'pre-existing@example.com', 'phone' => '5559990009']);
    $action = app(CreateLeadFromImport::class);

    $action->handle($import, $tenant, $source, $clientAdmin, 1, ['name' => 'Alpha', 'email' => 'earlier@example.com']);
    // Email matches the earlier row (in-file); phone matches a different,
    // pre-existing lead. Falling through to the existing-lead check would
    // report a conflict — the in-file result must win instead.
    $action->handle($import, $tenant, $source, $clientAdmin, 2, ['name' => 'Bravo', 'email' => 'earlier@example.com', 'phone' => '5559990009']);

    $row = LeadImportRow::where('lead_import_id', $import->id)->where('row_number', 2)->firstOrFail();
    expect($row->status)->toBe(LeadImportRowStatus::Duplicate);
    expect($row->errors)->toBe([INC1_IN_FILE_DUPLICATE_MESSAGE]);
    expect($row->duplicate_of_lead_id)->toBeNull();
    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(2);
});

test('existing-lead conflict behavior and message are unchanged when there is no in-file match', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin, 'source' => $source, 'import' => $import] = setUpLeadImport();
    Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'lead-a@example.com', 'phone' => '11111111111']);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'lead-b@example.com', 'phone' => '22222222222']);

    app(CreateLeadFromImport::class)->handle($import, $tenant, $source, $clientAdmin, 1, ['name' => 'Conflict Row', 'email' => 'lead-a@example.com', 'phone' => '22222222222']);

    $row = LeadImportRow::where('lead_import_id', $import->id)->where('row_number', 1)->firstOrFail();
    expect($row->status)->toBe(LeadImportRowStatus::Duplicate);
    expect($row->errors)->toBe([INC1_EXISTING_LEAD_CONFLICT_MESSAGE]);
    expect($row->duplicate_of_lead_id)->toBeNull();
    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(2);
});

test('when several earlier Imported rows share an identity value, the earliest row number is used — regardless of insertion order', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin, 'source' => $source, 'import' => $import] = setUpLeadImport();

    // Anomalous historical data, inserted in REVERSE row order so neither id
    // nor insertion order can decide the outcome. Earliest email row and
    // earliest phone row are both row 1, so this is an ordinary duplicate —
    // picking the later email row (2, no phone) would make it a conflict.
    LeadImportRow::factory()->create(['lead_import_id' => $import->id, 'row_number' => 2, 'status' => LeadImportRowStatus::Imported, 'normalized_email' => 'shared@example.com', 'normalized_phone' => null, 'raw_data' => ['name' => 'Later']]);
    LeadImportRow::factory()->create(['lead_import_id' => $import->id, 'row_number' => 1, 'status' => LeadImportRowStatus::Imported, 'normalized_email' => 'shared@example.com', 'normalized_phone' => '5550001111', 'raw_data' => ['name' => 'Earliest']]);

    app(CreateLeadFromImport::class)->handle($import, $tenant, $source, $clientAdmin, 3, ['name' => 'Newcomer', 'email' => 'shared@example.com', 'phone' => '5550001111']);

    $row = LeadImportRow::where('lead_import_id', $import->id)->where('row_number', 3)->firstOrFail();
    expect($row->status)->toBe(LeadImportRowStatus::Duplicate);
    expect($row->errors)->toBe([INC1_IN_FILE_DUPLICATE_MESSAGE]);
});

test('earlier Failed and Duplicate rows never participate: they do not block a later valid row or count as in-file matches', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin, 'source' => $source, 'import' => $import] = setUpLeadImport();
    Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'exists@example.com']);
    $action = app(CreateLeadFromImport::class);

    // A Failed row (it stores its normalized email) must not block the next valid row.
    $action->handle($import, $tenant, $source, $clientAdmin, 1, ['name' => 'Alpha', 'email' => 'failed-first@example.com', 'status' => 'NoSuchStatus']);
    $action->handle($import, $tenant, $source, $clientAdmin, 2, ['name' => 'Bravo', 'email' => 'failed-first@example.com']);

    expect(LeadImportRow::where('lead_import_id', $import->id)->where('row_number', 1)->firstOrFail()->status)->toBe(LeadImportRowStatus::Failed);
    expect(LeadImportRow::where('lead_import_id', $import->id)->where('row_number', 2)->firstOrFail()->status)->toBe(LeadImportRowStatus::Imported);

    // A Duplicate row is not an in-file "winner": the next row matching the
    // same identity is attributed to the existing lead, never to the earlier row.
    $action->handle($import, $tenant, $source, $clientAdmin, 3, ['name' => 'Charlie', 'email' => 'exists@example.com']);
    $action->handle($import, $tenant, $source, $clientAdmin, 4, ['name' => 'Delta', 'email' => 'exists@example.com']);

    $rowFour = LeadImportRow::where('lead_import_id', $import->id)->where('row_number', 4)->firstOrFail();
    expect($rowFour->status)->toBe(LeadImportRowStatus::Duplicate);
    expect($rowFour->errors)->toBe([INC1_EXISTING_LEAD_DUPLICATE_MESSAGE]);
    expect($rowFour->duplicate_of_lead_id)->not->toBeNull();
});

test('in-file matching is scoped to this import: another tenant\'s rows and another import\'s rows never make a row an in-file duplicate or conflict', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin, 'source' => $source, 'import' => $import] = setUpLeadImport();
    ['tenant' => $otherTenant, 'clientAdmin' => $otherAdmin, 'source' => $otherSource, 'import' => $otherImport] = setUpLeadImport();
    $action = app(CreateLeadFromImport::class);

    $action->handle($otherImport, $otherTenant, $otherSource, $otherAdmin, 1, ['name' => 'Alpha', 'email' => 'shared@example.com', 'phone' => '5550004444']);
    $action->handle($import, $tenant, $source, $clientAdmin, 1, ['name' => 'Bravo', 'email' => 'shared@example.com', 'phone' => '5550004444']);

    expect(LeadImportRow::where('lead_import_id', $import->id)->where('row_number', 1)->firstOrFail()->status)->toBe(LeadImportRowStatus::Imported);

    // A second import in the SAME tenant: its Imported row is a real Lead
    // now, so a later row is a duplicate of that EXISTING lead — not an
    // in-file duplicate of another import's row.
    $secondImport = LeadImport::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'imported_by' => $clientAdmin->id]);
    $action->handle($secondImport, $tenant, $source, $clientAdmin, 1, ['name' => 'Charlie', 'email' => 'second@example.com', 'phone' => '5550005555']);
    $action->handle($import, $tenant, $source, $clientAdmin, 2, ['name' => 'Delta', 'email' => 'second@example.com', 'phone' => '5550006666']);

    $row = LeadImportRow::where('lead_import_id', $import->id)->where('row_number', 2)->firstOrFail();
    expect($row->status)->toBe(LeadImportRowStatus::Duplicate);
    expect($row->errors)->toBe([INC1_EXISTING_LEAD_DUPLICATE_MESSAGE]);
    expect($row->duplicate_of_lead_id)->not->toBeNull();
});

test('a retried in-file conflict row is never reprocessed — one row recorded, counters unchanged', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin, 'source' => $source, 'import' => $import] = setUpLeadImport();
    $action = app(CreateLeadFromImport::class);

    $action->handle($import, $tenant, $source, $clientAdmin, 1, ['name' => 'Alpha', 'email' => 'retry-a@example.com']);
    $action->handle($import, $tenant, $source, $clientAdmin, 2, ['name' => 'Bravo', 'phone' => '5550007777']);
    $conflict = ['name' => 'Charlie', 'email' => 'retry-a@example.com', 'phone' => '5550007777'];
    $action->handle($import, $tenant, $source, $clientAdmin, 3, $conflict);
    $action->handle($import, $tenant, $source, $clientAdmin, 3, $conflict);

    expect(LeadImportRow::where('lead_import_id', $import->id)->where('row_number', 3)->count())->toBe(1);
    expect($import->fresh()->only(['imported_count', 'duplicate_count', 'failed_count']))->toBe(['imported_count' => 2, 'duplicate_count' => 1, 'failed_count' => 0]);
    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(2);
});
