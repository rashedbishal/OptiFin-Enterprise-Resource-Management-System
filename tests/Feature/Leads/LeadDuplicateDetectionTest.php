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
