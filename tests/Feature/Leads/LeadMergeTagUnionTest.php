<?php

use App\Models\Lead;
use App\Models\LeadTag;
use App\Models\TenantUser;
use App\Services\LeadMergeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('merging unions the loser\'s tags onto the survivor, keeping every unique tag from both', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $survivorOnly = LeadTag::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Survivor Only']);
    $loserOnly = LeadTag::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Loser Only']);

    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $survivor->tags()->attach($survivorOnly->id, ['tagged_by' => $clientAdmin->id]);
    $loser->tags()->attach($loserOnly->id, ['tagged_by' => $clientAdmin->id]);

    app(LeadMergeService::class)->merge($survivor, $loser, $clientAdmin, []);

    $survivor->refresh();
    expect($survivor->tags->pluck('id')->sort()->values()->all())
        ->toBe(collect([$survivorOnly->id, $loserOnly->id])->sort()->values()->all());
});

test('a tag both leads already carry ends up on the survivor exactly once, never duplicated', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $sharedTag = LeadTag::factory()->create(['tenant_id' => $tenant->id]);

    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $survivor->tags()->attach($sharedTag->id, ['tagged_by' => $clientAdmin->id]);
    $loser->tags()->attach($sharedTag->id, ['tagged_by' => $clientAdmin->id]);

    app(LeadMergeService::class)->merge($survivor, $loser, $clientAdmin, []);

    $survivor->refresh();
    expect($survivor->tags)->toHaveCount(1);
    expect(DB::table('lead_lead_tag')->where('lead_id', $survivor->id)->where('lead_tag_id', $sharedTag->id)->count())->toBe(1);
});

test('merging when the loser has tags but the survivor has none moves the loser\'s tags onto the survivor', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tag = LeadTag::factory()->create(['tenant_id' => $tenant->id]);

    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $loser->tags()->attach($tag->id, ['tagged_by' => $clientAdmin->id]);

    app(LeadMergeService::class)->merge($survivor, $loser, $clientAdmin, []);

    expect($survivor->fresh()->tags->pluck('id')->all())->toBe([$tag->id]);
});

test('merging when the survivor has tags but the loser has none leaves the survivor\'s tags untouched', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tag = LeadTag::factory()->create(['tenant_id' => $tenant->id]);

    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $survivor->tags()->attach($tag->id, ['tagged_by' => $clientAdmin->id]);

    app(LeadMergeService::class)->merge($survivor, $loser, $clientAdmin, []);

    expect($survivor->fresh()->tags->pluck('id')->all())->toBe([$tag->id]);
});

test('merging two leads with no tags at all succeeds without error', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);

    $result = app(LeadMergeService::class)->merge($survivor, $loser, $clientAdmin, []);

    expect($result->tags)->toHaveCount(0);
});

test('the tag union preserves the original tagged_by attribution for a tag carried over from the loser', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $tag = LeadTag::factory()->create(['tenant_id' => $tenant->id]);

    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $loser->tags()->attach($tag->id, ['tagged_by' => $rep->id]);

    app(LeadMergeService::class)->merge($survivor, $loser, $clientAdmin, []);

    expect(DB::table('lead_lead_tag')->where('lead_id', $survivor->id)->where('lead_tag_id', $tag->id)->value('tagged_by'))
        ->toBe($rep->id);
});

test('the tag union runs inside the merge\'s existing transaction and rolls back with everything else on failure', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tag = LeadTag::factory()->create(['tenant_id' => $tenant->id]);

    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $loser->tags()->attach($tag->id, ['tagged_by' => $clientAdmin->id]);

    // Same fault-injection technique as LeadMergeTest's "a failure partway
    // through a merge rolls back every change already made in that
    // transaction": a TenantUser built but never persisted trips the
    // LeadDuplicateLink.resolved_by foreign key on the merge's very last
    // write — AFTER unionTags() has already attached the loser's tag onto
    // the survivor, earlier in the SAME transaction.
    $neverPersisted = TenantUser::factory()->make(['id' => (string) Str::ulid(), 'tenant_id' => $tenant->id]);

    expect(fn () => app(LeadMergeService::class)->merge($survivor, $loser, $neverPersisted, []))
        ->toThrow(Exception::class);

    expect($survivor->fresh()->tags)->toHaveCount(0);
    expect($loser->fresh()->merged_into_lead_id)->toBeNull();
});
