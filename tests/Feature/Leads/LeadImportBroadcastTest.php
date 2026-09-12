<?php

use App\Enums\LeadImportStatus;
use App\Enums\LeadSourceType;
use App\Events\LeadImportStatusChanged;
use App\Imports\LeadCsvImport;
use App\Livewire\Tenant\LeadImports\Index;
use App\Livewire\Tenant\LeadImports\Show;
use App\Models\LeadImport;
use App\Models\LeadSource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * QA-06's Reverb architecture: App\Events\LeadImportStatusChanged fires at
 * Feature #7's two existing terminal finalization points only (never on
 * the Pending -> Processing transition, never per-row) — see
 * App\Imports\LeadCsvImport::registerEvents()/failed(). QUEUE_CONNECTION=sync
 * in the test environment runs the real chunk-chaining engine in-process
 * (same technique as LeadCsvImportEngineTest), so these assertions cover
 * the genuine finalization code path, not a simulated one.
 */
beforeEach(fn () => Storage::fake('local'));

test('a Completed import broadcasts LeadImportStatusChanged exactly once', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    Event::fake([LeadImportStatusChanged::class]);

    $csv = "name,email\nAlice,alice@example.com\n";

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->set('csvFile', UploadedFile::fake()->createWithContent('leads.csv', $csv))
        ->call('import');

    $import = LeadImport::where('tenant_id', $tenant->id)->firstOrFail();
    expect($import->status)->toBe(LeadImportStatus::Completed);

    Event::assertDispatchedTimes(LeadImportStatusChanged::class, 1);
    Event::assertDispatched(LeadImportStatusChanged::class, fn (LeadImportStatusChanged $event): bool => $event->leadImport->is($import));
});

test('a CompletedWithErrors import broadcasts LeadImportStatusChanged exactly once', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    Event::fake([LeadImportStatusChanged::class]);

    $csv = "name,email\n,missingname@example.com\nValid,valid@example.com\n";

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->set('csvFile', UploadedFile::fake()->createWithContent('mixed.csv', $csv))
        ->call('import');

    $import = LeadImport::where('tenant_id', $tenant->id)->firstOrFail();
    expect($import->status)->toBe(LeadImportStatus::CompletedWithErrors);

    Event::assertDispatchedTimes(LeadImportStatusChanged::class, 1);
    Event::assertDispatched(LeadImportStatusChanged::class, fn (LeadImportStatusChanged $event): bool => $event->leadImport->is($import));
});

test('a Failed import broadcasts LeadImportStatusChanged exactly once with the failure recorded', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::CsvImport]);
    $import = LeadImport::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    Event::fake([LeadImportStatusChanged::class]);

    (new LeadCsvImport($import->id))->failed(new Exception('simulated infrastructure fault'));

    $import->refresh();
    expect($import->status)->toBe(LeadImportStatus::Failed);
    expect($import->error_message)->toBe('simulated infrastructure fault');

    Event::assertDispatchedTimes(LeadImportStatusChanged::class, 1);
});

test('the broadcast payload contains only the LeadImport id', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::CsvImport]);
    $import = LeadImport::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    $event = new LeadImportStatusChanged($import);

    expect($event->broadcastWith())->toBe(['id' => $import->id]);
    expect($event->broadcastAs())->toBe('lead-import.status-changed');
    expect($event->broadcastOn())->toHaveCount(1);
    expect($event->broadcastOn()[0]->name)->toBe('private-lead-import.'.$import->id);
});

test('Show listens for the broadcast on this import\'s own private channel', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::CsvImport]);
    $import = LeadImport::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    $this->actingAs($clientAdmin);
    $component = new Show;
    $component->mount($import);

    expect($component->getListeners())->toBe([
        'echo-private:lead-import.'.$import->id.',.lead-import.status-changed' => 'refreshStatus',
    ]);
});

test('refreshStatus reflects a status change made outside the component — the same handler Reverb and the fallback poll both call', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::CsvImport]);
    $import = LeadImport::factory()->create([
        'tenant_id' => $tenant->id,
        'source_id' => $source->id,
        'status' => LeadImportStatus::Processing,
    ]);

    $test = Livewire::actingAs($clientAdmin)->test(Show::class, ['leadImport' => $import]);
    $test->assertSee('Processing');

    // Simulate the queue worker finishing concurrently, underneath the component.
    $import->update(['status' => LeadImportStatus::Completed, 'completed_at' => now()]);

    $test->call('refreshStatus')->assertSee('Completed');
});

test('the one-shot reconciliation timer is present while the import is non-terminal', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::CsvImport]);
    $import = LeadImport::factory()->create([
        'tenant_id' => $tenant->id,
        'source_id' => $source->id,
        'status' => LeadImportStatus::Processing,
    ]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['leadImport' => $import])
        ->assertSeeHtml('setTimeout(() => $wire.refreshStatus(), 5000)')
        ->assertDontSeeHtml('wire:poll');
});

test('the one-shot reconciliation timer is absent once the import reaches a terminal status', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::CsvImport]);
    $import = LeadImport::factory()->create([
        'tenant_id' => $tenant->id,
        'source_id' => $source->id,
        'status' => LeadImportStatus::Completed,
    ]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['leadImport' => $import])
        ->assertDontSeeHtml('setTimeout')
        ->assertDontSeeHtml('wire:poll');
});
