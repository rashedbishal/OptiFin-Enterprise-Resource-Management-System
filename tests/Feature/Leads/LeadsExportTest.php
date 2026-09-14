<?php

use App\Enums\LeadPriority;
use App\Enums\LeadSourceType;
use App\Livewire\Tenant\Leads\Index;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\TenantUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Decodes the CSV the "export" action streamed to the browser (captured by
 * Livewire's own SupportFileDownloads hook, which actually executes the
 * StreamedResponse's callback via sendContent() during the test call — see
 * vendor/livewire/livewire/src/Features/SupportFileDownloads/SupportFileDownloads.php)
 * into an array of row arrays, header row included at index 0.
 *
 * @return array<int, array<int, string>>
 */
function exportedCsvRows(Testable $component): array
{
    $content = base64_decode((string) data_get($component->effects, 'download.content'));
    $lines = array_filter(preg_split('/\r\n|\r|\n/', $content), fn (string $line): bool => $line !== '');

    return array_map(fn (string $line): array => str_getcsv($line), $lines);
}

/**
 * The exported "Lead ID" column (first column) for every data row, header
 * row excluded — the simplest stable way to assert which leads a given
 * filter/scope combination did or didn't include.
 *
 * @return array<int, string>
 */
function exportedIdentifiers(Testable $component): array
{
    $rows = exportedCsvRows($component);
    array_shift($rows);

    return array_map(fn (array $row): string => $row[0], $rows);
}

test('a client admin can export the filtered leads as a csv download', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    Lead::factory()->create(['tenant_id' => $tenant->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('export')
        ->assertFileDownloaded('leads.csv', null, 'text/csv');
});

test('the export csv has the exact expected header row', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    Lead::factory()->create(['tenant_id' => $tenant->id]);

    $component = Livewire::actingAs($clientAdmin)->test(Index::class)->call('completeInitialLoad')->call('export');

    expect(exportedCsvRows($component)[0])->toBe([
        'Lead ID', 'Name', 'Email', 'Phone', 'Source', 'Status', 'Priority', 'Assigned To', 'Created At', 'Closed At',
    ]);
});

test('the export writes each lead\'s exact csv row, including relationship-derived columns, without lazy-loading them', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::WebsiteForm]);
    $status = leadStatus('contacted');
    $rep = TenantUser::factory()->create(['tenant_id' => $tenant->id]);

    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'source_id' => $source->id,
        'status_id' => $status->id,
        'priority' => LeadPriority::High,
        'assigned_to' => $rep->id,
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'phone' => '+1-555-0100',
        'closed_at' => now()->subDay(),
    ]);

    Model::preventLazyLoading();

    try {
        $component = Livewire::actingAs($clientAdmin)->test(Index::class)->call('completeInitialLoad')->call('export');
    } finally {
        Model::preventLazyLoading(false);
    }

    expect(exportedCsvRows($component)[1])->toBe([
        $lead->identifier,
        'Jane Doe',
        'jane@example.com',
        '+1-555-0100',
        LeadSourceType::WebsiteForm->label(),
        $status->name,
        LeadPriority::High->value,
        $rep->name,
        $lead->created_at->toDateTimeString(),
        $lead->closed_at->toDateTimeString(),
    ]);
});

test('a lead name beginning with a formula-trigger character is neutralized in the export', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => '=HYPERLINK("http://evil.example/","Click")',
    ]);

    $component = Livewire::actingAs($clientAdmin)->test(Index::class)->call('completeInitialLoad')->call('export');

    expect(exportedCsvRows($component)[1][1])->toBe('\'=HYPERLINK("http://evil.example/","Click")');
});

test('a lead email beginning with a formula-trigger character is neutralized in the export', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => '=1+1@evil.com',
    ]);

    $component = Livewire::actingAs($clientAdmin)->test(Index::class)->call('completeInitialLoad')->call('export');

    expect(exportedCsvRows($component)[1][2])->toBe("'=1+1@evil.com");
});

test('an ordinary lead name and email are exported unchanged, with no spurious prefix', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
    ]);

    $component = Livewire::actingAs($clientAdmin)->test(Index::class)->call('completeInitialLoad')->call('export');

    $row = exportedCsvRows($component)[1];
    expect($row[1])->toBe('Jane Doe');
    expect($row[2])->toBe('jane@example.com');
});

test('a lead name containing a comma and quote is still correctly csv-quoted alongside the formula sanitizer', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => "O'Brien, Inc.",
    ]);

    $component = Livewire::actingAs($clientAdmin)->test(Index::class)->call('completeInitialLoad')->call('export');

    expect(exportedCsvRows($component)[1][1])->toBe("O'Brien, Inc.");
});

test('the export writes "Unassigned" for a lead with no assigned user', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => null]);

    $component = Livewire::actingAs($clientAdmin)->test(Index::class)->call('completeInitialLoad')->call('export');

    expect(exportedCsvRows($component)[1][7])->toBe('Unassigned');
});

test('the export respects the status filter', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $contactedStatus = leadStatus('contacted');
    $qualifiedStatus = leadStatus('qualified');
    $match = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => $contactedStatus->id]);
    $other = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => $qualifiedStatus->id]);

    $component = Livewire::actingAs($clientAdmin)->test(Index::class)->call('completeInitialLoad')
        ->set('status', $contactedStatus->id)
        ->call('export');

    $identifiers = exportedIdentifiers($component);
    expect($identifiers)->toContain($match->identifier);
    expect($identifiers)->not->toContain($other->identifier);
});

test('the export respects the search filter', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $match = Lead::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Jane Doe']);
    $other = Lead::factory()->create(['tenant_id' => $tenant->id, 'name' => 'John Smith']);

    $component = Livewire::actingAs($clientAdmin)->test(Index::class)->call('completeInitialLoad')
        ->set('search', 'Jane')
        ->call('export');

    $identifiers = exportedIdentifiers($component);
    expect($identifiers)->toContain($match->identifier);
    expect($identifiers)->not->toContain($other->identifier);
});

test('the export respects the source type filter', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $websiteSource = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::WebsiteForm]);
    $emailSource = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Email]);
    $match = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $websiteSource->id]);
    $other = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $emailSource->id]);

    $component = Livewire::actingAs($clientAdmin)->test(Index::class)->call('completeInitialLoad')
        ->set('sourceType', LeadSourceType::WebsiteForm->value)
        ->call('export');

    $identifiers = exportedIdentifiers($component);
    expect($identifiers)->toContain($match->identifier);
    expect($identifiers)->not->toContain($other->identifier);
});

test('a non-admin client user\'s export only includes their own assigned leads', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view']);
    $own = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id]);
    $other = Lead::factory()->create(['tenant_id' => $tenant->id]);

    $component = Livewire::actingAs($rep)->test(Index::class)->call('completeInitialLoad')->call('export');

    $identifiers = exportedIdentifiers($component);
    expect($identifiers)->toContain($own->identifier);
    expect($identifiers)->not->toContain($other->identifier);
});

test('the export cannot leak another tenant\'s leads', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    ['tenant' => $otherTenant] = tenantWithLeadManagement();
    $other = Lead::factory()->create(['tenant_id' => $otherTenant->id, 'name' => 'Cross Tenant Lead']);

    $component = Livewire::actingAs($clientAdmin)->test(Index::class)->call('completeInitialLoad')->call('export');

    expect(exportedIdentifiers($component))->not->toContain($other->identifier);
});

test('the export excludes merged-away leads', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id, 'merged_into_lead_id' => $survivor->id]);

    $component = Livewire::actingAs($clientAdmin)->test(Index::class)->call('completeInitialLoad')->call('export');

    $identifiers = exportedIdentifiers($component);
    expect($identifiers)->toContain($survivor->identifier);
    expect($identifiers)->not->toContain($loser->identifier);
});

test('the export includes every lead across multiple chunk boundaries, with no duplicates or gaps', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id]);
    $statusId = $tenant->initialLeadStatus()->id;

    // 250 leads at the 200-row chunk size used by export() spans exactly
    // two chunkById() pages (200 + 50) — the boundary this test exists to
    // prove nothing is lost or duplicated across.
    $leads = Lead::factory()->count(250)->create([
        'tenant_id' => $tenant->id,
        'source_id' => $source->id,
        'status_id' => $statusId,
    ]);

    $component = Livewire::actingAs($clientAdmin)->test(Index::class)->call('completeInitialLoad')->call('export');

    $identifiers = exportedIdentifiers($component);

    expect($identifiers)->toHaveCount(250);
    expect(array_unique($identifiers))->toHaveCount(250);
    expect(collect($identifiers)->sort()->values()->all())
        ->toBe($leads->pluck('identifier')->sort()->values()->all());
});

test('the export runs multiple bounded chunked queries instead of one unbounded get() for a large dataset', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id]);
    $statusId = $tenant->initialLeadStatus()->id;

    Lead::factory()->count(250)->create([
        'tenant_id' => $tenant->id,
        'source_id' => $source->id,
        'status_id' => $statusId,
    ]);

    $component = Livewire::actingAs($clientAdmin)->test(Index::class)->call('completeInitialLoad');

    DB::flushQueryLog();
    DB::enableQueryLog();

    $component->call('export');

    // Filtered on the literal chunk-size limit (200) rather than just "any
    // leads query with a LIMIT" — the post-action re-render's own leads()
    // pagination also issues a bounded "leads" query (LIMIT 15/10), so
    // matching on the chunk size specifically is what isolates chunkById()'s
    // own paging behavior from that unrelated, incidental query.
    $chunkPageQueries = collect(DB::getQueryLog())
        ->filter(fn (array $entry): bool => str_contains(strtolower((string) $entry['query']), 'from "leads"')
            && str_contains(strtolower((string) $entry['query']), 'limit 200'))
        ->values();

    DB::disableQueryLog();

    // 250 leads at chunk size 200 must page in exactly 2 queries (200 rows,
    // then the remaining 50) — a single unbounded get() would show zero
    // "leads" queries carrying this chunk-size LIMIT at all.
    expect($chunkPageQueries)->toHaveCount(2);
});
