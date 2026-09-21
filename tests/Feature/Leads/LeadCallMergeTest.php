<?php

use App\Models\Lead;
use App\Models\LeadCall;
use App\Models\LeadSource;
use App\Services\LeadMergeService;

/*
 * WhatsApp inbound calling — LeadCall is a plain lead_id-keyed child of Lead
 * (like Deal/Task), reparented by LeadMergeService::CHILD_MODELS.
 */

test('merging reparents every one of the loser\'s calls onto the survivor, active or finished', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id]);
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $unrelated = Lead::factory()->create(['tenant_id' => $tenant->id]);

    $survivorCall = LeadCall::factory()->completed()->create(['tenant_id' => $tenant->id, 'lead_id' => $survivor->id, 'lead_source_id' => $source->id]);
    $loserFinished = LeadCall::factory()->missed()->create(['tenant_id' => $tenant->id, 'lead_id' => $loser->id, 'lead_source_id' => $source->id]);
    $loserActive = LeadCall::factory()->connected()->create(['tenant_id' => $tenant->id, 'lead_id' => $loser->id, 'lead_source_id' => $source->id]);
    $unrelatedCall = LeadCall::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $unrelated->id, 'lead_source_id' => $source->id]);

    app(LeadMergeService::class)->merge($survivor, $loser, $clientAdmin, []);

    expect($survivorCall->fresh()->lead_id)->toBe($survivor->id);
    expect($loserFinished->fresh()->lead_id)->toBe($survivor->id);
    expect($loserActive->fresh()->lead_id)->toBe($survivor->id);
    expect($unrelatedCall->fresh()->lead_id)->toBe($unrelated->id);
    expect(LeadCall::where('lead_id', $loser->id)->count())->toBe(0);
    expect($survivor->fresh()->calls)->toHaveCount(3);
});

test('a merge keeps each call\'s identity, status and frozen routing snapshot untouched', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id]);
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);

    $call = LeadCall::factory()->connected()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $loser->id,
        'lead_source_id' => $source->id,
        'assignee_id_at_arrival' => $clientAdmin->id,
        'routed_user_ids' => [$clientAdmin->id],
    ]);
    $before = $call->only(['id', 'external_call_id', 'status', 'routing_mode', 'assignee_id_at_arrival', 'routed_user_ids']);

    app(LeadMergeService::class)->merge($survivor, $loser, $clientAdmin, []);

    $after = $call->fresh();

    expect($after->only(['id', 'external_call_id', 'status', 'routing_mode', 'assignee_id_at_arrival', 'routed_user_ids']))->toEqual($before);
    expect($after->lead_id)->toBe($survivor->id);
});
