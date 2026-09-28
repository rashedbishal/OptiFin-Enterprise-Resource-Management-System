<?php

use App\Actions\Tenant\RecordLeadConsent;
use App\Enums\ConsentSource;
use App\Enums\ConsentStatus;
use App\Enums\ConsentType;
use App\Models\Lead;
use App\Models\LeadConsent;
use App\Models\LeadConsentEvent;
use Illuminate\Support\Facades\DB;

/*
 * Batch 7 — F1. RecordLeadConsent::handle() called
 * LeadConsent::updateOrCreate(['lead_id' => ..., 'consent_type' => ...],
 * $attributes) directly — no recovery when two concurrent callers (Grant/
 * Withdraw, a Website Form submission, two admins) both see no existing row
 * and both attempt create(), so the loser's INSERT hit the real
 * unique(lead_id, consent_type) constraint uncaught.
 *
 * These tests force the real interleaving via a DB::listen() hook on the
 * fix's own fast-path SELECT: the moment that query completes and finds
 * nothing, a competing row is inserted directly — landing BEFORE the fix's
 * own create() attempt (and its savepoint) even runs, exactly matching how
 * two genuinely separate concurrent connections would behave. Racing inside
 * a `creating` event nested in the create() call itself would insert the
 * "winner" INSIDE that create()'s own savepoint, so a losing rollback would
 * silently undo the winner too — a same-connection artifact that can't
 * happen for two real concurrent connections (see
 * LeadSourceSingletonRaceTest's own docblock for the same reasoning).
 */

function raceOnFirstConsentSelect(string $leadId, ConsentType $type, string $tenantId, ConsentStatus $winnerStatus): void
{
    $raced = false;

    DB::listen(function ($query) use (&$raced, $leadId, $type, $tenantId, $winnerStatus) {
        if ($raced) {
            return;
        }

        if (! str_starts_with(ltrim($query->sql), 'select') || ! str_contains($query->sql, 'lead_consents')) {
            return;
        }

        if (! in_array($leadId, $query->bindings, true) || ! in_array($type->value, $query->bindings, true)) {
            return;
        }

        $raced = true;

        LeadConsent::query()->create([
            'tenant_id' => $tenantId,
            'lead_id' => $leadId,
            'consent_type' => $type,
            'status' => $winnerStatus,
            'source' => ConsentSource::Manual,
            'occurred_at' => now(),
        ]);
    });
}

test('two concurrent grants for the same lead+type recover instead of throwing, and this call still wins as the last write', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    raceOnFirstConsentSelect($lead->id, ConsentType::Privacy, $tenant->id, ConsentStatus::Withdrawn);

    $consent = app(RecordLeadConsent::class)->handle(
        lead: $lead,
        type: ConsentType::Privacy,
        status: ConsentStatus::Granted,
        source: ConsentSource::Manual,
    );

    expect(LeadConsent::where('lead_id', $lead->id)->where('consent_type', ConsentType::Privacy)->count())->toBe(1);
    expect($consent->status)->toBe(ConsentStatus::Granted);
    expect(LeadConsent::where('lead_id', $lead->id)->where('consent_type', ConsentType::Privacy)->first()->status)->toBe(ConsentStatus::Granted);
    // The transaction was not poisoned by the caught race: the history
    // event write that runs immediately after still succeeds.
    expect(LeadConsentEvent::where('lead_id', $lead->id)->count())->toBe(1);
});

test('an unrelated consent type for the same lead is not affected by the race', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    raceOnFirstConsentSelect($lead->id, ConsentType::Privacy, $tenant->id, ConsentStatus::Withdrawn);

    app(RecordLeadConsent::class)->handle($lead, ConsentType::Privacy, ConsentStatus::Granted, ConsentSource::Manual);
    app(RecordLeadConsent::class)->handle($lead, ConsentType::Marketing, ConsentStatus::Granted, ConsentSource::Manual);

    expect(LeadConsent::where('lead_id', $lead->id)->count())->toBe(2);
    expect(LeadConsent::where('lead_id', $lead->id)->where('consent_type', ConsentType::Marketing)->first()->status)->toBe(ConsentStatus::Granted);
});

test('the normal update path still works when a row already exists (no race)', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    app(RecordLeadConsent::class)->handle($lead, ConsentType::Privacy, ConsentStatus::Granted, ConsentSource::Manual);
    $consent = app(RecordLeadConsent::class)->handle($lead, ConsentType::Privacy, ConsentStatus::Withdrawn, ConsentSource::Manual);

    expect(LeadConsent::where('lead_id', $lead->id)->count())->toBe(1);
    expect($consent->status)->toBe(ConsentStatus::Withdrawn);
    expect(LeadConsentEvent::where('lead_id', $lead->id)->count())->toBe(2);
});
