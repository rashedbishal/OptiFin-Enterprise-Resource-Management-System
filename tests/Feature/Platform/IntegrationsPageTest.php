<?php

use App\Enums\FailedEmailMessageStatus;
use App\Enums\IntegrationProblem;
use App\Enums\LeadSourceType;
use App\Enums\TokenCheckStatus;
use App\Livewire\Platform\Monitoring\Integrations;
use App\Models\FailedEmailMessage;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadSourceTokenCheck;
use App\Models\PlatformUser;
use App\Models\Tenant;
use App\Services\IntegrationHealth;
use App\Services\MetaTokenChecker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * @return list<IntegrationProblem>
 */
function problemsOf(LeadSource $source): array
{
    $row = collect(app(IntegrationHealth::class)->rows())->first(fn (array $row): bool => $row['source']->is($source));

    return array_map(fn (array $p): IntegrationProblem => $p['problem'], $row['problems'] ?? []);
}

function whatsappSource(array $attributes = []): LeadSource
{
    return LeadSource::factory()->create([
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '1', 'access_token' => 'EAAtoken-never-shown'],
        'last_synced_at' => now()->subHour(),
        ...$attributes,
    ]);
}

// ── Which sources are listed ───────────────────────────────────────────

test('only active sources of active clients are listed, without Manual or CSV import', function () {
    $listed = LeadSource::factory()->create(['last_synced_at' => now()]);
    $inactive = LeadSource::factory()->inactive()->create();
    $suspendedClient = LeadSource::factory()->create(['tenant_id' => Tenant::factory()->suspended()]);
    $manual = LeadSource::factory()->create(['type' => LeadSourceType::Manual]);
    $csv = LeadSource::factory()->create(['type' => LeadSourceType::CsvImport]);

    $ids = collect(app(IntegrationHealth::class)->rows())->map(fn (array $row): string => $row['source']->id)->all();

    expect($ids)->toContain($listed->id)
        ->not->toContain($inactive->id)
        ->not->toContain($suspendedClient->id)
        ->not->toContain($manual->id)
        ->not->toContain($csv->id);
});

test('leads in the last 7 and 30 days are counted per source, without merged-away leads', function () {
    $source = LeadSource::factory()->create(['last_synced_at' => now()]);
    $survivor = Lead::factory()->create(['tenant_id' => $source->tenant_id, 'source_id' => $source->id, 'created_at' => now()->subDays(2)]);
    Lead::factory()->create(['tenant_id' => $source->tenant_id, 'source_id' => $source->id, 'created_at' => now()->subDays(20)]);
    Lead::factory()->create(['tenant_id' => $source->tenant_id, 'source_id' => $source->id, 'created_at' => now()->subDay(), 'merged_into_lead_id' => $survivor->id]);

    $row = collect(app(IntegrationHealth::class)->rows())->first();

    expect($row['leads7'])->toBe(1)->and($row['leads30'])->toBe(2);
});

// ── Problem rules ──────────────────────────────────────────────────────

test('a source is quiet after 7 days without activity, but a new one is just "no activity yet"', function () {
    $quiet = LeadSource::factory()->create(['last_synced_at' => now()->subDays(8)]);
    $neverArrived = LeadSource::factory()->create(['last_synced_at' => null, 'created_at' => now()->subDays(10)]);
    $brandNew = LeadSource::factory()->create(['last_synced_at' => null, 'created_at' => now()->subDays(2)]);
    $active = LeadSource::factory()->create(['last_synced_at' => now()->subDays(6)]);

    expect(problemsOf($quiet))->toBe([IntegrationProblem::Quiet])
        ->and(problemsOf($neverArrived))->toBe([IntegrationProblem::Quiet])
        ->and(problemsOf($brandNew))->toBe([])
        ->and(problemsOf($active))->toBe([]);

    $row = collect(app(IntegrationHealth::class)->rows())->first(fn (array $row): bool => $row['source']->is($brandNew));
    expect($row['info'])->toBe('No activity yet');
});

test('a webhook signature rejection is a problem for 7 days', function () {
    $recent = LeadSource::factory()->create(['last_synced_at' => now(), 'last_signature_rejection_at' => now()->subDays(2), 'last_signature_rejection_reason' => 'Signature mismatch']);
    $old = LeadSource::factory()->create(['last_synced_at' => now(), 'last_signature_rejection_at' => now()->subDays(10)]);

    expect(problemsOf($recent))->toBe([IntegrationProblem::WebhookRejected])
        ->and(problemsOf($old))->toBe([]);
});

test('an email inbox that keeps failing to connect, or failed to import emails recently, is flagged', function () {
    $source = LeadSource::factory()->create(['type' => LeadSourceType::Email, 'last_synced_at' => now()]);
    Cache::put("ops:imap-connection-failures:{$source->id}", 5);
    FailedEmailMessage::factory()->create(['tenant_id' => $source->tenant_id, 'lead_source_id' => $source->id, 'status' => FailedEmailMessageStatus::Failed, 'first_failed_at' => now()->subDay()]);

    expect(problemsOf($source))->toBe([IntegrationProblem::InboxCannotConnect, IntegrationProblem::EmailsFailed]);
});

test('Meta token problems: missing, invalid, expiring within 7 days', function () {
    $missing = whatsappSource(['config' => ['phone_number_id' => '1']]);
    $invalid = whatsappSource();
    LeadSourceTokenCheck::factory()->invalid()->create(['lead_source_id' => $invalid->id]);
    $expiring = whatsappSource();
    LeadSourceTokenCheck::factory()->expiringIn(3)->create(['lead_source_id' => $expiring->id]);
    $fine = whatsappSource();
    LeadSourceTokenCheck::factory()->expiringIn(30)->create(['lead_source_id' => $fine->id]);

    expect(problemsOf($missing))->toBe([IntegrationProblem::TokenMissing])
        ->and(problemsOf($invalid))->toBe([IntegrationProblem::TokenInvalid])
        ->and(problemsOf($expiring))->toBe([IntegrationProblem::TokenExpiresSoon])
        ->and(problemsOf($fine))->toBe([]);
});

test('WhatsApp calling switched on in OptiFin but off at Meta is flagged', function () {
    $source = whatsappSource(['calling_enabled' => true, 'calling_meta_status' => 'DISABLED', 'calling_meta_checked_at' => now()]);
    LeadSourceTokenCheck::factory()->create(['lead_source_id' => $source->id]);

    expect(problemsOf($source))->toBe([IntegrationProblem::CallingOffAtMeta]);
});

test('rows are ordered: serious problems, then warnings, then healthy', function () {
    $healthy = LeadSource::factory()->create(['last_synced_at' => now()]);
    $warning = LeadSource::factory()->create(['last_synced_at' => now()->subDays(9)]);
    $serious = LeadSource::factory()->create(['last_synced_at' => now(), 'last_signature_rejection_at' => now()]);

    $order = collect(app(IntegrationHealth::class)->rows())->map(fn (array $row): string => $row['source']->id)->all();

    expect($order)->toBe([$serious->id, $warning->id, $healthy->id]);
});

// ── Daily Meta token check ─────────────────────────────────────────────

test('the token check records valid tokens and their expiry, without storing the token', function () {
    $never = whatsappSource();
    $expiring = whatsappSource(['config' => ['phone_number_id' => '2', 'access_token' => 'EAAexpiring']]);
    Http::fake(function ($request) {
        return str_contains($request->url(), 'EAAexpiring')
            ? Http::response(['data' => ['is_valid' => true, 'expires_at' => now()->addDays(5)->timestamp]])
            : Http::response(['data' => ['is_valid' => true, 'expires_at' => 0]]);
    });

    app(MetaTokenChecker::class)->check($never);
    app(MetaTokenChecker::class)->check($expiring);

    expect($never->tokenCheck()->first())
        ->status->toBe(TokenCheckStatus::Valid)
        ->expires_at->toBeNull()
        ->expiry_known->toBeTrue();
    expect($expiring->tokenCheck()->first()->expires_at->isSameDay(now()->addDays(5)))->toBeTrue();
    expect(json_encode(DB::table('lead_source_token_checks')->get()))->not->toContain('EAA');
});

test('the token check marks rejected tokens invalid, missing tokens missing, and Meta outages unreachable', function () {
    $rejected = whatsappSource();
    $missing = whatsappSource(['config' => []]);
    $outage = whatsappSource(['config' => ['access_token' => 'EAAoutage']]);
    Http::fake(function ($request) {
        return str_contains($request->url(), 'EAAoutage')
            ? Http::response([], 503)
            : Http::response(['error' => ['message' => 'Error validating access token: Session has expired.', 'code' => 190]], 400);
    });

    expect(app(MetaTokenChecker::class)->check($rejected))->status->toBe(TokenCheckStatus::Invalid)
        ->error->toContain('Session has expired');
    expect(app(MetaTokenChecker::class)->check($missing)->status)->toBe(TokenCheckStatus::Missing);
    expect(app(MetaTokenChecker::class)->check($outage)->status)->toBe(TokenCheckStatus::Unreachable);
});

test('Instagram-login tokens are checked on graph.instagram.com, with expiry unknown', function () {
    $source = LeadSource::factory()->create(['type' => LeadSourceType::Instagram, 'config' => ['auth_mode' => 'instagram', 'instagram_account_id' => '1', 'access_token' => 'IGtoken']]);
    Http::fake(['graph.instagram.com/*' => Http::response(['id' => '1'])]);

    $check = app(MetaTokenChecker::class)->check($source);

    expect($check->status)->toBe(TokenCheckStatus::Valid)->and($check->expiry_known)->toBeFalse();
    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://graph.instagram.com/me'));
});

test('the daily command checks every active Meta source and nothing else', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => ['is_valid' => true, 'expires_at' => 0]])]);
    $meta = whatsappSource();
    $inactive = whatsappSource(['is_active' => false]);
    $notMeta = LeadSource::factory()->create();

    $this->artisan('integrations:check-meta-tokens')->assertSuccessful();

    expect(LeadSourceTokenCheck::pluck('lead_source_id')->all())->toBe([$meta->id]);
});

// ── Page ───────────────────────────────────────────────────────────────

test('the page shows problems only by default, can show all, and searches by client', function () {
    $broken = LeadSource::factory()->create(['last_synced_at' => now()->subDays(9), 'tenant_id' => Tenant::factory()->create(['name' => 'Acme Broken Ltd'])]);
    $fine = LeadSource::factory()->create(['last_synced_at' => now(), 'tenant_id' => Tenant::factory()->create(['name' => 'Zeta Fine Ltd'])]);
    $admin = PlatformUser::factory()->motherAdmin()->create();

    Livewire::actingAs($admin, 'platform')->test(Integrations::class)
        ->assertSee('Acme Broken Ltd')
        ->assertDontSee('Zeta Fine Ltd')
        ->set('problemsOnly', false)
        ->assertSee('Zeta Fine Ltd')
        ->set('search', 'zeta')
        ->assertSee('Zeta Fine Ltd')
        ->assertDontSee('Acme Broken Ltd')
        ->assertSeeHtml(route('admin.clients.lead-sources', $fine->tenant));
});
