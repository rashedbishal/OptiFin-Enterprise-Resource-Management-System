<?php

use App\Services\EnumConstraintVerifier;
use Illuminate\Support\Facades\DB;

/**
 * L4 — the automated regression guard for the exact defect class that let
 * App\Enums\LeadSourceType::CsvImport's CHECK-constraint drift slip past
 * the whole (SQLite) test suite once, caught only by a manual smoke test
 * against real Postgres. Deliberately split into two groups:
 *
 * - Pure, DB-free tests of parseEnumCheckDefinition()/compareValues()
 *   (both public static, mirroring App\Services\LeadDuplicateDetectionService::
 *   normalizeEmail()/normalizePhone()'s existing "public static for direct
 *   unit testing" convention) — these never touch a database at all.
 * - Real, live-connection tests of verify() and the private DB-catalog
 *   helpers (via ReflectionMethod, matching the existing precedent in
 *   tests/Unit/ProcessEmailLeadSourceBodyExtractionTest.php). None of
 *   these pretend SQLite can verify real Postgres constraint drift: the
 *   suite's own default connection is SQLite (phpunit.xml), so every test
 *   that genuinely needs Postgres either uses a connection attempt that is
 *   allowed to fail safely (the "connection unreachable" case IS the
 *   test), or explicitly recovers real credentials from .env — the
 *   process environment's own DB_DATABASE is globally overridden to
 *   ':memory:' by phpunit.xml for the whole test run (confirmed: this
 *   poisons every registered connection's env('DB_DATABASE') lookup, not
 *   just SQLite's), so .env is parsed directly (Dotenv::parse(), which
 *   does not mutate the process environment) to recover the real
 *   database name/host/credentials this repository's own dev environment
 *   already uses. Every such test skips itself cleanly if that recovery
 *   or the resulting connection fails — never a false pass, never a
 *   hard failure just because a given environment lacks a real Postgres
 *   server to reach.
 */

/**
 * Recovers this repository's real PostgreSQL connection details directly
 * from .env (not from env()/getenv(), which phpunit.xml's DB_DATABASE
 * override has already clobbered for the whole process) and points a
 * temporary 'pgsql' connection at them for the remainder of THIS test only
 * — Laravel's TestCase creates a fresh application (and therefore a fresh
 * config array) for every single test method, so this can never leak into
 * another test. Returns false if .env is absent, doesn't describe a
 * pgsql connection, or that connection can't actually be reached — the
 * caller must markTestSkipped() in that case, never treat it as a pass.
 */
/**
 * Any test above that points `database.default` at 'pgsql' (either
 * deliberately, or via useRealPostgresConnectionOrSkip() below) MUST NOT
 * leave it that way: RefreshDatabase's own teardown re-reads
 * config('database.default') to decide which connection to roll back and
 * disconnect (see Illuminate\Foundation\Testing\RefreshDatabase::
 * beginDatabaseTransaction()'s beforeApplicationDestroyed() closure) — if
 * that now resolves to 'pgsql' instead of the 'sqlite' this whole suite
 * actually runs on, teardown tries to connect to Postgres itself, which
 * can throw OUTSIDE any of this file's own try/catch blocks and corrupt
 * every subsequent test in the same process ("There is already an active
 * transaction"). Confirmed by direct reproduction while developing this
 * file. This restores the real default after every single test.
 */
afterEach(function (): void {
    config(['database.default' => 'sqlite']);
});

function useRealPostgresConnectionOrSkip(): bool
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

    config([
        'database.default' => 'pgsql',
        'database.connections.pgsql.host' => $parsed['DB_HOST'] ?? '127.0.0.1',
        'database.connections.pgsql.port' => $parsed['DB_PORT'] ?? '5432',
        'database.connections.pgsql.database' => $parsed['DB_DATABASE'] ?? 'laravel',
        'database.connections.pgsql.username' => $parsed['DB_USERNAME'] ?? 'root',
        'database.connections.pgsql.password' => $parsed['DB_PASSWORD'] ?? '',
    ]);

    try {
        DB::connection('pgsql')->select('select 1');
    } catch (Throwable) {
        return false;
    }

    return true;
}

// --- Pure parseEnumCheckDefinition() tests — no database involved -----

test('parseEnumCheckDefinition extracts every value from a real multi-value canonical definition', function () {
    $definition = "CHECK (((type)::text = ANY ((ARRAY['facebook_messenger'::character varying, 'facebook_lead_ads'::character varying, 'whatsapp'::character varying, 'instagram'::character varying, 'email'::character varying, 'website_form'::character varying, 'linkedin_lead_gen'::character varying, 'manual'::character varying, 'csv_import'::character varying])::text[])))";

    expect(EnumConstraintVerifier::parseEnumCheckDefinition($definition, 'type'))->toBe([
        'facebook_messenger', 'facebook_lead_ads', 'whatsapp', 'instagram',
        'email', 'website_form', 'linkedin_lead_gen', 'manual', 'csv_import',
    ]);
});

test('parseEnumCheckDefinition extracts a two-value canonical definition', function () {
    $definition = "CHECK (((direction)::text = ANY ((ARRAY['inbound'::character varying, 'outbound'::character varying])::text[])))";

    expect(EnumConstraintVerifier::parseEnumCheckDefinition($definition, 'direction'))->toBe(['inbound', 'outbound']);
});

test('parseEnumCheckDefinition returns null when the definition governs a different column', function () {
    $definition = "CHECK (((type)::text = ANY ((ARRAY['manual'::character varying])::text[])))";

    expect(EnumConstraintVerifier::parseEnumCheckDefinition($definition, 'priority'))->toBeNull();
});

test('parseEnumCheckDefinition returns null for a CHECK constraint that is not enum-shaped at all', function () {
    expect(EnumConstraintVerifier::parseEnumCheckDefinition('CHECK ((amount >= (0)::numeric))', 'amount'))->toBeNull();
});

test('parseEnumCheckDefinition unescapes a doubled single quote inside a value', function () {
    $definition = "CHECK (((label)::text = ANY ((ARRAY['can''t'::character varying])::text[])))";

    expect(EnumConstraintVerifier::parseEnumCheckDefinition($definition, 'label'))->toBe(["can't"]);
});

test('two columns sharing one enum class are parsed independently — one drifting does not affect the other', function () {
    $healthy = "CHECK (((channel)::text = ANY ((ARRAY['whatsapp'::character varying, 'messenger'::character varying, 'instagram'::character varying])::text[])))";
    $drifted = "CHECK (((channel)::text = ANY ((ARRAY['whatsapp'::character varying, 'messenger'::character varying])::text[])))";

    $conversationsChannel = EnumConstraintVerifier::parseEnumCheckDefinition($healthy, 'channel');
    $messagesChannel = EnumConstraintVerifier::parseEnumCheckDefinition($drifted, 'channel');

    expect($conversationsChannel)->toBe(['whatsapp', 'messenger', 'instagram']);
    expect($messagesChannel)->toBe(['whatsapp', 'messenger']);
    expect($conversationsChannel)->not->toBe($messagesChannel);
});

// --- Pure compareValues() tests — no database involved -----

test('compareValues reports no difference for identical sets', function () {
    [$missing, $extra] = EnumConstraintVerifier::compareValues(['low', 'medium', 'high'], ['low', 'medium', 'high']);

    expect($missing)->toBe([]);
    expect($extra)->toBe([]);
});

test('compareValues reports the exact missing value when the DB constraint lacks one the enum has', function () {
    [$missing, $extra] = EnumConstraintVerifier::compareValues(
        ['facebook_messenger', 'whatsapp', 'csv_import'],
        ['facebook_messenger', 'whatsapp'],
    );

    expect($missing)->toBe(['csv_import']);
    expect($extra)->toBe([]);
});

test('compareValues reports the exact extra value when the DB constraint has one the enum no longer has', function () {
    [$missing, $extra] = EnumConstraintVerifier::compareValues(
        ['granted', 'withdrawn'],
        ['granted', 'withdrawn', 'legacy_pending'],
    );

    expect($missing)->toBe([]);
    expect($extra)->toBe(['legacy_pending']);
});

test('compareValues reports both directions of mismatch simultaneously without conflating them', function () {
    [$missing, $extra] = EnumConstraintVerifier::compareValues(
        ['low', 'medium', 'high', 'urgent'],
        ['low', 'medium', 'critical'],
    );

    expect($missing)->toBe(['high', 'urgent']);
    expect($extra)->toBe(['critical']);
});

test('compareValues is not sensitive to declaration order', function () {
    [$missing, $extra] = EnumConstraintVerifier::compareValues(
        ['urgent', 'low', 'high', 'medium'],
        ['medium', 'urgent', 'low', 'high'],
    );

    expect($missing)->toBe([]);
    expect($extra)->toBe([]);
});

// --- Real-connection tests: verify()'s own applicability/error handling -----

test('verify() reports an explicit, non-passing NOT_APPLICABLE result under the test suite\'s own non-Postgres connection', function () {
    expect(DB::connection()->getDriverName())->toBe('sqlite');

    $result = app(EnumConstraintVerifier::class)->verify();

    expect($result['applicable'])->toBeFalse();
    expect($result['driver'])->toBe('sqlite');
    expect($result['skip_reason'])->not->toBeNull();
    expect($result['connection_error'])->toBeNull();
    expect($result['checks'])->toBe([]);
});

test('verify() reports a distinct connection_error, not schema drift, when PostgreSQL is unreachable', function () {
    // Deliberately NOT a mock: phpunit.xml's DB_DATABASE=":memory:" override
    // (confirmed to clobber env('DB_DATABASE') for every registered
    // connection, not just sqlite's) makes this a genuine failed
    // connection attempt against a real Postgres server for a database
    // literally named ":memory:", which does not exist.
    config(['database.default' => 'pgsql']);

    $result = app(EnumConstraintVerifier::class)->verify();

    expect($result['applicable'])->toBeTrue();
    expect($result['connection_error'])->not->toBeNull();
    expect($result['checks'])->toBe([]);
});

// --- Real-connection tests: the private DB-catalog helpers, via reflection
// (matching the existing precedent in
// tests/Unit/ProcessEmailLeadSourceBodyExtractionTest.php), and the real
// current repository state -----

test('checkConstraintsReferencing finds nothing for a real column that genuinely has no CHECK constraint', function () {
    if (! useRealPostgresConnectionOrSkip()) {
        $this->markTestSkipped('A real reachable PostgreSQL connection is required for this test.');
    }

    $method = new ReflectionMethod(EnumConstraintVerifier::class, 'checkConstraintsReferencing');

    expect($method->invoke(new EnumConstraintVerifier, 'leads', 'name'))->toBe([]);
});

test('columnExists correctly reports a column that does not exist', function () {
    if (! useRealPostgresConnectionOrSkip()) {
        $this->markTestSkipped('A real reachable PostgreSQL connection is required for this test.');
    }

    $method = new ReflectionMethod(EnumConstraintVerifier::class, 'columnExists');

    expect($method->invoke(new EnumConstraintVerifier, 'leads', 'this_column_does_not_exist_xyz'))->toBeFalse();
});

test('checkConstraintsReferencing reports an ambiguous result when two CHECK constraints reference the same column', function () {
    if (! useRealPostgresConnectionOrSkip()) {
        $this->markTestSkipped('A real reachable PostgreSQL connection is required for this test.');
    }

    // Postgres DDL is transactional: a second CHECK constraint is added
    // and the transaction is ROLLED BACK, never committed — the same
    // "uncommitted transaction, guaranteed clean" pattern this session
    // has used throughout for read/write reproductions against the real
    // dev database. Nothing here is left behind even if the process were
    // killed mid-test.
    DB::connection('pgsql')->beginTransaction();

    try {
        DB::connection('pgsql')->statement("alter table leads add constraint leads_priority_check_l4_test check (priority in ('low', 'medium', 'high', 'urgent'))");

        $method = new ReflectionMethod(EnumConstraintVerifier::class, 'checkConstraintsReferencing');
        $matches = $method->invoke(new EnumConstraintVerifier, 'leads', 'priority');

        expect($matches)->toHaveCount(2);
    } finally {
        DB::connection('pgsql')->rollBack();
    }
});

test('the current repository state has zero drift when verified against real PostgreSQL', function () {
    if (! useRealPostgresConnectionOrSkip()) {
        $this->markTestSkipped('A real reachable PostgreSQL connection is required for this test.');
    }

    $result = app(EnumConstraintVerifier::class)->verify();

    expect($result['applicable'])->toBeTrue();
    expect($result['connection_error'])->toBeNull();
    expect($result['checks'])->not->toBeEmpty();

    $mismatches = array_values(array_filter(
        $result['checks'],
        fn (array $check): bool => $check['status'] !== 'match',
    ));

    expect($mismatches)->toBe([]);
});
