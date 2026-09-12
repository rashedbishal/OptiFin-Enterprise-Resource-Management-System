<?php

use App\Enums\DealStatus;
use App\Models\Deal;
use App\Models\DealStatusLog;
use App\Models\Lead;
use App\Models\TenantUser;
use App\Services\LeadMergeService;
use Illuminate\Support\Str;

test('merging reparents every one of the loser\'s deals onto the survivor', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);

    $survivorDeal = Deal::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $survivor->id, 'name' => 'Survivor Deal']);
    $loserDealA = Deal::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $loser->id, 'name' => 'Loser Deal A']);
    $loserDealB = Deal::factory()->won()->create(['tenant_id' => $tenant->id, 'lead_id' => $loser->id, 'name' => 'Loser Deal B']);

    app(LeadMergeService::class)->merge($survivor, $loser, $clientAdmin, []);

    expect($survivorDeal->fresh()->lead_id)->toBe($survivor->id);
    expect($loserDealA->fresh()->lead_id)->toBe($survivor->id);
    expect($loserDealB->fresh()->lead_id)->toBe($survivor->id);
    expect($survivor->fresh()->deals)->toHaveCount(3);
});

test('deal ids are unchanged by a merge', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $loserDeal = Deal::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $loser->id]);
    $originalId = $loserDeal->id;

    app(LeadMergeService::class)->merge($survivor, $loser, $clientAdmin, []);

    expect($loserDeal->fresh()->id)->toBe($originalId);
});

test('deal status log history remains attached to its deal after a merge, with no reparenting statement of its own', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $loserDeal = Deal::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $loser->id]);

    $log = DealStatusLog::factory()->create([
        'deal_id' => $loserDeal->id,
        'old_status' => DealStatus::Open,
        'new_status' => DealStatus::Won,
    ]);

    app(LeadMergeService::class)->merge($survivor, $loser, $clientAdmin, []);

    // The log's deal_id is never touched by the merge — it stays correctly
    // attached to $loserDeal purely because that deal's own id never
    // changes (only its lead_id column does). Querying it back via the
    // deal's fresh, reparented state proves the whole chain still resolves.
    $log->refresh();
    expect($log->deal_id)->toBe($loserDeal->id);
    expect($log->deal->lead_id)->toBe($survivor->id);
    expect(DealStatusLog::where('deal_id', $loserDeal->id)->count())->toBe(1);
});

test('the survivor keeps its own deals and gains the loser\'s — none are lost, none are duplicated', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);

    Deal::factory()->count(2)->create(['tenant_id' => $tenant->id, 'lead_id' => $survivor->id]);
    Deal::factory()->count(3)->create(['tenant_id' => $tenant->id, 'lead_id' => $loser->id]);

    app(LeadMergeService::class)->merge($survivor, $loser, $clientAdmin, []);

    expect(Deal::where('lead_id', $survivor->id)->count())->toBe(5);
    expect(Deal::where('lead_id', $loser->id)->count())->toBe(0);
    expect(Deal::count())->toBe(5);
});

test('a merge with no deals on either lead succeeds without error', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);

    $result = app(LeadMergeService::class)->merge($survivor, $loser, $clientAdmin, []);

    expect($result->deals)->toHaveCount(0);
});

test('a failure partway through a merge rolls back deal reparenting too', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $loserDeal = Deal::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $loser->id]);

    // Same fault-injection technique as LeadMergeTest's own rollback test: a
    // TenantUser built but never persisted trips the LeadDuplicateLink.
    // resolved_by foreign key on the merge's very last write — AFTER Deal
    // reparenting has already run earlier in the SAME transaction.
    $neverPersisted = TenantUser::factory()->make(['id' => (string) Str::ulid(), 'tenant_id' => $tenant->id]);

    expect(fn () => app(LeadMergeService::class)->merge($survivor, $loser, $neverPersisted, []))
        ->toThrow(Exception::class);

    expect($loserDeal->fresh()->lead_id)->toBe($loser->id);
    expect($loser->fresh()->merged_into_lead_id)->toBeNull();
});
