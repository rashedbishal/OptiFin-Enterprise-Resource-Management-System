<?php

use App\Enums\LeadSourceType;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Tenant;
use App\Services\LeadDeletionEligibilityService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PROD-1 — proves, against two genuinely independent real PostgreSQL SESSIONS
 * (which SQLite, this suite's default driver, cannot exercise: its grammar
 * silently no-ops every lock clause), how LeadDeletionEligibilityService's
 * target-row lock interacts with a concurrent merge:
 *
 *   1. the evaluator locks exactly the lead being deleted — a merge-shaped
 *      ordered lock on that row is refused while it is held — and NOTHING
 *      else: the loser row stays freely lockable, so DB-1's single-lock audit
 *      invariant and LeadMergeService's ordered two-row lock are untouched;
 *   2. a merge that committed first is fully visible to the evaluator, which
 *      then refuses both the survivor and the loser;
 *   3. a deletion that committed first leaves the merge unable to commit an
 *      invalid state: the write that would point the loser at the now-deleted
 *      survivor is rejected by the merged_into_lead_id foreign key.
 *
 * Same technique, and same reasons, as LeadDeletionAuditConcurrencyTest.php:
 * the probing session uses FOR UPDATE NOWAIT, which fails immediately with a
 * specific PostgreSQL error instead of blocking, so the whole file is instant
 * and deterministic — no sleeping, polling or timing races. Skips cleanly
 * (never a false pass) when no real, reachable PostgreSQL connection exists,
 * and afterEach() always restores database.default so RefreshDatabase's own
 * teardown keeps rolling back against the suite's real sqlite connection.
 *
 * The full end-to-end "two real processes racing" guarantee is established by
 * a disposable manual verification (not a committed test), the same limitation
 * the other concurrency closures (M8, DEADLOCK-1, DB-1) already document.
 */
function useTwoRealPostgresSessionsForMergedDeletionOrSkip(): bool
{
    $envPath = base_path('.env');
    $envContents = is_file($envPath) ? file_get_contents($envPath) : false;

    if ($envContents === false) {
        return false;
    }

    $parsed = Dotenv\Dotenv::parse($envContents);

    if (($parsed['DB_CONNECTION'] ?? null) !== 'pgsql') {
        return false;
    }

    $config = [
        'driver' => 'pgsql',
        'host' => $parsed['DB_HOST'] ?? '127.0.0.1',
        'port' => $parsed['DB_PORT'] ?? '5432',
        'database' => $parsed['DB_DATABASE'] ?? 'laravel',
        'username' => $parsed['DB_USERNAME'] ?? 'root',
        'password' => $parsed['DB_PASSWORD'] ?? '',
        'charset' => 'utf8',
        'prefix' => '',
        'schema' => 'public',
    ];

    config(['database.connections.pg_session_a' => $config]);
    config(['database.connections.pg_session_b' => $config]);

    try {
        DB::connection('pg_session_a')->select('select 1');
        DB::connection('pg_session_b')->select('select 1');
    } catch (Throwable) {
        return false;
    }

    // Session A is the "deleting request": database.default points at it, so
    // the evaluator (which uses plain, connection-implicit queries exactly as
    // in production) runs inside session A's transaction.
    config(['database.default' => 'pg_session_a']);

    return true;
}

/**
 * @return array{tenant: Tenant, source: LeadSource, survivor: Lead, loser: Lead}
 */
function mergedDeletionRealPostgresFixture(): array
{
    // An explicit, unique tenant_code: the factory's own static counter
    // starts at TEN-000001 in every process and would collide with any tenant
    // already sitting in the real database this test runs against.
    $tenant = Tenant::factory()->create(['tenant_code' => 'PRD1-'.Str::upper(Str::random(10))]);
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Manual]);
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    return compact('tenant', 'source', 'survivor', 'loser');
}

/**
 * True when a NOWAIT lock probe on the given ids is rejected because another
 * session holds them; false when the lock was granted.
 *
 * @param  array<int, string>  $ids
 */
function mergedDeletionProbeIsBlocked(array $ids): bool
{
    $placeholders = implode(', ', array_fill(0, count($ids), '?'));

    try {
        DB::connection('pg_session_b')->select("select id from leads where id in ({$placeholders}) order by id for update nowait", $ids);
    } catch (QueryException) {
        return true;
    }

    return false;
}

function mergedDeletionCleanUp(array $fixture): void
{
    foreach (['pg_session_a', 'pg_session_b'] as $connection) {
        if (DB::connection($connection)->transactionLevel() > 0) {
            DB::connection($connection)->rollBack();
        }
    }

    Lead::on('pg_session_b')->whereIn('id', [$fixture['loser']->id, $fixture['survivor']->id])->delete();
    LeadSource::on('pg_session_b')->whereKey($fixture['source']->id)->delete();
    Tenant::on('pg_session_b')->whereKey($fixture['tenant']->id)->delete();
}

afterEach(function (): void {
    // Restore first — this must never be skipped (see this file's docblock).
    config(['database.default' => 'sqlite']);

    foreach (['pg_session_a', 'pg_session_b'] as $connection) {
        if (DB::connection($connection)->transactionLevel() > 0) {
            DB::connection($connection)->rollBack();
        }
    }

    DB::purge('pg_session_a');
    DB::purge('pg_session_b');
});

test('the evaluator locks exactly the lead being deleted, and no other lead row', function () {
    if (! useTwoRealPostgresSessionsForMergedDeletionOrSkip()) {
        $this->markTestSkipped('Two real, reachable PostgreSQL connections are required for this test.');
    }

    $fixture = mergedDeletionRealPostgresFixture();

    try {
        // The survivor really has a merged loser, so the evaluator's loser
        // lookup runs — and must take no lock on that loser row.
        DB::connection('pg_session_a')->table('leads')->where('id', $fixture['loser']->id)->update(['merged_into_lead_id' => $fixture['survivor']->id]);

        DB::connection('pg_session_a')->beginTransaction();

        $verdict = app(LeadDeletionEligibilityService::class)->evaluate($fixture['survivor']);

        expect($verdict->eligible)->toBeFalse();
        expect($verdict->reason)->toBe(LeadDeletionEligibilityService::survivorReason());

        // Session B: the merge-shaped ordered lock over survivor + loser is
        // refused (the survivor row is held) ...
        expect(mergedDeletionProbeIsBlocked([$fixture['survivor']->id, $fixture['loser']->id]))->toBeTrue(
            'A merge-shaped ordered lock succeeded while the evaluator held the target lead row — the evaluator did not lock it.'
        );
        expect(mergedDeletionProbeIsBlocked([$fixture['survivor']->id]))->toBeTrue();

        // ... but the loser row alone is freely lockable: the evaluator took
        // no second lead lock, so it can never join a circular wait.
        expect(mergedDeletionProbeIsBlocked([$fixture['loser']->id]))->toBeFalse(
            'The loser row was locked too — the evaluator acquired more than the single target-row lock.'
        );

        // Releasing the lock (the transaction ends) immediately unblocks B.
        DB::connection('pg_session_a')->rollBack();

        expect(mergedDeletionProbeIsBlocked([$fixture['survivor']->id, $fixture['loser']->id]))->toBeFalse();
    } finally {
        mergedDeletionCleanUp($fixture);
    }
});

test('a merge that committed first is fully visible to the evaluator, which refuses both the survivor and the loser', function () {
    if (! useTwoRealPostgresSessionsForMergedDeletionOrSkip()) {
        $this->markTestSkipped('Two real, reachable PostgreSQL connections are required for this test.');
    }

    $fixture = mergedDeletionRealPostgresFixture();

    try {
        // Session B performs exactly what LeadMergeService::merge() does for
        // this state: ordered two-row lock, then the merged_into write, commit.
        DB::connection('pg_session_b')->beginTransaction();
        DB::connection('pg_session_b')->select('select id from leads where id in (?, ?) order by id for update', [$fixture['survivor']->id, $fixture['loser']->id]);
        DB::connection('pg_session_b')->table('leads')->where('id', $fixture['loser']->id)->update(['merged_into_lead_id' => $fixture['survivor']->id]);
        DB::connection('pg_session_b')->commit();

        // Session A — a deletion request that began earlier — evaluates now.
        DB::connection('pg_session_a')->beginTransaction();

        $service = app(LeadDeletionEligibilityService::class);

        expect($service->evaluate($fixture['survivor'])->reason)->toBe(LeadDeletionEligibilityService::survivorReason());
        expect($service->evaluate($fixture['loser'])->reason)->toBe(LeadDeletionEligibilityService::loserReason());

        DB::connection('pg_session_a')->rollBack();
    } finally {
        mergedDeletionCleanUp($fixture);
    }
});

test('a deletion that committed first leaves a concurrent merge unable to commit an invalid state', function () {
    if (! useTwoRealPostgresSessionsForMergedDeletionOrSkip()) {
        $this->markTestSkipped('Two real, reachable PostgreSQL connections are required for this test.');
    }

    $fixture = mergedDeletionRealPostgresFixture();

    try {
        // Session A: the deletion request — evaluate (eligible, nothing merged
        // yet), delete, commit.
        DB::connection('pg_session_a')->beginTransaction();

        expect(app(LeadDeletionEligibilityService::class)->evaluate($fixture['survivor'])->eligible)->toBeTrue();

        DB::connection('pg_session_a')->table('leads')->where('id', $fixture['survivor']->id)->delete();
        DB::connection('pg_session_a')->commit();

        // Session B: the merge that raced it. Its ordered lock now only finds
        // the loser, and the write pointing the loser at the deleted survivor
        // is rejected by the merged_into_lead_id foreign key (SQLSTATE 23503).
        DB::connection('pg_session_b')->beginTransaction();

        $locked = DB::connection('pg_session_b')->select('select id from leads where id in (?, ?) order by id for update', [$fixture['survivor']->id, $fixture['loser']->id]);
        expect($locked)->toHaveCount(1);

        $sqlState = null;

        try {
            DB::connection('pg_session_b')->table('leads')->where('id', $fixture['loser']->id)->update(['merged_into_lead_id' => $fixture['survivor']->id]);
        } catch (QueryException $e) {
            $sqlState = $e->getCode();
        }

        expect($sqlState)->toBe('23503');

        DB::connection('pg_session_b')->rollBack();

        // No invalid state was committed: the loser is untouched and unmerged.
        expect(Lead::on('pg_session_b')->whereKey($fixture['loser']->id)->value('merged_into_lead_id'))->toBeNull();
    } finally {
        mergedDeletionCleanUp($fixture);
    }
});
