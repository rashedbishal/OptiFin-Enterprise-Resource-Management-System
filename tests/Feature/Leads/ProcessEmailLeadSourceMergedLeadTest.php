<?php

use App\Enums\LeadSourceType;
use App\Jobs\ProcessEmailLeadSource;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Services\LeadMergeService;

/**
 * F9 remediation (forensic audit Batch B) — ProcessEmailLeadSource's
 * existing-lead lookup for a follow-up email must never select a
 * merged-away lead (see App\Services\LeadMergeService — the loser's own
 * `email` column is left untouched by a merge, so a naive lookup can still
 * match it), and must instead resolve to the active survivor at the top of
 * the merge chain — the same identity-resolution behavior
 * App\Actions\Tenant\CreateLeadFromImport::resolveActiveSurvivorId()
 * already established for CSV import.
 *
 * Exercised directly against ProcessEmailLeadSource::resolveExistingLeadForEmail()
 * via reflection — this method and resolveActiveSurvivor() are pure DB
 * lookups with no IMAP call in them at all, unlike createLeadFromEmail()
 * itself, so this is testable exactly like the codebase's own established
 * "no real IMAP\Connection in tests" convention already requires (see
 * tests/Unit/ProcessEmailLeadSourceBodyExtractionTest.php's identical
 * reflection-based approach for this same class's other pure-logic
 * methods) — the DB access here is why this lives in tests/Feature (which
 * RefreshDatabase-wraps, per tests/Pest.php), not tests/Unit.
 */
function resolveExistingLeadForEmail(LeadSource $source, string $email): ?Lead
{
    $job = new ProcessEmailLeadSource($source);
    $method = new ReflectionMethod($job, 'resolveExistingLeadForEmail');

    return $method->invoke($job, $email);
}

test('an active lead matching the address is selected normally', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Email]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'email' => 'contact@example.com']);

    $resolved = resolveExistingLeadForEmail($source, 'contact@example.com');

    expect($resolved?->id)->toBe($lead->id);
});

test('no match at all still returns null, exactly as before this remediation', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Email]);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'email' => 'someone-else@example.com']);

    expect(resolveExistingLeadForEmail($source, 'contact@example.com'))->toBeNull();
});

test('a lookup is scoped to this source only, exactly as before this remediation', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $emailSource = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Email]);
    $otherSource = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Email]);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $otherSource->id, 'email' => 'contact@example.com']);

    expect(resolveExistingLeadForEmail($emailSource, 'contact@example.com'))->toBeNull();
});

test('a merged-away lead is never selected directly, even though its own email column still matches', function () {
    ['tenant' => $tenant, 'clientAdmin' => $admin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Email]);

    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'email' => 'survivor@example.com']);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'email' => 'contact@example.com']);

    app(LeadMergeService::class)->merge($survivor, $loser, $admin, []);

    // The bug this remediation closes: a naive where('email', ...) lookup
    // with no merged_into_lead_id filter would return the now-retired
    // $loser here (its own email column is untouched by the merge).
    $resolved = resolveExistingLeadForEmail($source, 'contact@example.com');

    expect($resolved)->not->toBeNull();
    expect($resolved->id)->toBe($survivor->id);
    expect($resolved->id)->not->toBe($loser->id);
});

test('a two-hop merge chain resolves all the way to the final active survivor', function () {
    ['tenant' => $tenant, 'clientAdmin' => $admin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Email]);

    $original = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'email' => 'contact@example.com']);
    $middle = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'email' => 'middle@example.com']);
    $finalSurvivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'email' => 'final@example.com']);

    // original -> middle -> finalSurvivor, exactly as a real duplicate
    // detected and merged twice, on two separate occasions, would produce.
    app(LeadMergeService::class)->merge($middle, $original, $admin, []);
    app(LeadMergeService::class)->merge($finalSurvivor, $middle->fresh(), $admin, []);

    $resolved = resolveExistingLeadForEmail($source, 'contact@example.com');

    expect($resolved?->id)->toBe($finalSurvivor->id);
});

test('a dismissed (never merged) possible duplicate is unaffected — only a real merge changes resolution', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Email]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'email' => 'contact@example.com']);

    expect(resolveExistingLeadForEmail($source, 'contact@example.com')?->id)->toBe($lead->id);
});
