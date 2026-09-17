<?php

use App\Enums\LeadSourceType;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * DB-1 concurrency follow-up — proves RecordLeadDeletionAudit::handle()'s
 * lockForUpdate() re-fetch genuinely locks the Lead row across independent
 * database SESSIONS, which is the actual guarantee the fix depends on and
 * which SQLite (this suite's default driver) cannot exercise at all — its
 * grammar silently no-ops every lock clause, the same already-established
 * limitation documented in StatusPipelineLockCoordinationTest.php's and
 * LeadMergeService's own M8 test docblocks.
 *
 * This goes one step further than every prior lock-related test in this
 * repo (M8, DEADLOCK-1, StatusPipelineLockCoordinationTest,
 * LeadSourceCostCatalogTest): those all stopped at proving the *compiled
 * SQL shape* includes a lock clause, deferring genuine cross-connection
 * blocking to a manual verification pass against the real dev database
 * (documented in each finding's PROGRESS.md closure entry, never encoded
 * as a permanent automated test — explicitly to avoid a flaky/timing-
 * dependent suite). This test proves actual cross-session exclusivity
 * safely, without ever sleeping, polling, or timing-racing: the probing
 * session uses `FOR UPDATE NOWAIT`, which returns immediately (a specific,
 * deterministic PostgreSQL error) rather than blocking, so the whole test
 * is instant and deterministic regardless of outcome.
 *
 * Skips cleanly (never a false pass) exactly like
 * EnumConstraintVerifierTest.php's own useRealPostgresConnectionOrSkip()
 * when no real, reachable PostgreSQL connection is configured — recovering
 * real credentials directly from .env (not env()/getenv(), which
 * phpunit.xml's DB_DATABASE=":memory:" override has already clobbered for
 * the whole process), the same recovery technique that file established.
 * Also carries that same file's critical warning forward: leaving
 * database.default pointed at a real pgsql connection after the test would
 * make RefreshDatabase's own teardown try to roll back against Postgres
 * instead of the suite's real sqlite connection — afterEach() below always
 * restores it, unconditionally, exactly like EnumConstraintVerifierTest.php
 * already does for its own single-connection version of this same risk.
 */
function useTwoRealPostgresSessionsOrSkip(): bool
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

    // Two separate connection names pointed at the identical DSN — each
    // gets its own PDO handle and therefore its own real PostgreSQL
    // backend session/PID, which is the whole point: two Laravel
    // connections sharing one PHP process, but never sharing a session.
    config(['database.connections.pg_session_a' => $config]);
    config(['database.connections.pg_session_b' => $config]);

    try {
        DB::connection('pg_session_a')->select('select 1');
        DB::connection('pg_session_b')->select('select 1');
    } catch (Throwable) {
        return false;
    }

    // Fixture creation below uses plain (connection-implicit) factories —
    // Lead::booted()'s creating hook calls Lead::nextIdentifier(), which
    // internally makes its own static Tenant::whereKey(...) call with no
    // explicit connection, so it must resolve to pg_session_a via
    // database.default rather than being passed through explicitly.
    config(['database.default' => 'pg_session_a']);

    return true;
}

afterEach(function (): void {
    // Restore first — this must never be skipped, even if something above
    // throws (RefreshDatabase's teardown re-reads database.default to
    // decide what to roll back; see this file's docblock).
    config(['database.default' => 'sqlite']);

    if (DB::connection('pg_session_a')->transactionLevel() > 0) {
        DB::connection('pg_session_a')->rollBack();
    }

    DB::purge('pg_session_a');
    DB::purge('pg_session_b');
});

test('a Lead row locked FOR UPDATE by one real PostgreSQL session cannot be locked by another session until the first releases it', function () {
    if (! useTwoRealPostgresSessionsOrSkip()) {
        $this->markTestSkipped('Two real, reachable PostgreSQL connections are required for this test.');
    }

    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::WebsiteForm,
    ]);
    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'source_id' => $source->id,
    ]);

    try {
        // Mirrors RecordLeadDeletionAudit::handle()'s own query exactly:
        // filtered by tenant_id + primary key, FOR UPDATE, inside an
        // explicit open transaction.
        DB::connection('pg_session_a')->beginTransaction();

        $lockedByA = Lead::on('pg_session_a')
            ->where('tenant_id', $tenant->id)
            ->whereKey($lead->id)
            ->lockForUpdate()
            ->first();

        expect($lockedByA)->not->toBeNull();

        // A second, fully independent session probes the same row without
        // waiting — NOWAIT makes this deterministic and instant: either it
        // is rejected right now (proving real cross-session exclusivity),
        // or it isn't (proving the lock is NOT actually exclusive).
        $sessionBWasBlocked = false;

        try {
            Lead::on('pg_session_b')
                ->whereKey($lead->id)
                ->lock('for update nowait')
                ->first();
        } catch (QueryException) {
            $sessionBWasBlocked = true;
        }

        expect($sessionBWasBlocked)->toBeTrue(
            'A second PostgreSQL session was able to lock a row session A already holds FOR UPDATE — the lock is not actually exclusive.'
        );

        // Releasing A's lock (commit, matching the real code path's own
        // COMMIT-on-success behavior) must immediately unblock session B.
        DB::connection('pg_session_a')->commit();

        $lockedByB = Lead::on('pg_session_b')
            ->whereKey($lead->id)
            ->lock('for update nowait')
            ->first();

        expect($lockedByB)->not->toBeNull();
        expect($lockedByB->id)->toBe($lead->id);
    } finally {
        if (DB::connection('pg_session_a')->transactionLevel() > 0) {
            DB::connection('pg_session_a')->rollBack();
        }

        // Disposable fixtures, fully cleaned up regardless of outcome —
        // same discipline as every other real-PostgreSQL test in this
        // suite (EnumConstraintVerifierTest.php, DEADLOCK-1's manual
        // verification).
        Lead::on('pg_session_b')->whereKey($lead->id)->delete();
        LeadSource::on('pg_session_b')->whereKey($source->id)->delete();
        Tenant::on('pg_session_b')->whereKey($tenant->id)->delete();
    }
});
