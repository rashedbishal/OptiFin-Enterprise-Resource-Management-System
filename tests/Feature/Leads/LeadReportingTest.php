<?php

use App\Livewire\Tenant\LeadReports\Index;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Services\LeadReportingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('a Client Admin sees the correct lead volume for the selected range', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Lead::factory()->count(3)->create(['tenant_id' => $tenant->id, 'created_at' => now()->subDays(5)]);
    Lead::factory()->count(2)->create(['tenant_id' => $tenant->id, 'created_at' => now()->subDays(40)]); // outside default 30-day window

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->assertSet('fromDate', now()->subDays(29)->toDateString())
        ->assertSet('toDate', now()->toDateString());

    expect(app(LeadReportingService::class)->volumeCount(
        $tenant, now()->subDays(29)->startOfDay(), now()->addDay()->startOfDay()
    ))->toBe(3);
});

test('a Client User is forbidden from the reporting page', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view']);

    Livewire::actingAs($rep)->test(Index::class)->assertForbidden();
});

test('a user without lead-management view permission is forbidden', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, []);

    Livewire::actingAs($rep)->test(Index::class)->assertForbidden();
});

test('reporting is tenant-isolated: another tenant\'s leads never appear', function () {
    ['tenant' => $tenantA, 'clientAdmin' => $adminA] = tenantWithLeadManagement();
    ['tenant' => $tenantB] = tenantWithLeadManagement();

    Lead::factory()->count(4)->create(['tenant_id' => $tenantB->id, 'created_at' => now()]);
    Lead::factory()->count(1)->create(['tenant_id' => $tenantA->id, 'created_at' => now()]);

    Livewire::actingAs($adminA)->test(Index::class)->assertSee('1');

    $count = app(LeadReportingService::class)->volumeCount(
        $tenantA, now()->subDays(29)->startOfDay(), now()->addDay()->startOfDay()
    );

    expect($count)->toBe(1);
});

test('a merged-away lead is excluded from every metric', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'created_at' => now()]);
    Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'created_at' => now(),
        'merged_into_lead_id' => $survivor->id,
    ]);

    $service = app(LeadReportingService::class);
    $from = now()->subDays(29)->startOfDay();
    $to = now()->addDay()->startOfDay();

    expect($service->volumeCount($tenant, $from, $to))->toBe(1);
});

test('date boundaries match the existing FiltersLeadsQuery semantics exactly', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $onFromDate = Lead::factory()->create(['tenant_id' => $tenant->id, 'created_at' => Carbon::parse('2026-01-05 00:00:00')]);
    $onToDate = Lead::factory()->create(['tenant_id' => $tenant->id, 'created_at' => Carbon::parse('2026-01-10 23:59:59')]);
    $beforeRange = Lead::factory()->create(['tenant_id' => $tenant->id, 'created_at' => Carbon::parse('2026-01-04 23:59:59')]);
    $afterRange = Lead::factory()->create(['tenant_id' => $tenant->id, 'created_at' => Carbon::parse('2026-01-11 00:00:00')]);

    $count = app(LeadReportingService::class)->volumeCount(
        $tenant,
        Carbon::parse('2026-01-05')->startOfDay(),
        Carbon::parse('2026-01-10')->addDay()->startOfDay(),
    );

    expect($count)->toBe(2);
});

test('the 92-day maximum range is enforced without silently truncating', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->set('fromDate', now()->subDays(92)->toDateString())
        ->set('toDate', now()->toDateString())
        ->assertSee('92 days or fewer');
});

test('exactly a 92-day range is accepted', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->set('fromDate', now()->subDays(91)->toDateString())
        ->set('toDate', now()->toDateString())
        ->assertDontSee('too wide');
});

test('a from date after the to date is rejected', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->set('fromDate', now()->toDateString())
        ->set('toDate', now()->subDays(5)->toDateString())
        ->assertSee('must be on or before');
});

test('the daily volume breakdown is zero-filled for days with no leads', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Lead::factory()->create(['tenant_id' => $tenant->id, 'created_at' => now()->subDays(2)]);

    $days = app(LeadReportingService::class)->dailyVolumeBreakdown(
        $tenant, now()->subDays(4)->startOfDay(), now()->addDay()->startOfDay()
    );

    expect($days)->toHaveCount(5);
    expect(collect($days)->sum('count'))->toBe(1);
    expect(collect($days)->firstWhere('date', now()->subDays(4)->toDateString())['count'])->toBe(0);
});

test('the daily volume table paginates at 50 rows per page', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $testable = Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->set('fromDate', now()->subDays(91)->toDateString())
        ->set('toDate', now()->toDateString());

    $dailyBreakdown = $testable->instance()->dailyBreakdown;

    expect($dailyBreakdown->count())->toBe(50);
    expect($dailyBreakdown->total())->toBe(92);
});

test('the status distribution is zero-filled against the tenant\'s configured pipeline', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $newStatus = leadStatus('new');
    Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => $newStatus->id, 'created_at' => now()]);

    $breakdown = app(LeadReportingService::class)->statusBreakdown(
        $tenant, now()->subDays(29)->startOfDay(), now()->addDay()->startOfDay()
    );

    expect($breakdown)->toHaveCount($tenant->leadStatuses()->count());
    $wonRow = collect($breakdown)->firstWhere('status', leadStatus('won')->id);
    expect($wonRow['count'])->toBe(0);
});

test('an inactive source\'s historical leads remain included and are badged inactive in the UI', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'is_active' => false]);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'created_at' => now()]);

    $breakdown = app(LeadReportingService::class)->sourceBreakdown(
        $tenant, now()->subDays(29)->startOfDay(), now()->addDay()->startOfDay()
    );

    expect($breakdown->firstWhere('source.id', $source->id)['count'])->toBe(1);

    Livewire::actingAs($clientAdmin)->test(Index::class)->assertSeeHtml('Inactive');
});

test('unassigned leads form their own bucket', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => null, 'created_at' => now()]);

    $breakdown = app(LeadReportingService::class)->assignmentBreakdown(
        $tenant, now()->subDays(29)->startOfDay(), now()->addDay()->startOfDay()
    );

    expect($breakdown)->toHaveCount(1);
    expect($breakdown->first()['assignee'])->toBeNull();
    expect($breakdown->first()['count'])->toBe(1);
});

test('rendering the reports page issues a bounded number of queries regardless of row count', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    LeadSource::factory()->count(3)->create(['tenant_id' => $tenant->id])->each(
        fn (LeadSource $source) => Lead::factory()->count(5)->create([
            'tenant_id' => $tenant->id,
            'source_id' => $source->id,
            'created_at' => now(),
        ])
    );

    DB::enableQueryLog();
    Livewire::actingAs($clientAdmin)->test(Index::class);
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queryCount)->toBeLessThan(20);
});

test('the sidebar shows the Reports & Analytics link to a Client Admin with the module', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $this->actingAs($clientAdmin)->get(route('dashboard'))->assertSee(route('tenant-leads.reports'), false);
});

test('the sidebar hides the Reports & Analytics link from a Client User', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view']);

    $this->actingAs($rep)->get(route('dashboard'))->assertDontSee(route('tenant-leads.reports'), false);
});
