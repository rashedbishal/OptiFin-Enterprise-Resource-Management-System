<?php

use App\Enums\CallStatus;
use App\Enums\LeadSourceType;
use App\Livewire\Tenant\Leads\Index;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Models\LeadDeletionAudit;
use App\Models\LeadSource;
use App\Services\LeadDeletionEligibilityService;
use Livewire\Livewire;

/*
 * WhatsApp inbound calling — a Lead's calls cascade-delete with it (like every
 * other lead child), are counted in the deletion audit's cascade_summary, and
 * a Lead with a Ringing, Answering or Connected call cannot be deleted at all.
 * Terminal calls never block.
 */

/**
 * A tenant, its admin, a deletable Manual-source lead, and a WhatsApp source
 * the lead's calls arrive on.
 *
 * @return array{tenant: mixed, admin: mixed, lead: Lead, whatsapp: LeadSource}
 */
function leadCallDeletionScenario(): array
{
    ['tenant' => $tenant, 'clientAdmin' => $admin] = tenantWithLeadManagement();
    $manual = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Manual]);
    $whatsapp = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Whatsapp]);
    // Unqualified: Show::confirmDelete()/delete() now also require this
    // (see their own docblocks) — set here so every test in this file
    // isolates the active-call protection this file is actually about,
    // not incidentally blocked by that separate, newer check instead.
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $manual->id, 'status_id' => leadStatus('unqualified')->id]);

    return compact('tenant', 'admin', 'lead', 'whatsapp');
}

/**
 * @return array<int, array{0: ?string, 1: ?string}> [variant, text] of every toast the last request dispatched
 */
function leadCallDeletionToasts($component): array
{
    return collect($component->effects['dispatches'] ?? [])
        ->where('name', 'toast-show')
        ->map(fn (array $dispatch): array => [$dispatch['params']['dataset']['variant'] ?? null, $dispatch['params']['slots']['text'] ?? null])
        ->values()
        ->all();
}

// ── Deleting a lead with only finished calls ────────────────────────────────

test('deleting a lead also deletes its finished calls and leaves other leads\' calls alone', function () {
    $x = leadCallDeletionScenario();
    $other = Lead::factory()->create(['tenant_id' => $x['tenant']->id, 'source_id' => $x['lead']->source_id]);

    LeadCall::factory()->completed()->create(['tenant_id' => $x['tenant']->id, 'lead_id' => $x['lead']->id, 'lead_source_id' => $x['whatsapp']->id]);
    LeadCall::factory()->missed()->create(['tenant_id' => $x['tenant']->id, 'lead_id' => $x['lead']->id, 'lead_source_id' => $x['whatsapp']->id]);
    $otherCall = LeadCall::factory()->connected()->create(['tenant_id' => $x['tenant']->id, 'lead_id' => $other->id, 'lead_source_id' => $x['whatsapp']->id]);

    $component = Livewire::actingAs($x['admin'])->test(Index::class)->call('completeInitialLoad')->set('deletePassword', 'password')->call('delete', $x['lead']->id);

    expect(leadCallDeletionToasts($component))->toBe([['success', 'Lead deleted.']]);
    expect(Lead::find($x['lead']->id))->toBeNull();
    expect(LeadCall::where('lead_id', $x['lead']->id)->count())->toBe(0);
    expect($otherCall->fresh())->not->toBeNull();
});

test('the deletion audit\'s cascade_summary counts the calls destroyed with the lead', function () {
    $x = leadCallDeletionScenario();
    LeadCall::factory()->count(2)->completed()->create(['tenant_id' => $x['tenant']->id, 'lead_id' => $x['lead']->id, 'lead_source_id' => $x['whatsapp']->id]);

    Livewire::actingAs($x['admin'])->test(Index::class)->call('completeInitialLoad')->set('deletePassword', 'password')->call('delete', $x['lead']->id);

    expect(LeadDeletionAudit::first()->cascade_summary['calls'])->toBe(2);
});

test('a lead with no calls still deletes and records zero calls in the audit', function () {
    $x = leadCallDeletionScenario();

    Livewire::actingAs($x['admin'])->test(Index::class)->call('completeInitialLoad')->set('deletePassword', 'password')->call('delete', $x['lead']->id);

    expect(Lead::find($x['lead']->id))->toBeNull();
    expect(LeadDeletionAudit::first()->cascade_summary['calls'])->toBe(0);
});

// ── Deleting a lead with an active call is refused on every path ────────────

test('Index::delete refuses a lead with an active call and changes nothing', function () {
    $x = leadCallDeletionScenario();
    $call = LeadCall::factory()->create(['tenant_id' => $x['tenant']->id, 'lead_id' => $x['lead']->id, 'lead_source_id' => $x['whatsapp']->id]);

    $component = Livewire::actingAs($x['admin'])->test(Index::class)->call('completeInitialLoad')->set('deletePassword', 'password')->call('delete', $x['lead']->id);

    expect(leadCallDeletionToasts($component))->toBe([['danger', LeadDeletionEligibilityService::activeCallReason()]]);
    expect(Lead::find($x['lead']->id))->not->toBeNull();
    expect($call->fresh()->status)->toBe(CallStatus::Ringing);
    expect(LeadDeletionAudit::count())->toBe(0);
});

test('Index::confirmDeleteLead refuses early for an active call instead of opening the password modal', function () {
    $x = leadCallDeletionScenario();
    LeadCall::factory()->answering()->create(['tenant_id' => $x['tenant']->id, 'lead_id' => $x['lead']->id, 'lead_source_id' => $x['whatsapp']->id]);

    $component = Livewire::actingAs($x['admin'])->test(Index::class)->call('completeInitialLoad')->call('confirmDeleteLead', $x['lead']->id);

    expect($component->get('showDeleteLeadModal'))->toBeFalse();
    expect(leadCallDeletionToasts($component))->toBe([['danger', LeadDeletionEligibilityService::activeCallReason()]]);
});

test('Show::confirmDelete refuses early for an active call instead of opening the password modal', function () {
    $x = leadCallDeletionScenario();
    LeadCall::factory()->answering()->create(['tenant_id' => $x['tenant']->id, 'lead_id' => $x['lead']->id, 'lead_source_id' => $x['whatsapp']->id]);

    $component = Livewire::actingAs($x['admin'])->test(Show::class, ['lead' => $x['lead']])->call('completeInitialLoad')->call('confirmDelete');

    expect($component->get('showDeleteModal'))->toBeFalse();
    expect(leadCallDeletionToasts($component))->toBe([['danger', LeadDeletionEligibilityService::activeCallReason()]]);
});

test('Show::delete refuses a lead with an active call: no delete, no redirect, nothing changed', function () {
    $x = leadCallDeletionScenario();
    $call = LeadCall::factory()->connected()->create(['tenant_id' => $x['tenant']->id, 'lead_id' => $x['lead']->id, 'lead_source_id' => $x['whatsapp']->id]);

    $component = Livewire::actingAs($x['admin'])->test(Show::class, ['lead' => $x['lead']])->call('completeInitialLoad')->set('deletePassword', 'password')->call('delete');

    expect(leadCallDeletionToasts($component))->toBe([['danger', LeadDeletionEligibilityService::activeCallReason()]]);
    expect($component->effects['redirect'] ?? null)->toBeNull();
    expect(Lead::find($x['lead']->id))->not->toBeNull();
    expect($call->fresh())->not->toBeNull();
    expect(LeadDeletionAudit::count())->toBe(0);
});

test('a bulk delete skips the lead with an active call and still deletes the others', function () {
    $x = leadCallDeletionScenario();
    $plain = Lead::factory()->create(['tenant_id' => $x['tenant']->id, 'source_id' => $x['lead']->source_id]);
    LeadCall::factory()->create(['tenant_id' => $x['tenant']->id, 'lead_id' => $x['lead']->id, 'lead_source_id' => $x['whatsapp']->id]);

    Livewire::actingAs($x['admin'])->test(Index::class)->call('completeInitialLoad')
        ->set('selectedLeadIds', [$x['lead']->id, $plain->id])
        ->call('confirmBulkDelete')
        ->set('deletePassword', 'password')
        ->call('deleteLeadConfirmed');

    expect(Lead::find($x['lead']->id))->not->toBeNull();
    expect(Lead::find($plain->id))->toBeNull();
    expect(LeadDeletionAudit::count())->toBe(1);
});

test('a lead becomes deletable as soon as its call reaches a terminal status', function () {
    $x = leadCallDeletionScenario();
    $call = LeadCall::factory()->connected()->create(['tenant_id' => $x['tenant']->id, 'lead_id' => $x['lead']->id, 'lead_source_id' => $x['whatsapp']->id]);
    $service = app(LeadDeletionEligibilityService::class);

    expect($service->evaluate($x['lead'])->eligible)->toBeFalse();

    $call->update(['status' => CallStatus::Completed]);

    expect($service->evaluate($x['lead'])->eligible)->toBeTrue();

    Livewire::actingAs($x['admin'])->test(Index::class)->call('completeInitialLoad')->set('deletePassword', 'password')->call('delete', $x['lead']->id);

    expect(Lead::find($x['lead']->id))->toBeNull();
});

// ── The evaluator itself ────────────────────────────────────────────────────

test('the evaluator blocks each active call status and allows each terminal one', function (CallStatus $status) {
    $x = leadCallDeletionScenario();
    LeadCall::factory()->create([
        'tenant_id' => $x['tenant']->id,
        'lead_id' => $x['lead']->id,
        'lead_source_id' => $x['whatsapp']->id,
        'status' => $status,
    ]);
    $service = app(LeadDeletionEligibilityService::class);

    foreach ([true, false] as $lock) {
        $verdict = $service->evaluate($x['lead'], lock: $lock);

        expect($verdict->eligible)->toBe($status->isTerminal());
        expect($verdict->reason)->toBe($status->isTerminal() ? null : LeadDeletionEligibilityService::activeCallReason());
    }
})->with(array_combine(
    array_map(fn (CallStatus $status): string => $status->value, CallStatus::cases()),
    array_map(fn (CallStatus $status): array => [$status], CallStatus::cases()),
));

test('the evaluator\'s active-call lookup is tenant-scoped', function () {
    $x = leadCallDeletionScenario();
    ['tenant' => $foreignTenant] = tenantWithLeadManagement();
    $foreignSource = LeadSource::factory()->create(['tenant_id' => $foreignTenant->id, 'type' => LeadSourceType::Whatsapp]);

    // Unreachable through the app (a call is always the lead's own tenant),
    // forced here only to prove the lookup can never see another tenant's rows.
    LeadCall::factory()->create(['tenant_id' => $foreignTenant->id, 'lead_id' => $x['lead']->id, 'lead_source_id' => $foreignSource->id]);

    expect(app(LeadDeletionEligibilityService::class)->evaluate($x['lead'])->eligible)->toBeTrue();
});
