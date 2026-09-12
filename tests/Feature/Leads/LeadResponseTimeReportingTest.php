<?php

use App\Livewire\Tenant\LeadReports\Index;
use App\Models\Lead;
use App\Services\LeadReportingService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('the responded count only includes leads with a non-null first_contacted_at', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    Lead::factory()->count(2)->create([
        'tenant_id' => $tenant->id,
        'created_at' => now()->subDays(2),
        'first_contacted_at' => now()->subDays(2)->addHours(3),
    ]);
    Lead::factory()->count(3)->create([
        'tenant_id' => $tenant->id,
        'created_at' => now()->subDays(2),
        'first_contacted_at' => null,
    ]);

    $summary = app(LeadReportingService::class)->firstResponseSummary(
        $tenant, now()->subDays(29)->startOfDay(), now()->addDay()->startOfDay()
    );

    expect($summary['responded_count'])->toBe(2);
});

test('not responded is derived as total leads minus responded leads', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Lead::factory()->count(2)->create([
        'tenant_id' => $tenant->id,
        'created_at' => now(),
        'first_contacted_at' => now()->addHour(),
    ]);
    Lead::factory()->count(3)->create([
        'tenant_id' => $tenant->id,
        'created_at' => now(),
        'first_contacted_at' => null,
    ]);

    $testable = Livewire::actingAs($clientAdmin)->test(Index::class);

    expect($testable->instance()->volumeCount)->toBe(5);
    expect($testable->instance()->firstResponseSummary['responded_count'])->toBe(2);
    expect($testable->instance()->firstResponseSummary['not_responded_count'])->toBe(3);
});

test('average first response time is correct across responded leads', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $base = now()->subDays(2);

    Lead::factory()->create(['tenant_id' => $tenant->id, 'created_at' => $base, 'first_contacted_at' => $base->copy()->addHours(2)]);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'created_at' => $base, 'first_contacted_at' => $base->copy()->addHours(4)]);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'created_at' => $base, 'first_contacted_at' => $base->copy()->addHours(6)]);

    $summary = app(LeadReportingService::class)->firstResponseSummary(
        $tenant, now()->subDays(29)->startOfDay(), now()->addDay()->startOfDay()
    );

    expect($summary['average_hours'])->toBe(4.0);
});

test('median first response time is correct for an odd number of responded leads', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $base = now()->subDays(2);

    Lead::factory()->create(['tenant_id' => $tenant->id, 'created_at' => $base, 'first_contacted_at' => $base->copy()->addHour()]);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'created_at' => $base, 'first_contacted_at' => $base->copy()->addHours(2)]);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'created_at' => $base, 'first_contacted_at' => $base->copy()->addHours(3)]);

    $summary = app(LeadReportingService::class)->firstResponseSummary(
        $tenant, now()->subDays(29)->startOfDay(), now()->addDay()->startOfDay()
    );

    expect($summary['median_hours'])->toBe(2.0);
});

test('median first response time is correct for an even number of responded leads', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $base = now()->subDays(2);

    Lead::factory()->create(['tenant_id' => $tenant->id, 'created_at' => $base, 'first_contacted_at' => $base->copy()->addHour()]);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'created_at' => $base, 'first_contacted_at' => $base->copy()->addHours(2)]);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'created_at' => $base, 'first_contacted_at' => $base->copy()->addHours(3)]);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'created_at' => $base, 'first_contacted_at' => $base->copy()->addHours(4)]);

    $summary = app(LeadReportingService::class)->firstResponseSummary(
        $tenant, now()->subDays(29)->startOfDay(), now()->addDay()->startOfDay()
    );

    expect($summary['median_hours'])->toBe(2.5);
});

test('average and median are null, never zero, when no lead in range has been responded to', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Lead::factory()->create(['tenant_id' => $tenant->id, 'created_at' => now(), 'first_contacted_at' => null]);

    $summary = app(LeadReportingService::class)->firstResponseSummary(
        $tenant, now()->subDays(29)->startOfDay(), now()->addDay()->startOfDay()
    );

    expect($summary['average_hours'])->toBeNull();
    expect($summary['median_hours'])->toBeNull();

    Livewire::actingAs($clientAdmin)->test(Index::class)
        ->assertSee('No data')
        ->assertDontSee('0 hours')
        ->assertDontSee('0.0 hours');
});

test('a merged-away lead is excluded from the response-time summary', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $survivor = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'created_at' => now(),
        'first_contacted_at' => now()->addHour(),
    ]);
    Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'created_at' => now(),
        'first_contacted_at' => now()->addMinutes(30),
        'merged_into_lead_id' => $survivor->id,
    ]);

    $summary = app(LeadReportingService::class)->firstResponseSummary(
        $tenant, now()->subDays(29)->startOfDay(), now()->addDay()->startOfDay()
    );

    expect($summary['responded_count'])->toBe(1);
});

test('the response-time summary is tenant-isolated', function () {
    ['tenant' => $tenantA] = tenantWithLeadManagement();
    ['tenant' => $tenantB] = tenantWithLeadManagement();

    Lead::factory()->count(3)->create([
        'tenant_id' => $tenantB->id,
        'created_at' => now(),
        'first_contacted_at' => now()->addHour(),
    ]);
    Lead::factory()->create([
        'tenant_id' => $tenantA->id,
        'created_at' => now(),
        'first_contacted_at' => now()->addHour(),
    ]);

    $summary = app(LeadReportingService::class)->firstResponseSummary(
        $tenantA, now()->subDays(29)->startOfDay(), now()->addDay()->startOfDay()
    );

    expect($summary['responded_count'])->toBe(1);
});

test('a lead closed without ever being contacted counts as not responded, excluded from average and median', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    // Simulates a CSV-imported lead created directly in a closed status —
    // no status "transition" ever ran, so first_contacted_at was never set.
    Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'status_id' => leadStatus('lost')->id,
        'closed_at' => now(),
        'created_at' => now(),
        'first_contacted_at' => null,
    ]);
    Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'created_at' => now(),
        'first_contacted_at' => now()->addHours(2),
    ]);

    $testable = Livewire::actingAs($clientAdmin)->test(Index::class);

    expect($testable->instance()->firstResponseSummary['not_responded_count'])->toBe(1);
    expect($testable->instance()->firstResponseSummary['responded_count'])->toBe(1);
    expect($testable->instance()->firstResponseSummary['average_hours'])->toBe(2.0);
});

test('a lead reopened after its first response keeps its original response time', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $base = now()->subDays(2);
    $originalResponse = $base->copy()->addHours(5);

    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'status_id' => leadStatus('contacted')->id,
        'created_at' => $base,
        'first_contacted_at' => $originalResponse,
    ]);

    // Reopen: move back to the initial status. The approved architecture
    // explicitly does not touch how first_contacted_at is captured — it
    // must remain at its original value (the existing ?? now() idempotency
    // guard in Show.php/Index.php is what guarantees this in production).
    $lead->update(['status_id' => leadStatus('new')->id]);

    $summary = app(LeadReportingService::class)->firstResponseSummary(
        $tenant, now()->subDays(29)->startOfDay(), now()->addDay()->startOfDay()
    );

    expect($summary['responded_count'])->toBe(1);
    expect($summary['average_hours'])->toBe(5.0);
});

test('a response that lands after the reporting window ends still uses its full actual duration', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $from = now()->subDays(9)->startOfDay();
    $to = now()->subDays(5)->addDay()->startOfDay(); // window ends 5 days ago

    $createdInWindow = now()->subDays(6);
    $respondedAfterWindow = now()->subDays(1); // 5 days after created_at, well past $to

    Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'created_at' => $createdInWindow,
        'first_contacted_at' => $respondedAfterWindow,
    ]);

    $summary = app(LeadReportingService::class)->firstResponseSummary($tenant, $from, $to);

    expect($summary['responded_count'])->toBe(1);
    expect($summary['average_hours'])->toBe(round($createdInWindow->diffInSeconds($respondedAfterWindow) / 3600, 1));
});

test('a Client User is still forbidden from the reporting page with the response-time block present', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view']);

    Livewire::actingAs($rep)->test(Index::class)->assertForbidden();
});

test('the response-time summary computation issues exactly one query', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    Lead::factory()->count(5)->create([
        'tenant_id' => $tenant->id,
        'created_at' => now(),
        'first_contacted_at' => now()->addHour(),
    ]);

    DB::enableQueryLog();
    app(LeadReportingService::class)->firstResponseSummary(
        $tenant, now()->subDays(29)->startOfDay(), now()->addDay()->startOfDay()
    );
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queryCount)->toBe(1);
});

test('rendering the reports page with the response-time block remains within the existing query budget', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Lead::factory()->count(10)->create([
        'tenant_id' => $tenant->id,
        'created_at' => now(),
        'first_contacted_at' => now()->addHour(),
    ]);

    DB::enableQueryLog();
    Livewire::actingAs($clientAdmin)->test(Index::class);
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queryCount)->toBeLessThan(20);
});
