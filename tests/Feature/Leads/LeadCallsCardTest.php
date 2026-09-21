<?php

use App\Enums\CallEndReason;
use App\Enums\CallStatus;
use App\Enums\LeadSourceType;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Models\LeadSource;
use App\Services\LeadMergeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\LeadCallScenario as Lc;
use Tests\Support\WhatsAppCallFixtures as Wa;

/*
 * The Lead Calls card on the lead detail page and its flyout: the lead's latest
 * 10 calls, real LeadCall data only, no routing or Meta internals.
 */

function lccShow(Lc $x, ?Lead $lead = null): Testable
{
    return Livewire::actingAs($x->admin)->test(Show::class, ['lead' => $lead ?? $x->lead])->call('completeInitialLoad');
}

/**
 * @param  array<string, mixed>  $attributes
 */
function lccCall(Lc $x, array $attributes = [], ?Lead $lead = null): LeadCall
{
    static $n = 0;
    $n++;

    $createdAt = $attributes['created_at'] ?? null;
    unset($attributes['created_at']);

    $call = LeadCall::factory()->create(array_merge([
        'tenant_id' => $x->tenant->id,
        'lead_id' => ($lead ?? $x->lead)->id,
        'lead_source_id' => $x->source->id,
        'external_call_id' => 'wacid.CARD-'.$n,
        'routed_user_ids' => [$x->agent->id],
    ], $attributes));

    if ($createdAt !== null) {
        DB::table('lead_calls')->where('id', $call->id)->update(['created_at' => $createdAt]);
    }

    return $call->fresh();
}

/**
 * Just the card's own markup — the rest of the lead page legitimately contains
 * other users' ids (the assignee list, for one).
 */
function lccCardHtml(string $html): string
{
    $document = new DOMDocument;
    libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    libxml_clear_errors();

    $card = (new DOMXPath($document))->query('//*[@data-test="lead-calls-card"]')->item(0);

    return $card === null ? '' : (string) $document->saveHTML($card);
}

// ── Visibility ──────────────────────────────────────────────────────────────

test('a WhatsApp lead without calls shows the card with its empty state', function () {
    $x = Lc::make();
    $x->call->delete();

    lccShow($x)->assertSee('No calls yet.')->assertSeeHtml('data-test="lead-calls-card"');
});

test('a lead from another channel shows the card only once it has calls', function () {
    $x = Lc::make();
    $manual = LeadSource::factory()->create(['tenant_id' => $x->tenant->id, 'type' => LeadSourceType::Manual]);
    $lead = Lead::factory()->create(['tenant_id' => $x->tenant->id, 'source_id' => $manual->id]);

    lccShow($x, $lead)->assertDontSeeHtml('data-test="lead-calls-card"')->assertDontSee('No calls yet.');

    lccCall($x, ['status' => CallStatus::Missed, 'end_reason' => CallEndReason::CallerEndedOrNotAnswered], $lead);

    lccShow($x, $lead)->assertSeeHtml('data-test="lead-calls-card"')->assertSee('Missed');
});

test('the card is on the flyout view too', function () {
    $x = Lc::make();

    $html = Livewire::actingAs($x->admin)->test(Show::class, ['lead' => $x->lead, 'panel' => true])->call('completeInitialLoad')->html();

    expect($html)->toContain('data-test="lead-calls-card"');
})->skip(fn () => ! property_exists(Show::class, 'panel'), 'this component has no separate panel mode');

// ── Contents ────────────────────────────────────────────────────────────────

test('each call shows its status, direction, times, duration, claimant and end reason', function () {
    Carbon::setTestNow('2026-09-21 12:00:00');
    $x = Lc::make();
    $x->call->delete();
    lccCall($x, [
        'status' => CallStatus::Completed,
        'end_reason' => CallEndReason::RemoteTerminated,
        'claimed_by' => $x->agent->id,
        'claimed_at' => now()->subMinutes(5),
        'connected_at' => now()->subMinutes(4),
        'ended_at' => now()->subMinutes(3),
        'duration_seconds' => 65,
        'created_at' => now()->subMinutes(5),
    ]);

    lccShow($x)
        ->assertSee('Incoming')
        ->assertSee('Completed')
        ->assertSee('Duration: 1m 05s')
        ->assertSee('Answered by: '.$x->agent->name)
        ->assertSee('Connected: '.now()->subMinutes(4)->format('H:i:s'))
        ->assertSee('Ended: '.now()->subMinutes(3)->format('H:i:s'))
        ->assertSee('Ended')
        ->assertSee('5 minutes ago');
});

test('terminal states each show their own label and reason', function (string $state, ?CallEndReason $reason, string $label, ?string $reasonText) {
    $x = Lc::make();
    $x->call->delete();
    lccCall($x, ['status' => $state, 'end_reason' => $reason, 'ended_at' => now()]);

    $component = lccShow($x)->assertSee($label);

    if ($reasonText !== null) {
        $component->assertSee($reasonText);
    }
})->with([
    'missed' => ['missed', CallEndReason::CallerEndedOrNotAnswered, 'Missed', 'Caller hung up or nobody answered'],
    'rejected' => ['rejected', CallEndReason::AgentRejected, 'Rejected', 'Declined by an agent'],
    'failed' => ['failed', CallEndReason::PreAcceptFailed, 'Failed', 'Could not be answered'],
    'ringing' => ['ringing', null, 'Ringing', null],
]);

test('the card never shows routing or Meta internals', function () {
    $x = Lc::make()->preAccepted();

    $html = lccCardHtml(lccShow($x)->html());

    expect($html)->not->toBe('');
    expect($html)
        ->not->toContain(Lc::CALL_ID)
        ->not->toContain('pre_accepted')
        ->not->toContain($x->other->id)
        ->not->toContain(Lc::TOKEN)
        ->not->toContain(Wa::SDP_SENTINEL)
        ->not->toContain(Lc::ANSWER_SENTINEL);
});

// ── History size and ordering ───────────────────────────────────────────────

test('only the latest ten calls are shown, newest first', function () {
    $x = Lc::make();
    $x->call->delete();
    $ids = [];

    foreach (range(1, 12) as $i) {
        $ids[$i] = lccCall($x, ['status' => CallStatus::Missed, 'created_at' => now()->subHours(13 - $i)])->id;
    }

    $component = lccShow($x);
    $calls = $component->instance()->leadCalls;

    expect($calls)->toHaveCount(10)->and($calls->pluck('id')->all())->toBe(array_reverse(array_slice($ids, 2, 10, true), false));
    expect($calls->pluck('id')->all())->not->toContain($ids[1], $ids[2]);
});

// ── Merge, tenant, eager loading, live refresh ──────────────────────────────

test('a merged lead\'s calls appear on the surviving lead', function () {
    $x = Lc::make();
    $survivor = Lead::factory()->create(['tenant_id' => $x->tenant->id, 'source_id' => $x->source->id, 'email' => 'dup@example.com']);
    $x->lead->update(['email' => 'dup@example.com']);

    app(LeadMergeService::class)->merge($survivor, $x->lead, $x->admin, []);

    $calls = lccShow($x, $survivor)->instance()->leadCalls;

    expect($calls->pluck('id')->all())->toBe([$x->call->id]);
});

test('another lead\'s calls are not shown', function () {
    $x = Lc::make();
    $other = Lead::factory()->create(['tenant_id' => $x->tenant->id, 'source_id' => $x->source->id]);
    $mine = lccCall($x, ['status' => CallStatus::Missed], $other);

    expect(lccShow($x)->instance()->leadCalls->pluck('id')->all())->toBe([$x->call->id])->not->toContain($mine->id);
});

test('the claimants are loaded up front, never one query per call', function () {
    $x = Lc::make();
    foreach (range(1, 6) as $i) {
        lccCall($x, ['status' => CallStatus::Completed, 'claimed_by' => $x->agent->id]);
    }

    $component = lccShow($x);
    $calls = $component->instance()->leadCalls;

    expect($calls)->toHaveCount(7);
    expect($calls->every(fn (LeadCall $call): bool => $call->relationLoaded('claimedBy')))->toBeTrue();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $component->call('refreshCalls');
    $queries = array_column(DB::getQueryLog(), 'query');

    expect(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from "lead_calls"')))->toHaveCount(1);
});

test('the card refreshes when the user\'s own call.updated event arrives', function () {
    $x = Lc::make();
    $component = lccShow($x);

    expect($component->instance()->getListeners())->toHaveKey('echo-private:tenant-user.'.$x->admin->id.',.call.updated', 'refreshCalls');

    lccCall($x, ['status' => CallStatus::Missed, 'end_reason' => CallEndReason::CallerEndedOrNotAnswered, 'ended_at' => now()]);

    $component->call('refreshCalls')->assertSee('Caller hung up or nobody answered');
});
