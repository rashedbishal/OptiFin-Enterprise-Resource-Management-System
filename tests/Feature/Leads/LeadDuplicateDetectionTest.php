<?php

use App\Enums\LeadDuplicateLinkStatus;
use App\Models\Lead;
use App\Models\LeadDuplicateLink;
use App\Services\LeadDuplicateDetectionService;
use Illuminate\Support\Facades\DB;

test('a normalized email match across channels is flagged as a possible duplicate', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $leadA = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'Jane.Doe@Example.com ', 'phone' => null]);
    $leadB = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => ' jane.doe@example.com', 'phone' => null]);

    $links = app(LeadDuplicateDetectionService::class)->detectFor($leadA);

    expect($links)->toHaveCount(1);
    expect($links->first()->otherLead($leadA)->id)->toBe($leadB->id);
    expect($links->first()->matched_fields)->toBe(['email']);
    expect($links->first()->status)->toBe(LeadDuplicateLinkStatus::Pending);
});

test('a normalized phone match across channels is flagged as a possible duplicate', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $leadA = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => null, 'phone' => '+1 (555) 123-4567']);
    $leadB = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => null, 'phone' => '15551234567']);

    $links = app(LeadDuplicateDetectionService::class)->detectFor($leadA);

    expect($links)->toHaveCount(1);
    expect($links->first()->matched_fields)->toBe(['phone']);
});

test('duplicate detection never matches leads belonging to different tenants', function () {
    ['tenant' => $tenantA] = tenantWithLeadManagement();
    ['tenant' => $tenantB] = tenantWithLeadManagement();

    $leadA = Lead::factory()->create(['tenant_id' => $tenantA->id, 'email' => 'same@example.com', 'phone' => '15551234567']);
    Lead::factory()->create(['tenant_id' => $tenantB->id, 'email' => 'same@example.com', 'phone' => '15551234567']);

    $links = app(LeadDuplicateDetectionService::class)->detectFor($leadA);

    expect($links)->toHaveCount(0);
    expect(LeadDuplicateLink::query()->count())->toBe(0);
});

test('a lead with no email and no phone is never flagged as a duplicate of anything', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $leadA = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => null, 'phone' => null]);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => null, 'phone' => null]);

    $links = app(LeadDuplicateDetectionService::class)->detectFor($leadA);

    expect($links)->toHaveCount(0);
});

test('the same pair is never recorded twice regardless of which lead triggers detection', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $leadA = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $leadB = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);

    app(LeadDuplicateDetectionService::class)->detectFor($leadA);
    app(LeadDuplicateDetectionService::class)->detectFor($leadB);

    expect(LeadDuplicateLink::query()->count())->toBe(1);
});

test('dismissing a possible duplicate is never re-flagged by a later detection pass', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $leadA = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $leadB = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);

    $detectionService = app(LeadDuplicateDetectionService::class);
    $link = $detectionService->detectFor($leadA)->first();

    $link->update(['status' => LeadDuplicateLinkStatus::Dismissed, 'resolved_at' => now()]);

    $pending = $detectionService->detectFor($leadB);

    expect($pending)->toHaveCount(0);
    expect(LeadDuplicateLink::query()->count())->toBe(1);
    expect($link->fresh()->status)->toBe(LeadDuplicateLinkStatus::Dismissed);
});

test('a lead already merged away is never re-flagged as a fresh duplicate', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);

    DB::table('leads')->where('id', $loser->id)->update(['merged_into_lead_id' => $survivor->id]);

    $links = app(LeadDuplicateDetectionService::class)->detectFor($loser->fresh());

    expect($links)->toHaveCount(0);
});

// --- M4: normalization-compatibility unit tests (normalizeEmail()/normalizePhone() directly) ---

test('normalizeEmail lowercases and trims, using multibyte-safe casing', function () {
    expect(LeadDuplicateDetectionService::normalizeEmail('Jane.Doe@Example.COM'))->toBe('jane.doe@example.com');
    expect(LeadDuplicateDetectionService::normalizeEmail('  a@b.com  '))->toBe('a@b.com');
    expect(LeadDuplicateDetectionService::normalizeEmail("\t a@b.com \n"))->toBe('a@b.com');
});

test('normalizeEmail treats null and blank/whitespace-only input as null, not a matchable empty string', function () {
    expect(LeadDuplicateDetectionService::normalizeEmail(null))->toBeNull();
    expect(LeadDuplicateDetectionService::normalizeEmail(''))->toBeNull();
    expect(LeadDuplicateDetectionService::normalizeEmail('   '))->toBeNull();
});

test('normalizePhone strips every non-digit character regardless of format', function () {
    expect(LeadDuplicateDetectionService::normalizePhone('+1 (555) 123-4567'))->toBe('15551234567');
    expect(LeadDuplicateDetectionService::normalizePhone('555.123.4567'))->toBe('5551234567');
    expect(LeadDuplicateDetectionService::normalizePhone('015551234567'))->toBe('015551234567');
});

test('normalizePhone treats null and non-digit-only input as null, not a matchable empty string', function () {
    expect(LeadDuplicateDetectionService::normalizePhone(null))->toBeNull();
    expect(LeadDuplicateDetectionService::normalizePhone(''))->toBeNull();
    expect(LeadDuplicateDetectionService::normalizePhone('abc'))->toBeNull();
});

// --- M4: LeadObserver synchronization ---

test('creating a lead with an email and phone populates both normalized columns', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => ' Jane.Doe@Example.COM ', 'phone' => '+1 (555) 123-4567']);

    expect($lead->email_normalized)->toBe('jane.doe@example.com');
    expect($lead->phone_normalized)->toBe('15551234567');
});

test('updating only a lead\'s email recomputes email_normalized without touching phone_normalized', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'old@example.com', 'phone' => '5551234567']);
    $originalPhoneNormalized = $lead->phone_normalized;

    $lead->update(['email' => 'New.Address@Example.COM']);

    expect($lead->fresh()->email_normalized)->toBe('new.address@example.com');
    expect($lead->fresh()->phone_normalized)->toBe($originalPhoneNormalized);
});

test('updating only a lead\'s phone recomputes phone_normalized without touching email_normalized', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'stable@example.com', 'phone' => '5551234567']);
    $originalEmailNormalized = $lead->email_normalized;

    $lead->update(['phone' => '+1 (555) 999-8888']);

    expect($lead->fresh()->phone_normalized)->toBe('15559998888');
    expect($lead->fresh()->email_normalized)->toBe($originalEmailNormalized);
});

test('updating email and phone together recomputes both normalized columns', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'old@example.com', 'phone' => '5551234567']);

    $lead->update(['email' => ' New@Example.COM ', 'phone' => '555.999.8888']);

    $lead->refresh();
    expect($lead->email_normalized)->toBe('new@example.com');
    expect($lead->phone_normalized)->toBe('5559998888');
});

test('saving a lead without touching email or phone leaves both normalized columns untouched', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'stable@example.com', 'phone' => '5551234567']);
    $originalEmailNormalized = $lead->email_normalized;
    $originalPhoneNormalized = $lead->phone_normalized;

    $lead->update(['company' => 'Acme Corp']);

    expect($lead->fresh()->email_normalized)->toBe($originalEmailNormalized);
    expect($lead->fresh()->phone_normalized)->toBe($originalPhoneNormalized);
});

// --- M4: backfill correctness ---

/**
 * Simulates the exact backfill computation the migration
 * (2026_09_20_060000_add_normalized_email_phone_to_leads_table.php)
 * performs against pre-existing, un-normalized rows — RefreshDatabase runs
 * every migration against an empty leads table, so there's no real
 * pre-migration data for the migration itself to backfill inside a normal
 * test run. This instead recreates that starting state directly (a lead
 * whose normalized columns were never populated, exactly as any lead
 * created before this migration shipped would be) and proves the identical
 * two-line computation the migration's backfill loop uses produces values
 * equal to normalizeEmail()/normalizePhone() — the same guarantee the
 * migration itself relies on.
 */
test('backfilling a pre-existing lead\'s normalized columns matches normalizeEmail()/normalizePhone() exactly', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => ' Mixed.Case@Example.COM ', 'phone' => '+1 (555) 123-4567']);

    // Wipe the normalized columns back to null, simulating a row that
    // predates this migration and has never been backfilled.
    DB::table('leads')->where('id', $lead->id)->update(['email_normalized' => null, 'phone_normalized' => null]);
    $lead->refresh();
    expect($lead->email_normalized)->toBeNull();
    expect($lead->phone_normalized)->toBeNull();

    // The exact computation the migration's backfill loop performs.
    $lead->email_normalized = LeadDuplicateDetectionService::normalizeEmail($lead->email);
    $lead->phone_normalized = LeadDuplicateDetectionService::normalizePhone($lead->phone);
    $lead->save();

    expect($lead->fresh()->email_normalized)->toBe('mixed.case@example.com');
    expect($lead->fresh()->phone_normalized)->toBe('15551234567');
});

// --- M4: query-shape regression ---

/**
 * Proves detectFor() no longer fetches the full tenant lead set — the
 * previous implementation selected every non-merged, email-or-phone-
 * populated lead in the tenant unconditionally. The rewritten query must
 * filter by email_normalized/phone_normalized directly, so only genuine
 * candidates are ever fetched.
 */
test('detectFor() filters candidates by email_normalized/phone_normalized instead of fetching every tenant lead', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'target@example.com', 'phone' => null]);
    // Several unrelated leads that the old full-scan implementation would
    // have fetched and compared in PHP, but the new query should never
    // even retrieve.
    Lead::factory()->count(5)->create(['tenant_id' => $tenant->id, 'email' => null, 'phone' => null]);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'target@example.com', 'phone' => null]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    app(LeadDuplicateDetectionService::class)->detectFor($lead);

    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    $candidateQuery = $queries->first(
        fn (string $sql): bool => str_contains($sql, 'from "leads"') && str_contains($sql, '"tenant_id" =')
    );

    expect($candidateQuery)->not->toBeNull();
    expect($candidateQuery)->toContain('"email_normalized" =');
    // The old implementation's candidate query had no email/phone-value
    // predicate at all — only existence checks — so its absence here is
    // itself part of the proof the full-scan shape is gone.
    expect($candidateQuery)->not->toContain('"email" is not null');
});
