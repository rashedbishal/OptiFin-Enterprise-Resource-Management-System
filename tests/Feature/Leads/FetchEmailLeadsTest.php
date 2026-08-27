<?php

use App\Enums\LeadSourceType;
use App\Jobs\ProcessEmailLeadSource;
use App\Models\LeadSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

function emailSourceConfig(): array
{
    return [
        'email_address' => 'inbox@example.com',
        'imap_host' => 'imap.example.com',
        'imap_port' => 993,
        'imap_username' => 'inbox@example.com',
        'imap_password' => 'secret',
        'fetch_interval_minutes' => 15,
    ];
}

test('leads:fetch-emails fails gracefully when the imap extension is unavailable', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Email,
        'last_synced_at' => null,
        'config' => emailSourceConfig(),
    ]);

    $this->artisan('leads:fetch-emails')
        ->expectsOutputToContain('The IMAP extension is not available on this server.')
        ->assertExitCode(Command::FAILURE);
})->skip(extension_loaded('imap'), 'Requires the imap extension to be unavailable');

test('leads:fetch-emails fetches a source that has never been synced', function () {
    Bus::fake();
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Email,
        'last_synced_at' => null,
        'config' => emailSourceConfig(),
    ]);

    $this->artisan('leads:fetch-emails')->assertExitCode(Command::SUCCESS);

    Bus::assertDispatched(ProcessEmailLeadSource::class, fn ($job) => $job->source->is($source));
});

test('leads:fetch-emails skips a source that was synced within its fetch interval', function () {
    Bus::fake();
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Email,
        'last_synced_at' => now()->subMinutes(5),
        'config' => emailSourceConfig(),
    ]);

    $this->artisan('leads:fetch-emails')->assertExitCode(Command::SUCCESS);

    Bus::assertNotDispatched(ProcessEmailLeadSource::class);
});

test('leads:fetch-emails fetches a source once its fetch interval has elapsed', function () {
    Bus::fake();
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Email,
        'last_synced_at' => now()->subMinutes(20),
        'config' => emailSourceConfig(),
    ]);

    $this->artisan('leads:fetch-emails')->assertExitCode(Command::SUCCESS);

    Bus::assertDispatched(ProcessEmailLeadSource::class, fn ($job) => $job->source->is($source));
});
