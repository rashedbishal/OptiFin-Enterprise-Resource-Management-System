<?php

use App\Enums\LeadDuplicateLinkStatus;
use App\Models\Lead;
use App\Models\LeadDuplicateLink;
use App\Services\LeadDuplicateDetectionService;
use Illuminate\Support\Facades\DB;

/*
 * Batch 7 — F2. LeadDuplicateDetectionService::recordPendingLink() runs on
 * every live Lead detail-page view (detectFor() is not gated behind any
 * explicit action), so two concurrent viewers of the same pair — or of
 * either lead in it — could both see no existing LeadDuplicateLink and both
 * attempt firstOrCreate()'s create(), hitting the real
 * unique(lead_id, duplicate_lead_id) constraint uncaught for the loser.
 *
 * Forces the real interleaving via a DB::listen() hook on the fix's own
 * existence check, landing the competing insert before the fix's own
 * create() attempt runs — see RecordLeadConsentConcurrentCreateTest's own
 * docblock for why this is done on the SELECT rather than inside a
 * `creating` event nested in the create() call itself.
 */

function raceOnFirstDuplicateLinkSelect(string $leadId, string $duplicateLeadId, string $tenantId): void
{
    $raced = false;

    DB::listen(function ($query) use (&$raced, $leadId, $duplicateLeadId, $tenantId) {
        if ($raced) {
            return;
        }

        if (! str_starts_with(ltrim($query->sql), 'select') || ! str_contains($query->sql, 'lead_duplicate_links')) {
            return;
        }

        if (! in_array($leadId, $query->bindings, true) || ! in_array($duplicateLeadId, $query->bindings, true)) {
            return;
        }

        $raced = true;

        LeadDuplicateLink::query()->create([
            'lead_id' => $leadId,
            'duplicate_lead_id' => $duplicateLeadId,
            'tenant_id' => $tenantId,
            'status' => LeadDuplicateLinkStatus::Pending,
            'matched_fields' => ['email'],
            'detected_at' => now(),
        ]);
    });
}

test('a concurrent duplicate-link create recovers instead of throwing, exactly one row remains', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $leadA = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'jane@example.com', 'phone' => null]);
    $leadB = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'jane@example.com', 'phone' => null]);

    [$leadId, $duplicateLeadId] = LeadDuplicateLink::pairKeys($leadA, $leadB);
    raceOnFirstDuplicateLinkSelect($leadId, $duplicateLeadId, $tenant->id);

    $links = app(LeadDuplicateDetectionService::class)->detectFor($leadA);

    expect($links)->toHaveCount(1);
    expect(LeadDuplicateLink::where('lead_id', $leadId)->where('duplicate_lead_id', $duplicateLeadId)->count())->toBe(1);
});

test('the recovered link is still returned by detectFor(), not silently dropped', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $leadA = Lead::factory()->create(['tenant_id' => $tenant->id, 'phone' => '+15551234567']);
    $leadB = Lead::factory()->create(['tenant_id' => $tenant->id, 'phone' => '15551234567']);

    [$leadId, $duplicateLeadId] = LeadDuplicateLink::pairKeys($leadA, $leadB);
    raceOnFirstDuplicateLinkSelect($leadId, $duplicateLeadId, $tenant->id);

    $links = app(LeadDuplicateDetectionService::class)->detectFor($leadB);

    expect($links)->toHaveCount(1);
    expect($links->first()->otherLead($leadB)->id)->toBe($leadA->id);
});

test('reverse-direction detection still resolves to the same canonical pair row, not a second one', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $leadA = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'jane@example.com', 'phone' => null]);
    $leadB = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'jane@example.com', 'phone' => null]);

    app(LeadDuplicateDetectionService::class)->detectFor($leadA);
    app(LeadDuplicateDetectionService::class)->detectFor($leadB);

    [$leadId, $duplicateLeadId] = LeadDuplicateLink::pairKeys($leadA, $leadB);
    expect(LeadDuplicateLink::where('lead_id', $leadId)->where('duplicate_lead_id', $duplicateLeadId)->count())->toBe(1);
});
