<?php

use App\Livewire\Tenant\Leads\Index;
use App\Models\Lead;
use Livewire\Livewire;

/**
 * The Kanban board is the only view that renders partials.lead-card
 * (whole-card shimmer), so these all act as a rep (clientUserWithLeadPermission),
 * never the client admin — Index::render() picks the table view for admins.
 * The Client Admin's table has its own, separate shimmer on just the Lead ID
 * badge (same lead-alert-shimmer CSS class, reused rather than duplicated) —
 * see LeadIdShimmerRenderTest.php for that view's coverage.
 */
test('a lead inside its tenant\'s meeting alert window shows the shimmer class on the Kanban board', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $tenant->update(['meeting_alert_enabled' => true, 'meeting_alert_days_before' => 3]);
    $rep = clientUserWithLeadPermission($tenant);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id, 'meeting_date' => today()->addDay()]);

    Livewire::actingAs($rep)
        ->test(Index::class)
        ->assertSeeHtml('lead-alert-shimmer');
});

test('a lead outside the alert window shows no shimmer', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $tenant->update(['meeting_alert_enabled' => true, 'meeting_alert_days_before' => 3]);
    $rep = clientUserWithLeadPermission($tenant);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id, 'meeting_date' => today()->addDays(10)]);

    Livewire::actingAs($rep)
        ->test(Index::class)
        ->assertDontSeeHtml('lead-alert-shimmer');
});

test('a lead in the window shows no shimmer while the tenant setting is disabled', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id, 'meeting_date' => today()]);

    Livewire::actingAs($rep)
        ->test(Index::class)
        ->assertDontSeeHtml('lead-alert-shimmer');
});

test('a lead with both meeting and follow-up alerts active still gets only one shimmer class', function () {
    // The board renders each card twice in one page (a desktop list and a
    // separate "lg:hidden" mobile list, toggled by CSS, not two templates),
    // so a single card's class shows up twice in the HTML regardless. What
    // "no double shimmer" actually guards against is a *second* shimmer
    // class being added per extra active alert type — so the real
    // assertion is that a lead with both alerts active renders the class
    // exactly as many times as one with only a single alert active, not
    // that the raw count is 1.
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $tenant->update([
        'meeting_alert_enabled' => true, 'meeting_alert_days_before' => 3,
        'followup_alert_enabled' => true, 'followup_alert_days_before' => 3,
    ]);
    $rep = clientUserWithLeadPermission($tenant);
    Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'assigned_to' => $rep->id,
        'meeting_date' => today(),
        'next_follow_up_at' => today(),
    ]);

    $bothActiveHtml = Livewire::actingAs($rep)->test(Index::class)->html();

    $tenantSingle = tenantWithLeadManagement()['tenant'];
    $tenantSingle->update(['meeting_alert_enabled' => true, 'meeting_alert_days_before' => 3]);
    $repSingle = clientUserWithLeadPermission($tenantSingle);
    Lead::factory()->create(['tenant_id' => $tenantSingle->id, 'assigned_to' => $repSingle->id, 'meeting_date' => today()]);

    $singleActiveHtml = Livewire::actingAs($repSingle)->test(Index::class)->html();

    expect(substr_count($bothActiveHtml, 'lead-alert-shimmer'))
        ->toBe(substr_count($singleActiveHtml, 'lead-alert-shimmer'));
});
