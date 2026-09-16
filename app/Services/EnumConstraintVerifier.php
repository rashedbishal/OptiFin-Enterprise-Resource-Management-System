<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadAttachment;
use App\Models\LeadConsent;
use App\Models\LeadConsentEvent;
use App\Models\LeadDuplicateLink;
use App\Models\LeadSource;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\WebsiteFormField;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * L4 — read-only diagnostic proving every Lead-Management enum-backed
 * column's real PostgreSQL CHECK constraint still matches its PHP enum's
 * current case set.
 *
 * Exists because Laravel's enum() migration helper re-derives its CHECK
 * constraint from the LIVE enum class every time a migration actually
 * runs — a freshly-migrated database (every automated test, via
 * RefreshDatabase) can therefore never reproduce "an already-provisioned
 * database whose constraint predates a later enum change", which is
 * exactly how App\Enums\LeadSourceType::CsvImport slipped past the whole
 * test suite once (see database/migrations/2026_09_12_080002_add_csv_import_to_lead_sources_type_check.php's
 * own docblock) and was only caught by a manual smoke test against the
 * real dev database. This class is the automated, repeatable replacement
 * for that manual step — it never writes anything, it only reports.
 *
 * Deliberately reuses config('database.default') — no dedicated
 * verification connection, no new config key (per the approved L4
 * Architecture Brief). Deliberately scoped to a fixed, explicit list of
 * Lead-Management models (MODELS below), not a reflection scan of every
 * class under app/Models, which would silently pull in unrelated
 * PlatformUser/TenantUser/Role/etc. casts with no review.
 */
class EnumConstraintVerifier
{
    /**
     * The fixed, explicit set of Lead-Management models this verifier
     * scans for enum-backed casts() entries. Extend this list only when a
     * genuinely new Lead-Management enum-backed column is introduced
     * elsewhere — it deliberately does not grow itself automatically.
     *
     * @var array<int, class-string<Model>>
     */
    private const array MODELS = [
        Tenant::class,
        LeadSource::class,
        Lead::class,
        Conversation::class,
        Message::class,
        LeadDuplicateLink::class,
        LeadAttachment::class,
        LeadConsent::class,
        LeadConsentEvent::class,
        WebsiteFormField::class,
    ];

    /**
     * @return array{
     *     applicable: bool,
     *     driver: string,
     *     skip_reason: string|null,
     *     connection_error: string|null,
     *     checks: array<int, array{
     *         model: class-string<Model>,
     *         table: string,
     *         column: string,
     *         enum: class-string<BackedEnum>,
     *         status: string,
     *         expected: array<int, string>,
     *         missing_in_db: array<int, string>,
     *         extra_in_db: array<int, string>,
     *         detail: string|null,
     *     }>,
     * }
     */
    public function verify(): array
    {
        $driver = DB::connection()->getDriverName();

        if ($driver !== 'pgsql') {
            return [
                'applicable' => false,
                'driver' => $driver,
                'skip_reason' => "Enum/CHECK constraint drift can only be verified against PostgreSQL — the active connection uses the '{$driver}' driver.",
                'connection_error' => null,
                'checks' => [],
            ];
        }

        try {
            DB::connection()->select('select 1');
        } catch (Throwable $e) {
            return [
                'applicable' => true,
                'driver' => $driver,
                'skip_reason' => null,
                'connection_error' => $e->getMessage(),
                'checks' => [],
            ];
        }

        $checks = [];

        foreach (self::MODELS as $modelClass) {
            foreach ($this->enumCastsFor($modelClass) as $column => $enumClass) {
                $checks[] = $this->checkColumn($modelClass, $column, $enumClass);
            }
        }

        return [
            'applicable' => true,
            'driver' => $driver,
            'skip_reason' => null,
            'connection_error' => null,
            'checks' => $checks,
        ];
    }

    /**
     * Every column this model casts to a backed enum — reuses
     * Model::getCasts() (the same resolved cast list Eloquent itself uses
     * to hydrate attributes), not a hand-maintained mirror of it, so this
     * can never drift from what the model actually does.
     *
     * @param  class-string<Model>  $modelClass
     * @return array<string, class-string<BackedEnum>>
     */
    private function enumCastsFor(string $modelClass): array
    {
        $model = new $modelClass;
        $casts = [];

        foreach ($model->getCasts() as $column => $cast) {
            if (is_string($cast) && enum_exists($cast) && is_a($cast, BackedEnum::class, true)) {
                $casts[$column] = $cast;
            }
        }

        return $casts;
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  class-string<BackedEnum>  $enumClass
     * @return array{model: class-string<Model>, table: string, column: string, enum: class-string<BackedEnum>, status: string, expected: array<int, string>, missing_in_db: array<int, string>, extra_in_db: array<int, string>, detail: string|null}
     */
    private function checkColumn(string $modelClass, string $column, string $enumClass): array
    {
        $table = (new $modelClass)->getTable();
        $expected = array_map(fn (BackedEnum $case): string => (string) $case->value, $enumClass::cases());

        $base = [
            'model' => $modelClass,
            'table' => $table,
            'column' => $column,
            'enum' => $enumClass,
            'expected' => $expected,
        ];

        if (! $this->columnExists($table, $column)) {
            return [
                ...$base,
                'status' => 'column_not_found',
                'missing_in_db' => [],
                'extra_in_db' => [],
                'detail' => "Column \"{$table}.{$column}\" does not exist — this casts() mapping does not correspond to a real column.",
            ];
        }

        $constraints = $this->checkConstraintsReferencing($table, $column);

        if ($constraints === []) {
            return [
                ...$base,
                'status' => 'missing_constraint',
                'missing_in_db' => [],
                'extra_in_db' => [],
                'detail' => "No CHECK constraint on \"{$table}\" references \"{$column}\".",
            ];
        }

        if (count($constraints) > 1) {
            $names = implode(', ', array_column($constraints, 'conname'));

            return [
                ...$base,
                'status' => 'ambiguous_constraint',
                'missing_in_db' => [],
                'extra_in_db' => [],
                'detail' => "Multiple CHECK constraints reference \"{$table}.{$column}\", so the authoritative one cannot be determined without guessing: {$names}.",
            ];
        }

        $constraint = $constraints[0];
        $parsed = self::parseEnumCheckDefinition($constraint['definition'], $column);

        if ($parsed === null) {
            return [
                ...$base,
                'status' => 'malformed_constraint',
                'missing_in_db' => [],
                'extra_in_db' => [],
                'detail' => "Constraint \"{$constraint['conname']}\" references \"{$column}\" but is not in the expected `col = ANY (ARRAY[...])` shape: {$constraint['definition']}",
            ];
        }

        [$missing, $extra] = self::compareValues($expected, $parsed);

        if ($missing === [] && $extra === []) {
            return [
                ...$base,
                'status' => 'match',
                'missing_in_db' => [],
                'extra_in_db' => [],
                'detail' => null,
            ];
        }

        return [
            ...$base,
            'status' => 'value_mismatch',
            'missing_in_db' => $missing,
            'extra_in_db' => $extra,
            'detail' => null,
        ];
    }

    /**
     * Compares two unordered sets of string values — $expected (the PHP
     * enum's current cases) against $actual (what the parsed DB CHECK
     * constraint actually allows) — and reports both directions of
     * difference separately, exactly as the approved Architecture Brief
     * requires: a value the enum can produce but the DB would reject
     * (missing_in_db, the historical CsvImport failure's exact shape) is a
     * genuinely different problem from a value the DB still accepts that
     * the enum can no longer produce (extra_in_db, e.g. an incomplete
     * case-removal). Public static and pure, for the same direct-testing
     * reason as parseEnumCheckDefinition() above.
     *
     * @param  array<int, string>  $expected
     * @param  array<int, string>  $actual
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    public static function compareValues(array $expected, array $actual): array
    {
        return [
            array_values(array_diff($expected, $actual)),
            array_values(array_diff($actual, $expected)),
        ];
    }

    private function columnExists(string $table, string $column): bool
    {
        return DB::selectOne(
            'select 1 from information_schema.columns where table_name = ? and column_name = ? limit 1',
            [$table, $column]
        ) !== null;
    }

    /**
     * Every CHECK constraint on $table whose definition mentions $column as
     * a distinct identifier (word-boundary match, so e.g. "status" never
     * matches a constraint that only mentions "old_status") — deliberately
     * NOT filtered by Laravel's default `{table}_{column}_check` naming
     * convention, since a hand-written migration could name one
     * differently; deliberately not assumed to be exactly one match, so a
     * genuinely ambiguous schema (two CHECK constraints both touching the
     * same column under different names) is reported, not guessed past.
     *
     * @return array<int, array{conname: string, definition: string}>
     */
    private function checkConstraintsReferencing(string $table, string $column): array
    {
        $rows = DB::select(
            "select conname, pg_get_constraintdef(oid) as definition
             from pg_constraint
             where conrelid = ?::regclass and contype = 'c'",
            [$table]
        );

        $matching = [];
        $pattern = '/\b'.preg_quote($column, '/').'\b/';

        foreach ($rows as $row) {
            if (preg_match($pattern, (string) $row->definition) === 1) {
                $matching[] = ['conname' => $row->conname, 'definition' => $row->definition];
            }
        }

        return $matching;
    }

    /**
     * Parses PostgreSQL's own CANONICAL pg_get_constraintdef() rendering
     * of a Laravel enum()-emulated CHECK constraint — confirmed, against
     * the real dev database, to be identical in shape for every one of
     * this repository's currently-live enum-derived constraints
     * regardless of whether the originating migration used Laravel's
     * enum() blueprint or a raw DB::statement() `CHECK (col IN (...))`
     * (both canonicalize to the same `col = ANY (ARRAY[...])` form) — so
     * this deliberately targets exactly that canonical shape rather than
     * attempting a general SQL CHECK-expression parser. Returns null
     * (never an empty array) when the definition doesn't match that
     * shape — the caller reports 'malformed_constraint' rather than
     * silently treating an unparseable definition as "zero allowed
     * values", which would produce a false, alarming "every value is
     * missing" report instead of an honest "cannot verify this one".
     *
     * Public static and pure (no DB access, no side effects) — mirrors
     * App\Services\LeadDuplicateDetectionService::normalizeEmail()/
     * normalizePhone()'s existing "public static for direct, isolated
     * unit testing" convention in this codebase, so this can be tested
     * against real, hand-copied Postgres constraint definitions without
     * needing a live database connection at all.
     *
     * @return array<int, string>|null
     */
    public static function parseEnumCheckDefinition(string $definition, string $column): ?array
    {
        $quotedColumn = preg_quote($column, '/');
        $pattern = '/^CHECK \(\(\(('.$quotedColumn.')\)::text = ANY \(\(ARRAY\[(.*)\]\)::text\[\]\)\)\)$/s';

        if (preg_match($pattern, $definition, $matches) !== 1) {
            return null;
        }

        $values = [];

        foreach (explode(', ', $matches[2]) as $item) {
            if (preg_match("/^'(.*)'::character varying$/s", $item, $valueMatch) !== 1) {
                return null;
            }

            $values[] = str_replace("''", "'", $valueMatch[1]);
        }

        return $values;
    }
}
