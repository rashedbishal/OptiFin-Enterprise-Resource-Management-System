<?php

use App\Actions\Tenant\ListActiveLeadCalls;
use App\Enums\CallRoutingMode;
use App\Enums\CallStatus;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Models\TenantUser;
use App\Services\LeadMergeService;
use Illuminate\Support\Facades\DB;
use Tests\Support\LeadCallScenario as Lc;
use Tests\Support\WhatsAppCallFixtures as Wa;

/*
 * GET lead-management/calls/active — the softphone's recovery read: the calls
 * that are live for THIS user, inside their own tenant, and nothing else. Never
 * an SDP, never a Meta detail, never a change to any call.
 */

/**
 * @return array<int, array<string, mixed>>
 */
function lalList(TenantUser $user): array
{
    return app(ListActiveLeadCalls::class)->handle($user);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function lalCall(Lc $x, array $attributes = []): LeadCall
{
    static $n = 0;
    $n++;

    // created_at is not mass-assignable, so it is applied straight to the row.
    $createdAt = $attributes['created_at'] ?? null;
    unset($attributes['created_at']);

    $call = LeadCall::factory()->create(array_merge([
        'tenant_id' => $x->tenant->id,
        'lead_id' => $x->lead->id,
        'lead_source_id' => $x->source->id,
        'external_call_id' => 'wacid.EXTRA-'.$n,
        'routed_user_ids' => [$x->agent->id, $x->other->id],
        'ringing_expires_at' => now()->addSeconds(60),
    ], $attributes));

    if ($createdAt !== null) {
        DB::table('lead_calls')->where('id', $call->id)->update(['created_at' => $createdAt]);
    }

    return $call->fresh();
}

// ── Ringing ─────────────────────────────────────────────────────────────────

test('a ringing call routed to the user is listed with exactly the approved fields', function () {
    $x = Lc::make();
    $x->lead->update(['name' => 'Ada Lovelace', 'phone' => '+15551230000']);

    $calls = lalList($x->agent);

    expect($calls)->toHaveCount(1);
    expect(array_keys($calls[0]))->toBe(['id', 'status', 'direction', 'claimed_by_me', 'accepted', 'lead', 'assignee', 'receiving_as', 'expires_in_seconds', 'connected_at']);
    expect($calls[0])->toMatchArray([
        'id' => $x->call->id,
        'status' => 'ringing',
        'direction' => 'inbound',
        'claimed_by_me' => false,
        'accepted' => false,
        'lead' => ['id' => $x->lead->id, 'name' => 'Ada Lovelace', 'phone' => '+15551230000'],
        // Lc::make()'s default LeadCall row has no assignee_id_at_arrival
        // and the factory's default routing_mode is AdminFallback — see
        // WhatsApp Call Backup / Overflow Receiver tests below for the
        // Assignee/BackupFallback/AgentInitiated cases.
        'assignee' => null,
        'receiving_as' => 'admin_fallback',
        'connected_at' => null,
    ]);
    expect($calls[0]['expires_in_seconds'])->toBeInt()->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
});

test('every routed user sees the same ringing call, and nobody else does', function () {
    $x = Lc::make();

    expect(lalList($x->agent))->toHaveCount(1)->and(lalList($x->other))->toHaveCount(1);
    expect(lalList($x->admin))->toBe([]);
});

test('an expired ringing call is not listed, and reading never changes it', function () {
    $x = Lc::make(['ringing_expires_at' => now()->subSecond()]);
    $before = $x->fresh()->only(['status', 'end_reason', 'ended_at', 'updated_at']);

    expect(lalList($x->agent))->toBe([]);

    expect($x->fresh()->only(['status', 'end_reason', 'ended_at', 'updated_at']))->toEqual($before);
});

test('a ringing call that was terminal by any means is not listed', function (string $state) {
    $x = Lc::make(['status' => $state]);

    expect(lalList($x->agent))->toBe([]);
})->with(['completed', 'missed', 'rejected', 'failed']);

// ── Answering / Connected ───────────────────────────────────────────────────

test('an answering or connected call is listed only for the user who claimed it', function () {
    $x = Lc::make()->claimed();

    $mine = lalList($x->agent);
    expect($mine)->toHaveCount(1)->and($mine[0]['status'])->toBe('answering')->and($mine[0]['claimed_by_me'])->toBeTrue();

    // A colleague who was routed the same call no longer sees it once it is claimed.
    expect(lalList($x->other))->toBe([]);

    $x->call->update(['status' => CallStatus::Connected, 'connected_at' => now()]);
    $connected = lalList($x->agent);
    expect($connected[0]['status'])->toBe('connected')->and($connected[0]['connected_at'])->toBeString()->and($connected[0]['expires_in_seconds'])->toBeNull();
    expect(lalList($x->other))->toBe([]);
});

test('the window bounds answering and connected calls and is configurable', function () {
    $x = Lc::make(['status' => 'answering', 'claimed_by' => null]);
    $x->call->update(['claimed_by' => $x->agent->id, 'claimed_at' => now()->subMinutes(90)]);
    $stuck = lalCall($x, ['status' => 'connected', 'claimed_by' => $x->agent->id, 'claimed_at' => now()->subHours(3), 'connected_at' => now()->subHours(3)]);

    // Default window: 120 minutes.
    $ids = array_column(lalList($x->agent), 'id');
    expect($ids)->toContain($x->call->id)->not->toContain($stuck->id);

    config(['calling.active_window_minutes' => 60]);
    expect(lalList($x->agent))->toBe([]);

    config(['calling.active_window_minutes' => 240]);
    expect(array_column(lalList($x->agent), 'id'))->toContain($stuck->id);
});

test('the default window is two hours', function () {
    expect(config('calling.active_window_minutes'))->toBe(120);
});

// ── Isolation and authorization ─────────────────────────────────────────────

test('another tenant\'s calls are never listed, even when the user id is in the snapshot', function () {
    $mine = Lc::make();
    $theirs = Lc::make(['external_call_id' => 'wacid.THEIRS', 'routed_user_ids' => []]);
    $theirs->call->update(['routed_user_ids' => [$mine->agent->id, $theirs->agent->id]]);

    expect(array_column(lalList($mine->agent), 'id'))->toBe([$mine->call->id]);
    expect(array_column(lalList($theirs->agent), 'id'))->toBe([$theirs->call->id]);
});

test('a user who is inactive or lost the lead-management update permission sees nothing', function () {
    $x = Lc::make();
    $viewOnly = clientUserWithLeadPermission($x->tenant, ['view']);
    $x->call->update(['routed_user_ids' => [$x->agent->id, $viewOnly->id]]);

    expect(lalList($viewOnly))->toBe([]);

    DB::table('tenant_users')->where('id', $x->agent->id)->update(['is_active' => false]);
    expect(lalList($x->agent->fresh()))->toBe([]);
});

test('a call claimed by someone else is not listed even for a routed user', function () {
    $x = Lc::make()->claimed();
    $x->call->update(['claimed_by' => $x->other->id]);

    expect(lalList($x->agent))->toBe([]);
});

// ── Contents ────────────────────────────────────────────────────────────────

test('the list never contains an SDP, a Meta detail, routing internals or a credential', function () {
    $x = Lc::make()->preAccepted();

    $json = json_encode(lalList($x->agent));

    expect($json)
        ->not->toContain(Wa::SDP_SENTINEL)
        ->not->toContain(Lc::ANSWER_SENTINEL)
        ->not->toContain('sdp')
        ->not->toContain('meta_stage')
        ->not->toContain('pre_accepted')
        ->not->toContain('routed_user_ids')
        ->not->toContain(Lc::CALL_ID)
        ->not->toContain(Lc::TOKEN)
        ->not->toContain(Wa::APP_SECRET)
        ->not->toContain(Wa::PHONE_NUMBER_ID);
});

test('at most 20 calls are returned, newest first', function () {
    $x = Lc::make();
    DB::table('lead_calls')->where('id', $x->call->id)->update(['created_at' => now()->subMinutes(30)]);

    foreach (range(1, 24) as $minutesAgo) {
        lalCall($x, ['created_at' => now()->subSeconds(100 - $minutesAgo)]);
    }

    $calls = lalList($x->agent);

    expect($calls)->toHaveCount(20);
    $createdAt = LeadCall::whereIn('id', array_column($calls, 'id'))->pluck('created_at', 'id');
    $ordered = array_map(fn (array $call) => $createdAt[$call['id']]->getTimestamp(), $calls);
    $sorted = $ordered;
    rsort($sorted);
    expect($ordered)->toBe($sorted);
    expect(array_column($calls, 'id'))->not->toContain($x->call->id);
});

test('the read loads every lead in one query instead of one per call', function () {
    $x = Lc::make();
    foreach (range(1, 8) as $i) {
        $lead = Lead::factory()->create(['tenant_id' => $x->tenant->id, 'source_id' => $x->source->id]);
        lalCall($x, ['lead_id' => $lead->id]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $calls = lalList($x->agent);
    $leadQueries = array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $sql): bool => str_contains($sql, 'from "leads"'));

    expect($calls)->toHaveCount(9)->and($leadQueries)->toHaveCount(1);
});

test('a merged lead\'s reparented call is listed under the surviving lead', function () {
    $x = Lc::make();
    $survivor = Lead::factory()->create(['tenant_id' => $x->tenant->id, 'name' => 'Survivor', 'email' => 'dup@example.com']);
    $x->lead->update(['email' => 'dup@example.com']);
    app(LeadMergeService::class)->merge($survivor, $x->lead, $x->admin, []);

    $calls = lalList($x->agent);

    expect($calls)->toHaveCount(1)->and($calls[0]['lead']['id'])->toBe($survivor->id);
});

// ── HTTP ────────────────────────────────────────────────────────────────────

test('the endpoint is a named GET behind the tenant middleware and returns the list uncacheably', function () {
    $x = Lc::make();

    expect(route('tenant-lead-calls.active'))->toEndWith('/lead-management/calls/active');

    $this->getJson(route('tenant-lead-calls.active'))->assertUnauthorized();

    $response = $this->actingAs($x->agent)->getJson(route('tenant-lead-calls.active'));

    $response->assertOk()->assertJsonPath('data.calls.0.id', $x->call->id)->assertJsonCount(1, 'data.calls');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    $this->actingAs($x->admin)->getJson(route('tenant-lead-calls.active'))->assertOk()->assertJsonCount(0, 'data.calls');
    $this->actingAs($x->agent)->postJson(route('tenant-lead-calls.active'))->assertStatus(405);
});

test('the call routes still resolve next to the new active route', function () {
    $x = Lc::make();

    $this->actingAs($x->agent)->postJson(route('tenant-lead-calls.claim', $x->call->id))->assertOk();
    expect($x->fresh()->status)->toBe(CallStatus::Answering);
});

// ── WhatsApp Call Backup / Overflow Receiver — softphone presence heartbeat ─

test('the active-calls poll touches only the authenticated caller\'s own softphone_last_seen_at', function () {
    $x = Lc::make();
    expect($x->agent->softphone_last_seen_at)->toBeNull();
    expect($x->other->softphone_last_seen_at)->toBeNull();

    $this->actingAs($x->agent)->getJson(route('tenant-lead-calls.active'))->assertOk();

    expect($x->agent->fresh()->softphone_last_seen_at)->not->toBeNull();
    expect($x->agent->fresh()->softphone_last_seen_at->diffInSeconds(now()))->toBeLessThan(5);
    // Nobody else's row is touched by one user's own poll — no id is ever
    // accepted from the browser for this, only $request->user().
    expect($x->other->fresh()->softphone_last_seen_at)->toBeNull();
});

test('the heartbeat cannot be spoofed for another user or another tenant — it only ever follows the authenticated session', function () {
    $x = Lc::make();
    $otherTenant = Lc::make();

    $this->actingAs($x->other)->getJson(route('tenant-lead-calls.active'))->assertOk();

    expect($x->agent->fresh()->softphone_last_seen_at)->toBeNull();
    expect($otherTenant->agent->fresh()->softphone_last_seen_at)->toBeNull();
    expect($x->other->fresh()->softphone_last_seen_at)->not->toBeNull();
});

test('an unauthenticated request touches nothing and is still refused', function () {
    $x = Lc::make();

    $this->getJson(route('tenant-lead-calls.active'))->assertUnauthorized();

    expect($x->agent->fresh()->softphone_last_seen_at)->toBeNull();
});

test('the heartbeat never changes the returned call list', function () {
    $x = Lc::make();

    $before = $this->actingAs($x->agent)->getJson(route('tenant-lead-calls.active'))->json('data.calls');
    $after = $this->actingAs($x->agent)->getJson(route('tenant-lead-calls.active'))->json('data.calls');

    expect($after)->toEqual($before);
});

// ── WhatsApp Call Backup / Overflow Receiver — assignee / receiving_as ─────

test('1. an Assignee-routed call reports receiving_as=assignee and its own name as the assignee', function () {
    $x = Lc::make();
    $x->call->update(['routing_mode' => CallRoutingMode::Assignee, 'assignee_id_at_arrival' => $x->agent->id, 'routed_user_ids' => [$x->agent->id]]);

    $call = lalList($x->agent)[0];

    expect($call['receiving_as'])->toBe('assignee');
    expect($call['assignee'])->toBe(['id' => $x->agent->id, 'name' => $x->agent->name]);
});

test('2. a BackupFallback call reports receiving_as=backup and the ORIGINAL assignee, not the viewer', function () {
    $x = Lc::make();
    // $x->admin is the original assignee, deliberately NOT routed; $x->agent
    // is the backup receiver actually viewing the list.
    $x->call->update(['routing_mode' => CallRoutingMode::BackupFallback, 'assignee_id_at_arrival' => $x->admin->id, 'routed_user_ids' => [$x->agent->id]]);

    $call = lalList($x->agent)[0];

    expect($call['receiving_as'])->toBe('backup');
    expect($call['assignee'])->toBe(['id' => $x->admin->id, 'name' => $x->admin->name]);
});

test('4. an AgentInitiated (outbound) call reports receiving_as=agent_initiated', function () {
    $x = Lc::make();
    $outbound = LeadCall::factory()->connected()->create([
        'tenant_id' => $x->tenant->id,
        'lead_id' => $x->lead->id,
        'lead_source_id' => $x->source->id,
        'direction' => 'outbound',
        'external_call_id' => 'wacid.OUTBOUND-ACTIVE-1',
        'routing_mode' => CallRoutingMode::AgentInitiated,
        'assignee_id_at_arrival' => $x->admin->id,
        'routed_user_ids' => [$x->agent->id],
        'claimed_by' => $x->agent->id,
    ]);

    $call = collect(lalList($x->agent))->firstWhere('id', $outbound->id);

    expect($call['receiving_as'])->toBe('agent_initiated');
    expect($call['assignee'])->toBe(['id' => $x->admin->id, 'name' => $x->admin->name]);
});

test('10. a since-deleted assignee resolves to a null assignee, never a crash, never a fake user', function () {
    $x = Lc::make();
    // assignee_id_at_arrival is a real, schema-enforced foreign key
    // (nullOnDelete — see 2026_09_21_070000_create_lead_calls_table.php), so
    // a genuinely "stale" reference cannot dangle: deleting the referenced
    // TenantUser automatically nulls this column at the database level.
    // This proves that path resolves gracefully, not a fabricated id.
    $deletedAssignee = clientUserWithLeadPermission($x->tenant, ['view', 'update']);
    $x->call->update([
        'routing_mode' => CallRoutingMode::AdminFallback,
        'assignee_id_at_arrival' => $deletedAssignee->id,
        'routed_user_ids' => [$x->agent->id],
    ]);
    $deletedAssignee->delete();

    $call = lalList($x->agent)[0];

    expect($call['assignee'])->toBeNull();
    expect($call['receiving_as'])->toBe('admin_fallback');
});

test('11. multiple eligible backup receivers all see the identical original-assignee context', function () {
    $x = Lc::make();
    $x->call->update(['routing_mode' => CallRoutingMode::BackupFallback, 'assignee_id_at_arrival' => $x->admin->id, 'routed_user_ids' => [$x->agent->id, $x->other->id]]);

    $forAgent = lalList($x->agent)[0];
    $forOther = lalList($x->other)[0];

    expect($forAgent['assignee'])->toBe(['id' => $x->admin->id, 'name' => $x->admin->name]);
    expect($forOther['assignee'])->toBe($forAgent['assignee']);
    expect($forOther['receiving_as'])->toBe($forAgent['receiving_as']);
});

test('12. reading the active list with assignee context never changes Lead.assigned_to', function () {
    $x = Lc::make();
    $x->call->update(['routing_mode' => CallRoutingMode::BackupFallback, 'assignee_id_at_arrival' => $x->admin->id, 'routed_user_ids' => [$x->agent->id]]);
    $before = $x->lead->fresh()->assigned_to;

    lalList($x->agent);

    expect($x->lead->fresh()->assigned_to)->toBe($before);
});

test('the assignee eager-load adds exactly one query, same discipline as the existing lead eager-load', function () {
    $x = Lc::make();
    $x->call->update(['routing_mode' => CallRoutingMode::BackupFallback, 'assignee_id_at_arrival' => $x->admin->id, 'routed_user_ids' => [$x->agent->id]]);
    foreach (range(1, 3) as $i) {
        $lead = Lead::factory()->create(['tenant_id' => $x->tenant->id, 'source_id' => $x->source->id]);
        lalCall($x, ['lead_id' => $lead->id, 'routing_mode' => CallRoutingMode::BackupFallback, 'assignee_id_at_arrival' => $x->admin->id, 'routed_user_ids' => [$x->agent->id]]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    lalList($x->agent);
    $assigneeQueries = array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $sql): bool => str_contains($sql, 'from "tenant_users"') && str_contains($sql, 'in ('));

    expect($assigneeQueries)->toHaveCount(1);
});
