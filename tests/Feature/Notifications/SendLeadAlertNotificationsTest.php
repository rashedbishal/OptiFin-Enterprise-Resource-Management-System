<?php

use App\Enums\TenantStatus;
use App\Models\Lead;
use App\Notifications\FollowUpAlertNotification;
use App\Notifications\MeetingAlertNotification;
use Illuminate\Support\Facades\DB;

test('a suspended tenant is skipped entirely, even with alerts enabled and a due meeting', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['meeting_alert_enabled' => true, 'meeting_alert_days_before' => 2, 'status' => TenantStatus::Suspended]);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => now()->addDays(2)]);

    $this->artisan('leads:send-alert-notifications')->assertSuccessful();

    expect($clientAdmin->notifications()->count())->toBe(0);
});

test('a client admin is notified once a lead\'s meeting alert window opens', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['meeting_alert_enabled' => true, 'meeting_alert_days_before' => 2]);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => now()->addDays(2)]);

    $this->artisan('leads:send-alert-notifications')->assertSuccessful();

    expect($clientAdmin->notifications()->where('type', MeetingAlertNotification::class)->count())->toBe(1);
});

test('a lead outside its meeting alert window does not notify anyone', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['meeting_alert_enabled' => true, 'meeting_alert_days_before' => 2]);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => now()->addDays(10)]);

    $this->artisan('leads:send-alert-notifications');

    expect($clientAdmin->notifications()->count())->toBe(0);
});

test('a disabled meeting alert setting sends nothing even for a due meeting', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['meeting_alert_enabled' => false, 'meeting_alert_days_before' => 2]);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => now()]);

    $this->artisan('leads:send-alert-notifications');

    expect($clientAdmin->notifications()->count())->toBe(0);
});

test('only the assigned rep and admins are notified for a follow-up alert, not an unrelated rep', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['followup_alert_enabled' => true, 'followup_alert_days_before' => 1]);
    $assignedRep = clientUserWithLeadPermission($tenant);
    $otherRep = clientUserWithLeadPermission($tenant);
    Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'next_follow_up_at' => now()->addDay(),
        'assigned_to' => $assignedRep->id,
    ]);

    $this->artisan('leads:send-alert-notifications');

    expect($clientAdmin->notifications()->where('type', FollowUpAlertNotification::class)->count())->toBe(1);
    expect($assignedRep->notifications()->where('type', FollowUpAlertNotification::class)->count())->toBe(1);
    expect($otherRep->notifications()->count())->toBe(0);
});

test('running the sweep twice in the same day does not duplicate a notification', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['meeting_alert_enabled' => true, 'meeting_alert_days_before' => 0]);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => now()]);

    $this->artisan('leads:send-alert-notifications');
    $this->artisan('leads:send-alert-notifications');

    expect($clientAdmin->notifications()->where('type', MeetingAlertNotification::class)->count())->toBe(1);
});

test('a cancelled meeting (null meeting_date) is never alerted on', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['meeting_alert_enabled' => true, 'meeting_alert_days_before' => 5]);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => null]);

    $this->artisan('leads:send-alert-notifications');

    expect($clientAdmin->notifications()->count())->toBe(0);
});

test('a merged-away lead\'s meeting alert never notifies anyone, while an eligible survivor still does', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['meeting_alert_enabled' => true, 'meeting_alert_days_before' => 2]);

    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => now()->addDays(2)]);
    Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'meeting_date' => now()->addDays(2),
        'merged_into_lead_id' => $survivor->id,
    ]);

    $this->artisan('leads:send-alert-notifications')->assertSuccessful();

    // Exactly one notification — proves the merged-away lead produced none
    // of its own while the eligible survivor still notified as expected.
    expect($clientAdmin->notifications()->where('type', MeetingAlertNotification::class)->count())->toBe(1);
});

// ── REL-4 — chunkById() pagination regression coverage ─────────────────────
//
// sweep()'s Lead-level query uses chunkById(200, ...) rather than each().
// The tests below exercise that exact query shape directly (same
// tenant/date-column/merged-lead filters sweep() itself uses) so a genuine
// multi-page run, and a row disappearing between pages, can be deterministically
// controlled and observed — something the full `leads:send-alert-notifications`
// command has no seam to intercept mid-run. sweep()'s own notify/dedup
// callback logic is already fully covered by the tests above, unmodified.

test('the sweep notifies every qualifying lead across more than one chunkById page', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['meeting_alert_enabled' => true, 'meeting_alert_days_before' => 2]);

    // 201 leads — one more than the production chunkById(200, ...) page
    // size, forcing a genuine second page fetch.
    Lead::factory()->count(201)->create(['tenant_id' => $tenant->id, 'meeting_date' => now()->addDays(2)]);

    $this->artisan('leads:send-alert-notifications')->assertSuccessful();

    // Every one of the 201 leads across both pages produced its own
    // notification — none silently dropped at the page boundary.
    expect($clientAdmin->notifications()->where('type', MeetingAlertNotification::class)->count())->toBe(201);
});

test('deleting an already-processed lead mid-sweep does not skip a later qualifying lead (REL-4 regression)', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $leads = Lead::factory()->count(4)->create(['tenant_id' => $tenant->id, 'meeting_date' => now()->addDays(2)])
        ->sortBy('id')->values();

    $processedIds = [];
    $pageNumber = 0;

    // Mirrors sweep()'s exact Lead-level query shape and filters.
    $tenant->leads()
        ->whereNotNull('meeting_date')
        ->whereNull('merged_into_lead_id')
        ->with('assignedUser')
        ->chunkById(2, function ($page) use (&$processedIds, &$pageNumber): void {
            $pageNumber++;

            foreach ($page as $lead) {
                $processedIds[] = $lead->id;
            }

            // Simulate a Client Admin deleting a lead from the UI between
            // pages — the exact REL-4 failure trigger — mid-sweep. The
            // deleted lead's id is already recorded above (this page's
            // batch was fetched and processed before the delete), so it is
            // still expected in $processedIds below — what this proves is
            // that its deletion has no effect on page 2's cursor, unlike
            // each()/chunk()'s OFFSET, which would have silently dropped a
            // *different*, still-pending lead from page 2 as a side effect.
            if ($pageNumber === 1) {
                $page->first()->delete();
            }
        });

    // All 4 original leads are accounted for — none silently dropped.
    expect($processedIds)->toBe($leads->pluck('id')->values()->all());
});

test('a lead merged away between pages is excluded without skipping a later qualifying lead, tenant isolation and eager loading preserved (REL-4 regression)', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    ['tenant' => $otherTenant] = tenantWithLeadManagement();

    // No meeting_date on the survivor — it's purely a merge target here,
    // and must never itself match the query below (whereNotNull('meeting_date')),
    // or it would confuse the expected processed set.
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'meeting_date' => null]);
    $leads = Lead::factory()->count(4)->create(['tenant_id' => $tenant->id, 'meeting_date' => now()->addDays(2)])
        ->sortBy('id')->values();
    Lead::factory()->count(2)->create(['tenant_id' => $otherTenant->id, 'meeting_date' => now()->addDays(2)]);

    $processedIds = [];
    $pageNumber = 0;

    DB::enableQueryLog();
    $tenant->leads()
        ->whereNotNull('meeting_date')
        ->whereNull('merged_into_lead_id')
        ->with('assignedUser')
        ->chunkById(2, function ($page) use (&$processedIds, &$pageNumber, $survivor): void {
            $pageNumber++;

            foreach ($page as $lead) {
                $processedIds[] = $lead->id;
                // assignedUser was eager-loaded above — accessing it here
                // must not trigger an additional query per row.
                $lead->assignedUser;
            }

            // Merge the first lead of page 1 into a survivor — the same
            // whereNull('merged_into_lead_id') disqualification sweep()
            // itself relies on. Already recorded above before this update,
            // so still expected in $processedIds below; what this proves is
            // that the merge has no effect on page 2's cursor.
            if ($pageNumber === 1) {
                $page->first()->update(['merged_into_lead_id' => $survivor->id]);
            }
        });
    $queryLog = DB::getQueryLog();
    DB::disableQueryLog();

    // All 4 original leads are accounted for — none silently dropped.
    expect($processedIds)->toBe($leads->pluck('id')->values()->all());
    // Never touches the other tenant's leads, or the (non-matching) survivor.
    expect($processedIds)->not->toContain($survivor->id);

    // 2 page-fetch queries (page 1 of 2, page 2 of 1 remaining) + up to 2
    // eager-load queries for assignedUser (one per page) + the merge update
    // — no per-row N+1 from accessing assignedUser above.
    $selectQueries = collect($queryLog)->filter(fn (array $q): bool => str_starts_with(trim($q['query']), 'select'));
    expect($selectQueries->count())->toBeLessThanOrEqual(4);
});
