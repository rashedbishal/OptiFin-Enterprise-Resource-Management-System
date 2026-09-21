<?php

use App\Enums\CallEndReason;
use App\Enums\CallMetaStage;
use App\Enums\CallRoutingMode;
use App\Enums\CallStatus;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Models\LeadSource;
use App\Models\TenantUser;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;

/*
 * WhatsApp inbound calling, Build Step 1 — the lead_calls table and the
 * calling columns on lead_sources. No behavior beyond the data model exists
 * yet; these tests pin the approved schema, casts and FK semantics.
 */

test('the lead_calls table has exactly the approved columns', function () {
    expect(Schema::getColumnListing('lead_calls'))->toEqualCanonicalizing([
        'id', 'tenant_id', 'lead_id', 'lead_source_id', 'conversation_id', 'external_call_id',
        'status', 'end_reason', 'meta_stage', 'meta_status', 'meta_terminated_at',
        'assignee_id_at_arrival', 'routing_mode', 'routed_user_ids',
        'claimed_by', 'claimed_at', 'connected_at', 'ended_at', 'duration_seconds', 'ringing_expires_at',
        'created_at', 'updated_at',
    ]);
});

test('an existing lead source gains calling columns that default to calling off with no Meta status', function () {
    $source = LeadSource::factory()->create();

    $fresh = $source->fresh();

    expect($fresh->calling_enabled)->toBeFalse();
    expect($fresh->calling_meta_status)->toBeNull();
    expect($fresh->calling_meta_checked_at)->toBeNull();
});

test('the calling columns on a lead source round-trip through mass assignment', function () {
    $source = LeadSource::factory()->create([
        'calling_enabled' => true,
        'calling_meta_status' => 'ENABLED',
        'calling_meta_checked_at' => now(),
    ]);

    $fresh = $source->fresh();

    expect($fresh->calling_enabled)->toBeTrue();
    expect($fresh->calling_meta_status)->toBe('ENABLED');
    expect($fresh->calling_meta_checked_at)->not->toBeNull();
});

test('a call round-trips its enums, routing snapshot and timestamps', function () {
    $assignee = TenantUser::factory()->create();
    $admin = TenantUser::factory()->create();

    $call = LeadCall::factory()->create([
        'status' => CallStatus::Answering,
        'end_reason' => CallEndReason::AnswerTimeout,
        'meta_stage' => CallMetaStage::PreAccepted,
        'meta_status' => 'FAILED',
        'routing_mode' => CallRoutingMode::Assignee,
        'assignee_id_at_arrival' => $assignee->id,
        'routed_user_ids' => [$assignee->id, $admin->id],
        'claimed_by' => $assignee->id,
        'claimed_at' => now(),
        'duration_seconds' => 42,
    ]);

    $fresh = $call->fresh();

    expect($fresh->status)->toBe(CallStatus::Answering);
    expect($fresh->end_reason)->toBe(CallEndReason::AnswerTimeout);
    expect($fresh->meta_stage)->toBe(CallMetaStage::PreAccepted);
    expect($fresh->routing_mode)->toBe(CallRoutingMode::Assignee);
    expect($fresh->routed_user_ids)->toBe([$assignee->id, $admin->id]);
    expect($fresh->assigneeAtArrival->is($assignee))->toBeTrue();
    expect($fresh->claimedBy->is($assignee))->toBeTrue();
    expect($fresh->claimed_at)->not->toBeNull();
    expect($fresh->duration_seconds)->toBe(42);
});

test('a call starts Ringing with no end reason, Meta stage or claim unless told otherwise', function () {
    $call = LeadCall::factory()->create();

    $fresh = $call->fresh();

    expect($fresh->status)->toBe(CallStatus::Ringing);
    expect($fresh->end_reason)->toBeNull();
    expect($fresh->meta_stage)->toBeNull();
    expect($fresh->claimed_by)->toBeNull();
    expect($fresh->meta_terminated_at)->toBeNull();
});

test('a Meta call id is unique per lead source', function () {
    $source = LeadSource::factory()->create();
    LeadCall::factory()->create(['lead_source_id' => $source->id, 'external_call_id' => 'wacid.duplicate']);

    expect(fn () => LeadCall::factory()->create(['lead_source_id' => $source->id, 'external_call_id' => 'wacid.duplicate']))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('the same Meta call id may exist under a different lead source', function () {
    LeadCall::factory()->create(['lead_source_id' => LeadSource::factory()->create()->id, 'external_call_id' => 'wacid.shared']);
    LeadCall::factory()->create(['lead_source_id' => LeadSource::factory()->create()->id, 'external_call_id' => 'wacid.shared']);

    expect(LeadCall::where('external_call_id', 'wacid.shared')->count())->toBe(2);
});

test('the active scope returns only calls still in flight', function () {
    $lead = Lead::factory()->create();
    $source = LeadSource::factory()->create();

    foreach (CallStatus::cases() as $status) {
        LeadCall::factory()->create([
            'lead_id' => $lead->id,
            'lead_source_id' => $source->id,
            'status' => $status,
        ]);
    }

    $active = LeadCall::query()->where('lead_id', $lead->id)->active()->get();

    expect($active->pluck('status')->map->value->sort()->values()->all())->toBe(['answering', 'connected', 'ringing']);
    expect($active->every->isActive())->toBeTrue();
});

test('deleting the claiming user, the arrival assignee or the conversation keeps the call and nulls the reference', function () {
    $user = TenantUser::factory()->create();
    $conversation = Conversation::factory()->create();

    $call = LeadCall::factory()->connected()->create([
        'conversation_id' => $conversation->id,
        'assignee_id_at_arrival' => $user->id,
        'claimed_by' => $user->id,
    ]);

    $user->delete();
    $conversation->delete();

    $fresh = $call->fresh();

    expect($fresh)->not->toBeNull();
    expect($fresh->assignee_id_at_arrival)->toBeNull();
    expect($fresh->claimed_by)->toBeNull();
    expect($fresh->conversation_id)->toBeNull();
});

test('a lead source that has call history cannot be deleted', function () {
    $source = LeadSource::factory()->create();
    LeadCall::factory()->completed()->create(['lead_source_id' => $source->id]);

    expect(fn () => $source->delete())->toThrow(QueryException::class);
    expect(LeadSource::find($source->id))->not->toBeNull();
});
