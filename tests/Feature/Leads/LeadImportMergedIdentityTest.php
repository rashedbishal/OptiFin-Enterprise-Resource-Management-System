<?php

use App\Actions\Tenant\CreateLeadFromImport;
use App\Enums\LeadImportRowStatus;
use App\Enums\LeadSourceType;
use App\Livewire\Tenant\LeadImports\Show;
use App\Models\Lead;
use App\Models\LeadImport;
use App\Models\LeadImportRow;
use App\Models\LeadSource;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Services\LeadMergeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

/**
 * INC-1 D — an identity that lives on a merged-away lead belongs to the ACTIVE
 * top survivor of its merge chain: a CSV row carrying it is a duplicate of
 * that survivor and never creates a new lead. Merges are made with the real
 * LeadMergeService (default resolution: the survivor keeps its own values, so
 * the loser's identity stays only on the loser row — the exact former bug).
 */

/**
 * @return array{tenant: Tenant, admin: TenantUser, source: LeadSource}
 */
function mergedIdentityTenant(): array
{
    ['tenant' => $tenant, 'clientAdmin' => $admin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::CsvImport, 'name' => 'CSV Import']);

    return compact('tenant', 'admin', 'source');
}

/**
 * A survivor and a loser with distinct identities, the loser merged into the survivor.
 *
 * @param  array<string, mixed>  $survivor
 * @param  array<string, mixed>  $loser
 * @param  array<string, string>  $resolutions
 * @return array{0: Lead, 1: Lead}
 */
function mergedPair(array $ctx, string $tag, array $survivor = [], array $loser = [], array $resolutions = []): array
{
    $seed = $tag;
    $tag = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($tag)), '-');
    $s = Lead::factory()->create(array_merge(['tenant_id' => $ctx['tenant']->id, 'source_id' => $ctx['source']->id, 'name' => 'Survivor', 'email' => "s-{$tag}@synthetic.test", 'phone' => '5551'.str_pad((string) (crc32($seed) % 1000000), 6, '0', STR_PAD_LEFT)], $survivor));
    $l = Lead::factory()->create(array_merge(['tenant_id' => $ctx['tenant']->id, 'source_id' => $ctx['source']->id, 'name' => 'Loser', 'email' => "l-{$tag}@synthetic.test", 'phone' => '5552'.str_pad((string) (crc32($seed) % 1000000), 6, '0', STR_PAD_LEFT)], $loser));

    app(LeadMergeService::class)->merge($s, $l, $ctx['admin'], $resolutions);

    return [$s->fresh(), $l->fresh()];
}

/**
 * @param  array<string, mixed>  $row
 */
function importIdentityRow(array $ctx, array $row, int $rowNumber = 1, ?LeadImport $import = null): LeadImportRow
{
    $import ??= LeadImport::factory()->create(['tenant_id' => $ctx['tenant']->id, 'source_id' => $ctx['source']->id, 'imported_by' => $ctx['admin']->id]);

    app(CreateLeadFromImport::class)->handle($import, $ctx['tenant'], $ctx['source'], $ctx['admin'], $rowNumber, $row);

    return LeadImportRow::where('lead_import_id', $import->id)->where('row_number', $rowNumber)->firstOrFail();
}

/**
 * Two survivors S1/S2, each with its own merged loser, where BOTH losers hold the same email
 * (and, optionally, the same phone) — so one identity maps to two distinct active survivors.
 *
 * @return array{0: Lead, 1: Lead, 2: string, 3: string} S1, S2, shared email, shared phone
 */
function ambiguousSurvivors(array $ctx, string $tag, bool $sharePhone = false): array
{
    $email = "shared-{$tag}@synthetic.test";
    $phone = '5553'.str_pad((string) (crc32("shared-{$tag}") % 1000000), 6, '0', STR_PAD_LEFT);
    $loser = ['email' => $email] + ($sharePhone ? ['phone' => $phone] : []);

    [$s1] = mergedPair($ctx, "{$tag}-a", [], $loser);
    [$s2] = mergedPair($ctx, "{$tag}-b", [], $loser);

    return [$s1, $s2, $email, $phone];
}

function activeLeadCount(Tenant $tenant): int
{
    return Lead::where('tenant_id', $tenant->id)->whereNull('merged_into_lead_id')->count();
}

test('former D bug: a row carrying only a merged loser\'s email, phone, or both is a duplicate of the survivor and creates no lead', function (string $case) {
    $ctx = mergedIdentityTenant();
    [$survivor, $loser] = mergedPair($ctx, $case);
    $leadCount = Lead::where('tenant_id', $ctx['tenant']->id)->count();

    // Sanity: this is exactly the old-bug shape — the survivor never took the loser's identity.
    expect($survivor->email_normalized)->not->toBe($loser->email_normalized)->and($survivor->phone_normalized)->not->toBe($loser->phone_normalized);

    $row = importIdentityRow($ctx, match ($case) {
        'email' => ['name' => 'Loser Again', 'email' => strtoupper($loser->email)],
        'phone' => ['name' => 'Loser Again', 'phone' => $loser->phone],
        'both' => ['name' => 'Loser Again', 'email' => $loser->email, 'phone' => $loser->phone],
    });

    expect($row->status)->toBe(LeadImportRowStatus::Duplicate);
    expect($row->duplicate_of_lead_id)->toBe($survivor->id);
    expect(Lead::where('tenant_id', $ctx['tenant']->id)->count())->toBe($leadCount);
})->with(['loser email only' => ['email'], 'loser phone only' => ['phone'], 'both loser identities' => ['both']]);

test('a loser identity plus a brand-new other identity is still a duplicate of the survivor', function (string $case) {
    $ctx = mergedIdentityTenant();
    [$survivor, $loser] = mergedPair($ctx, $case);

    $row = importIdentityRow($ctx, $case === 'email'
        ? ['name' => 'Loser New', 'email' => $loser->email, 'phone' => '5559990001']
        : ['name' => 'Loser New', 'email' => 'brand-new@synthetic.test', 'phone' => $loser->phone]);

    expect($row->status)->toBe(LeadImportRowStatus::Duplicate);
    expect($row->duplicate_of_lead_id)->toBe($survivor->id);
    expect(activeLeadCount($ctx['tenant']))->toBe(1);
})->with(['loser email + new phone' => ['email'], 'loser phone + new email' => ['phone']]);

test('a loser identity combined with the survivor\'s own identity resolves to the same survivor: a duplicate, not a conflict', function (string $case) {
    $ctx = mergedIdentityTenant();
    [$survivor, $loser] = mergedPair($ctx, $case);

    $row = importIdentityRow($ctx, $case === 'loser email + survivor phone'
        ? ['name' => 'Mixed One', 'email' => $loser->email, 'phone' => $survivor->phone]
        : ['name' => 'Mixed Two', 'email' => $survivor->email, 'phone' => $loser->phone]);

    expect($row->status)->toBe(LeadImportRowStatus::Duplicate);
    expect($row->duplicate_of_lead_id)->toBe($survivor->id);
    expect($row->errors)->toBe(['Matches an existing lead (email or phone already on file).']);
    expect(activeLeadCount($ctx['tenant']))->toBe(1);
})->with(['loser email + survivor phone' => ['loser email + survivor phone'], 'survivor email + loser phone' => ['survivor email + loser phone']]);

test('email and phone resolving to two DIFFERENT survivors is the existing A1-style conflict — no lead, no arbitrary pick', function () {
    $ctx = mergedIdentityTenant();
    [$s1, $l1] = mergedPair($ctx, 'one');
    [$s2, $l2] = mergedPair($ctx, 'two');
    $expected = ['Email matches an existing lead and phone matches a different existing lead — rejected as a duplicate conflict.'];

    // both through merged losers
    $row = importIdentityRow($ctx, ['name' => 'Conflict One', 'email' => $l1->email, 'phone' => $l2->phone]);
    expect($row->status)->toBe(LeadImportRowStatus::Duplicate)->and($row->duplicate_of_lead_id)->toBeNull()->and($row->errors)->toBe($expected);

    // an ACTIVE survivor's email with another survivor's merged loser's phone
    $row = importIdentityRow($ctx, ['name' => 'Conflict Two', 'email' => $s1->email, 'phone' => $l2->phone]);
    expect($row->status)->toBe(LeadImportRowStatus::Duplicate)->and($row->duplicate_of_lead_id)->toBeNull()->and($row->errors)->toBe($expected);

    expect(activeLeadCount($ctx['tenant']))->toBe(2);
});

test('a multi-hop chain (B merged into A, then A merged into C) resolves to the top survivor C', function () {
    $ctx = mergedIdentityTenant();
    [$a, $b] = mergedPair($ctx, 'hop');
    $c = Lead::factory()->create(['tenant_id' => $ctx['tenant']->id, 'source_id' => $ctx['source']->id, 'name' => 'Top', 'email' => 'top@synthetic.test', 'phone' => null]);
    app(LeadMergeService::class)->merge($c, $a, $ctx['admin'], []);

    // A merge never re-points earlier losers: B still points at A, A at C.
    expect($b->fresh()->merged_into_lead_id)->toBe($a->id)->and($a->fresh()->merged_into_lead_id)->toBe($c->id);

    foreach ([['email' => $b->email], ['phone' => $b->phone], ['email' => $a->email]] as $n => $identity) {
        $row = importIdentityRow($ctx, ['name' => 'Chain Row'] + $identity, $n + 1);

        expect($row->status)->toBe(LeadImportRowStatus::Duplicate)->and($row->duplicate_of_lead_id)->toBe($c->id);
    }

    expect(activeLeadCount($ctx['tenant']))->toBe(1);
});

test('the loser\'s identity stays claimed after the survivor changes its own identity', function () {
    $ctx = mergedIdentityTenant();
    [$survivor, $loser] = mergedPair($ctx, 'edit');
    $survivorOldEmail = $survivor->email;

    $survivor->update(['email' => 'survivor-changed@synthetic.test']);

    $viaLoser = importIdentityRow($ctx, ['name' => 'Loser Late', 'email' => $loser->email], 1);
    expect($viaLoser->status)->toBe(LeadImportRowStatus::Duplicate)->and($viaLoser->duplicate_of_lead_id)->toBe($survivor->id);

    $viaNewEmail = importIdentityRow($ctx, ['name' => 'Survivor New', 'email' => 'survivor-changed@synthetic.test'], 2);
    expect($viaNewEmail->duplicate_of_lead_id)->toBe($survivor->id);

    // The survivor's OLD email is held by no lead any more: it is not part of any merge chain, so it imports normally.
    $viaOld = importIdentityRow($ctx, ['name' => 'Survivor Old', 'email' => $survivorOldEmail], 3);
    expect($viaOld->status)->toBe(LeadImportRowStatus::Imported);
});

test('an identity chosen onto the survivor during the merge is an ordinary active match', function () {
    $ctx = mergedIdentityTenant();
    [$survivor, $loser] = mergedPair($ctx, 'chosen', resolutions: ['email' => 'loser']);

    expect($survivor->email_normalized)->toBe($loser->email_normalized);

    $row = importIdentityRow($ctx, ['name' => 'Chosen Row', 'email' => $loser->email]);

    expect($row->status)->toBe(LeadImportRowStatus::Duplicate)->and($row->duplicate_of_lead_id)->toBe($survivor->id);
});

test('an active holder and a merged holder of the SAME survivor collapse into one candidate: an ordinary duplicate', function () {
    $ctx = mergedIdentityTenant();
    $survivor = Lead::factory()->create(['tenant_id' => $ctx['tenant']->id, 'source_id' => $ctx['source']->id, 'name' => 'Survivor', 'email' => 'shared@synthetic.test']);
    $loser = Lead::factory()->create(['tenant_id' => $ctx['tenant']->id, 'source_id' => $ctx['source']->id, 'name' => 'Loser', 'email' => 'shared@synthetic.test']);
    app(LeadMergeService::class)->merge($survivor, $loser, $ctx['admin'], []);

    $row = importIdentityRow($ctx, ['name' => 'Shared Row', 'email' => 'shared@synthetic.test']);

    expect($row->status)->toBe(LeadImportRowStatus::Duplicate)->and($row->duplicate_of_lead_id)->toBe($survivor->id);
    expect(activeLeadCount($ctx['tenant']))->toBe(1);
});

test('an active holder never outranks a merged holder that resolves elsewhere: both stay candidates and the intersect-else-conflict rule decides', function () {
    $ctx = mergedIdentityTenant();
    $active = Lead::factory()->create(['tenant_id' => $ctx['tenant']->id, 'source_id' => $ctx['source']->id, 'name' => 'Active Holder', 'email' => 'contested@synthetic.test', 'phone' => '5554440001']);
    [$survivor] = mergedPair($ctx, 'contested', [], ['email' => 'contested@synthetic.test']);

    // email -> {active holder, survivor of the merged holder}: ambiguous on its own
    $alone = importIdentityRow($ctx, ['name' => 'Contested One', 'email' => 'contested@synthetic.test'], 1);
    expect($alone->status)->toBe(LeadImportRowStatus::Duplicate)->and($alone->duplicate_of_lead_id)->toBeNull();

    // ... but a phone that singles out the active holder makes it the unique intersection
    $withActivePhone = importIdentityRow($ctx, ['name' => 'Contested Two', 'email' => 'contested@synthetic.test', 'phone' => '5554440001'], 2);
    expect($withActivePhone->duplicate_of_lead_id)->toBe($active->id);

    // ... and one that singles out the merged holder's survivor picks that survivor instead
    $withSurvivorPhone = importIdentityRow($ctx, ['name' => 'Contested Three', 'email' => 'contested@synthetic.test', 'phone' => $survivor->phone], 3);
    expect($withSurvivorPhone->duplicate_of_lead_id)->toBe($survivor->id);

    expect(activeLeadCount($ctx['tenant']))->toBe(2);
});

test('in-file interaction: a merged-identity row is a duplicate of the survivor and reserves nothing, and later rows behave normally', function () {
    $ctx = mergedIdentityTenant();
    [$survivor, $loser] = mergedPair($ctx, 'infile');
    $import = LeadImport::factory()->create(['tenant_id' => $ctx['tenant']->id, 'source_id' => $ctx['source']->id, 'imported_by' => $ctx['admin']->id]);

    $first = importIdentityRow($ctx, ['name' => 'Loser Row', 'email' => $loser->email, 'phone' => '5557770001'], 1, $import);
    $second = importIdentityRow($ctx, ['name' => 'Fresh Person', 'email' => 'fresh@synthetic.test', 'phone' => '5557770001'], 2, $import);
    $third = importIdentityRow($ctx, ['name' => 'Fresh Again', 'email' => 'fresh@synthetic.test'], 3, $import);

    expect($first->duplicate_of_lead_id)->toBe($survivor->id);
    // the phone that only appeared on the skipped duplicate row is not reserved by it
    expect($second->status)->toBe(LeadImportRowStatus::Imported);
    // and an in-file repeat is still classified as in-file (checked first, and final)
    expect($third->status)->toBe(LeadImportRowStatus::Duplicate)->and($third->duplicate_of_lead_id)->toBeNull()->and($third->errors)->toBe(['Duplicate of an earlier row in this same CSV file.']);
});

test('DB-2: a merge that commits between the pre-lock check and the in-lock re-check is honored by the re-check', function () {
    $ctx = mergedIdentityTenant();
    $survivor = Lead::factory()->create(['tenant_id' => $ctx['tenant']->id, 'source_id' => $ctx['source']->id, 'name' => 'Survivor', 'email' => 'racesurvivor@synthetic.test', 'phone' => null]);
    $fired = false;

    // Nobody holds the identity when the pre-lock check runs. The first tenants-table read after that is the
    // tenant row lock: right after it, a lead holding the identity appears and is merged into the survivor —
    // exactly what the authoritative in-lock re-check exists to catch.
    DB::listen(function ($query) use (&$fired, $ctx, $survivor): void {
        if ($fired || ! str_contains($query->sql, 'from "tenants"')) {
            return;
        }

        $fired = true;
        $loser = Lead::factory()->create(['tenant_id' => $ctx['tenant']->id, 'source_id' => $ctx['source']->id, 'name' => 'Racing Loser', 'email' => 'racing@synthetic.test', 'phone' => null]);
        app(LeadMergeService::class)->merge($survivor, $loser, $ctx['admin'], []);
    });

    $row = importIdentityRow($ctx, ['name' => 'Racing Row', 'email' => 'racing@synthetic.test']);

    expect($fired)->toBeTrue();
    expect($row->status)->toBe(LeadImportRowStatus::Duplicate)->and($row->duplicate_of_lead_id)->toBe($survivor->id);
    expect(activeLeadCount($ctx['tenant']))->toBe(1);
});

test('retry / idempotency: re-running a row skipped for a merged identity records and creates nothing more', function () {
    $ctx = mergedIdentityTenant();
    [$survivor, $loser] = mergedPair($ctx, 'retry');
    $import = LeadImport::factory()->create(['tenant_id' => $ctx['tenant']->id, 'source_id' => $ctx['source']->id, 'imported_by' => $ctx['admin']->id]);
    $leadCount = Lead::where('tenant_id', $ctx['tenant']->id)->count();

    $first = importIdentityRow($ctx, ['name' => 'Loser Again', 'email' => $loser->email], 1, $import);
    importIdentityRow($ctx, ['name' => 'Loser Again', 'email' => $loser->email], 1, $import);

    expect(LeadImportRow::where('lead_import_id', $import->id)->count())->toBe(1);
    expect($first->fresh()->duplicate_of_lead_id)->toBe($survivor->id);
    expect($import->fresh()->only(['imported_count', 'duplicate_count', 'failed_count']))->toBe(['imported_count' => 0, 'duplicate_count' => 1, 'failed_count' => 0]);
    expect(Lead::where('tenant_id', $ctx['tenant']->id)->count())->toBe($leadCount);
});

test('no lead is modified by resolving a merged identity', function () {
    $ctx = mergedIdentityTenant();
    [$survivor, $loser] = mergedPair($ctx, 'safe');
    $before = Lead::where('tenant_id', $ctx['tenant']->id)->orderBy('id')->get()->map->getAttributes()->all();

    importIdentityRow($ctx, ['name' => 'Loser Again', 'email' => $loser->email, 'phone' => '5559990002', 'company' => 'Incoming Co']);

    expect(Lead::where('tenant_id', $ctx['tenant']->id)->orderBy('id')->get()->map->getAttributes()->all())->toBe($before);
});

test('tenant isolation: another tenant\'s merged identity never matches, and a merged lead never resolves across tenants', function () {
    $ctx = mergedIdentityTenant();
    [$survivor, $loser] = mergedPair($ctx, 'tenanta');

    $other = mergedIdentityTenant();

    // The same identity imported by the OTHER tenant is simply new there.
    $row = importIdentityRow($other, ['name' => 'Other Tenant', 'email' => $loser->email, 'phone' => $loser->phone]);
    expect($row->status)->toBe(LeadImportRowStatus::Imported);
    expect($row->duplicate_of_lead_id)->toBeNull();

    // A (corrupt) pointer that leaves the tenant must not be followed: the row fails safe, creating nothing.
    $foreignTarget = Lead::factory()->create(['tenant_id' => $ctx['tenant']->id, 'source_id' => $ctx['source']->id, 'name' => 'Foreign Target']);
    $strayLoser = Lead::factory()->create(['tenant_id' => $other['tenant']->id, 'source_id' => $other['source']->id, 'name' => 'Stray Loser', 'email' => 'stray@synthetic.test']);
    DB::table('leads')->where('id', $strayLoser->id)->update(['merged_into_lead_id' => $foreignTarget->id]);
    $before = activeLeadCount($other['tenant']);

    $stray = importIdentityRow($other, ['name' => 'Stray Row', 'email' => 'stray@synthetic.test'], 2);

    expect($stray->status)->toBe(LeadImportRowStatus::Failed)->and($stray->duplicate_of_lead_id)->toBeNull();
    expect(activeLeadCount($other['tenant']))->toBe($before);
});

test('a cyclic merge chain fails the row safely: Failed, exact data-free message, no lead, nothing mutated, only ids logged', function () {
    $ctx = mergedIdentityTenant();
    $a = Lead::factory()->create(['tenant_id' => $ctx['tenant']->id, 'source_id' => $ctx['source']->id, 'name' => 'Cycle One', 'email' => 'cycle-a@synthetic.test']);
    $b = Lead::factory()->create(['tenant_id' => $ctx['tenant']->id, 'source_id' => $ctx['source']->id, 'name' => 'Cycle Two', 'email' => 'cycle-b@synthetic.test']);
    DB::table('leads')->where('id', $a->id)->update(['merged_into_lead_id' => $b->id]);
    DB::table('leads')->where('id', $b->id)->update(['merged_into_lead_id' => $a->id]);
    Log::spy();
    $before = Lead::where('tenant_id', $ctx['tenant']->id)->orderBy('id')->get()->map->getAttributes()->all();

    $row = importIdentityRow($ctx, ['name' => 'Cycle Row', 'email' => 'cycle-a@synthetic.test']);

    expect($row->status)->toBe(LeadImportRowStatus::Failed);
    expect($row->duplicate_of_lead_id)->toBeNull()->and($row->lead_id)->toBeNull();
    expect($row->errors)->toBe(['This row matches a merged lead whose merge chain could not be resolved, so it was not imported.']);
    expect(Lead::where('tenant_id', $ctx['tenant']->id)->orderBy('id')->get()->map->getAttributes()->all())->toBe($before);
    expect(LeadImport::findOrFail($row->lead_import_id)->only(['imported_count', 'duplicate_count', 'failed_count']))->toBe(['imported_count' => 0, 'duplicate_count' => 0, 'failed_count' => 1]);

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => array_keys($context) === ['matched_lead_id', 'reason']
        && $context['reason'] === 'cycle'
        && $context['matched_lead_id'] === $a->id
        && ! str_contains($message.json_encode($context), 'synthetic.test'));
});

test('the depth cap is a boundary, not a truncation: 25 hops still resolve to the top survivor, 26 hops fail safely', function (int $hops, bool $resolves) {
    $ctx = mergedIdentityTenant();
    $chain = collect(range(0, $hops))->map(fn (int $i) => Lead::factory()->create(['tenant_id' => $ctx['tenant']->id, 'source_id' => $ctx['source']->id, 'name' => 'Deep Chain', 'email' => $i === 0 ? 'deep@synthetic.test' : null]));

    foreach ($chain as $i => $lead) {
        if ($i < $hops) {
            DB::table('leads')->where('id', $lead->id)->update(['merged_into_lead_id' => $chain[$i + 1]->id]);
        }
    }

    $leadCount = Lead::where('tenant_id', $ctx['tenant']->id)->count();
    $row = importIdentityRow($ctx, ['name' => 'Deep Row', 'email' => 'deep@synthetic.test']);

    expect(Lead::where('tenant_id', $ctx['tenant']->id)->count())->toBe($leadCount); // never a new lead, either way

    if ($resolves) {
        expect($row->status)->toBe(LeadImportRowStatus::Duplicate)->and($row->duplicate_of_lead_id)->toBe($chain->last()->id);
    } else {
        expect($row->status)->toBe(LeadImportRowStatus::Failed)->and($row->duplicate_of_lead_id)->toBeNull();
        expect($row->errors)->toBe(['This row matches a merged lead whose merge chain could not be resolved, so it was not imported.']);
    }
})->with(['25 hops resolves' => [25, true], '26 hops fails safe' => [26, false]]);

test('a broken chain that only appears after the pre-lock check fails the row safely from inside the tenant lock', function () {
    $ctx = mergedIdentityTenant();
    $before = Lead::where('tenant_id', $ctx['tenant']->id)->count();
    $fired = false;
    Log::spy();

    // Nobody holds the identity at the pre-lock check. Right after the tenant row lock is read, a merge cycle
    // holding it appears — the in-lock re-check must fail the row, never import it.
    DB::listen(function ($query) use (&$fired, $ctx): void {
        if ($fired || ! str_contains($query->sql, 'from "tenants"')) {
            return;
        }

        $fired = true;
        $a = Lead::factory()->create(['tenant_id' => $ctx['tenant']->id, 'source_id' => $ctx['source']->id, 'name' => 'Lock Cycle', 'email' => 'lockcycle@synthetic.test']);
        $b = Lead::factory()->create(['tenant_id' => $ctx['tenant']->id, 'source_id' => $ctx['source']->id, 'name' => 'Lock Partner']);
        DB::table('leads')->where('id', $a->id)->update(['merged_into_lead_id' => $b->id]);
        DB::table('leads')->where('id', $b->id)->update(['merged_into_lead_id' => $a->id]);
    });

    $row = importIdentityRow($ctx, ['name' => 'Lock Row', 'email' => 'lockcycle@synthetic.test']);

    expect($fired)->toBeTrue();
    expect($row->status)->toBe(LeadImportRowStatus::Failed)->and($row->lead_id)->toBeNull()->and($row->duplicate_of_lead_id)->toBeNull();
    expect($row->errors)->toBe(['This row matches a merged lead whose merge chain could not be resolved, so it was not imported.']);
    // the failed transaction rolled everything back: no lead exists that this import created or left behind
    expect(Lead::where('tenant_id', $ctx['tenant']->id)->count())->toBe($before);
    expect(LeadImport::findOrFail($row->lead_import_id)->only(['imported_count', 'duplicate_count', 'failed_count']))->toBe(['imported_count' => 0, 'duplicate_count' => 0, 'failed_count' => 1]);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $context['reason'] === 'cycle');
});

test('ambiguous identity: one identity mapping to several distinct survivors and nothing else is a conflict — no survivor is picked', function () {
    $ctx = mergedIdentityTenant();
    [$s1, $s2, $email] = ambiguousSurvivors($ctx, 'alone');

    $row = importIdentityRow($ctx, ['name' => 'Ambiguous Row', 'email' => $email]);

    expect($row->status)->toBe(LeadImportRowStatus::Duplicate);
    expect($row->duplicate_of_lead_id)->toBeNull();
    expect($row->errors)->toBe(['Email matches an existing lead and phone matches a different existing lead — rejected as a duplicate conflict.']);
    expect(activeLeadCount($ctx['tenant']))->toBe(2);
});

test('ambiguous identity + the other identity uniquely resolving to one candidate: that survivor is the duplicate target, whichever is older', function (string $which) {
    $ctx = mergedIdentityTenant();
    [$s1, $s2, $email] = ambiguousSurvivors($ctx, 'intersect-'.$which);
    $target = $which === 'first' ? $s1 : $s2;

    $row = importIdentityRow($ctx, ['name' => 'Intersect Row', 'email' => $email, 'phone' => $target->phone]);

    expect($row->status)->toBe(LeadImportRowStatus::Duplicate);
    expect($row->duplicate_of_lead_id)->toBe($target->id);
    expect($row->errors)->toBe(['Matches an existing lead (email or phone already on file).']);
    expect(activeLeadCount($ctx['tenant']))->toBe(2);
})->with(['the first survivor' => ['first'], 'the second survivor' => ['second']]);

test('ambiguous identity + an identity resolving OUTSIDE the candidate set, or matching nothing, is a conflict', function (string $case) {
    $ctx = mergedIdentityTenant();
    [$s1, $s2, $email] = ambiguousSurvivors($ctx, 'nointersect-'.$case);
    $third = Lead::factory()->create(['tenant_id' => $ctx['tenant']->id, 'source_id' => $ctx['source']->id, 'name' => 'Third Survivor', 'email' => 'third@synthetic.test', 'phone' => '5556660003']);

    $row = importIdentityRow($ctx, ['name' => 'Outside Row', 'email' => $email, 'phone' => $case === 'outside' ? $third->phone : '5559990009']);

    expect($row->status)->toBe(LeadImportRowStatus::Duplicate)->and($row->duplicate_of_lead_id)->toBeNull();
    expect($row->errors)->toBe(['Email matches an existing lead and phone matches a different existing lead — rejected as a duplicate conflict.']);
    expect(activeLeadCount($ctx['tenant']))->toBe(3);
})->with(['phone belongs to another survivor' => ['outside'], 'phone matches nothing' => ['nothing']]);

test('both identities ambiguous over the SAME candidate set has no unique survivor: a conflict', function () {
    $ctx = mergedIdentityTenant();
    [$s1, $s2, $email, $phone] = ambiguousSurvivors($ctx, 'both', sharePhone: true);

    $row = importIdentityRow($ctx, ['name' => 'Both Ambiguous', 'email' => $email, 'phone' => $phone]);

    expect($row->status)->toBe(LeadImportRowStatus::Duplicate)->and($row->duplicate_of_lead_id)->toBeNull();
    expect($row->errors)->toBe(['Email matches an existing lead and phone matches a different existing lead — rejected as a duplicate conflict.']);
    expect(activeLeadCount($ctx['tenant']))->toBe(2);
});

test('a single-candidate identity intersecting an ambiguous one is a duplicate only when it is one of the candidates', function () {
    $ctx = mergedIdentityTenant();
    [$s1, $s2, $email] = ambiguousSurvivors($ctx, 'single');

    // email -> {S1, S2}; phone -> S1 alone => S1
    $inside = importIdentityRow($ctx, ['name' => 'Inside Row', 'email' => $email, 'phone' => $s1->phone], 1);
    expect($inside->duplicate_of_lead_id)->toBe($s1->id);

    // phone ambiguous, email single: build the mirror image
    $phone = '5557770077';
    [$p1] = mergedPair($ctx, 'mirror-a', [], ['phone' => $phone]);
    [$p2] = mergedPair($ctx, 'mirror-b', [], ['phone' => $phone]);
    $mirror = importIdentityRow($ctx, ['name' => 'Mirror Row', 'email' => $p2->email, 'phone' => $phone], 2);
    expect($mirror->duplicate_of_lead_id)->toBe($p2->id);

    $outside = importIdentityRow($ctx, ['name' => 'Outside Mirror', 'email' => $s1->email, 'phone' => $phone], 3);
    expect($outside->duplicate_of_lead_id)->toBeNull()->and($outside->status)->toBe(LeadImportRowStatus::Duplicate);
});

test('query cost: active matches gain no query, a merged match costs exactly one primary-key read per hop', function () {
    $ctx = mergedIdentityTenant();
    $active = Lead::factory()->create(['tenant_id' => $ctx['tenant']->id, 'source_id' => $ctx['source']->id, 'name' => 'Active', 'email' => 'active@synthetic.test']);

    // a chain of three hops: c0 -> c1 -> c2 -> c3
    $chain = collect(range(0, 3))->map(fn (int $i) => Lead::factory()->create(['tenant_id' => $ctx['tenant']->id, 'source_id' => $ctx['source']->id, 'name' => 'Chain', 'email' => "chain{$i}@synthetic.test"]));

    foreach (range(0, 2) as $i) {
        app(LeadMergeService::class)->merge($chain[$i + 1], $chain[$i], $ctx['admin'], []);
    }

    [$oneHopSurvivor, $oneHopLoser] = mergedPair($ctx, 'cost');

    $queries = function (string $email, int $rowNumber) use ($ctx): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        importIdentityRow($ctx, ['name' => 'Cost Row', 'email' => $email], $rowNumber);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $activeCost = $queries($active->email, 1);
    $oneHopCost = $queries($oneHopLoser->email, 2);
    $threeHopCost = $queries($chain[0]->email, 3);

    expect($oneHopCost)->toBe($activeCost + 1);
    expect($threeHopCost)->toBe($activeCost + 3);
});

test('the import detail page shows a merged-identity duplicate as "Duplicate of <survivor>" without a match label (INC-1 C is unaffected)', function () {
    $ctx = mergedIdentityTenant();
    [$survivor, $loser] = mergedPair($ctx, 'display');
    $row = importIdentityRow($ctx, ['name' => 'Loser Again', 'email' => $loser->email, 'phone' => '5559990003']);

    Livewire::actingAs($ctx['admin'])->test(Show::class, ['leadImport' => LeadImport::findOrFail($row->lead_import_id)])
        ->assertSee('Duplicate of')
        ->assertSee($survivor->name)
        ->assertDontSee('Matched by')
        ->assertDontSee('Unmatched');
});
