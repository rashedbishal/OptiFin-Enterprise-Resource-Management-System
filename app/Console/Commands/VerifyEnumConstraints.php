<?php

namespace App\Console\Commands;

use App\Services\EnumConstraintVerifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * L4 — on-demand replacement for the undocumented "someone remembers to
 * manually smoke-test against real Postgres" habit that let
 * App\Enums\LeadSourceType::CsvImport's CHECK-constraint drift slip
 * through once. Deliberately NOT wired into CI (current CI is SQLite-only,
 * per the approved L4 Architecture Brief) — this is meant to be run by
 * hand, or from a future deployment script, against a real PostgreSQL
 * environment. Read-only: never alters schema, data, or migrations.
 *
 * Exit codes (deliberate three-state design, documented here rather than
 * left implicit, since a plain 0/1 pair cannot distinguish "verified and
 * clean" from "not verified at all" — a caller scripting against this
 * command needs to tell those apart):
 *   0 = PostgreSQL reached, every discovered mapping matched.
 *   1 = drift found, a constraint issue was found, or the connection
 *       failed — something is genuinely wrong or could not be checked.
 *   2 = not applicable — the active connection isn't PostgreSQL, so no
 *       verification was performed at all. Never reported as exit 0,
 *       so a caller can never mistake "skipped" for "passed".
 */
#[Signature('schema:verify-enum-constraints')]
#[Description('Verify every Lead-Management enum-backed column\'s live PostgreSQL CHECK constraint matches its PHP enum\'s current cases')]
class VerifyEnumConstraints extends Command
{
    public function handle(EnumConstraintVerifier $verifier): int
    {
        $result = $verifier->verify();

        if (! $result['applicable']) {
            $this->warn('SKIPPED / NOT APPLICABLE — '.$result['skip_reason']);

            return 2;
        }

        if ($result['connection_error'] !== null) {
            $this->error('CONNECTION FAILED — could not verify anything: '.$result['connection_error']);

            return self::FAILURE;
        }

        if ($result['checks'] === []) {
            $this->warn('No enum-backed Lead-Management columns were discovered to check.');

            return self::SUCCESS;
        }

        $rows = [];
        $hasProblem = false;

        foreach ($result['checks'] as $check) {
            $rows[] = [
                $check['table'].'.'.$check['column'],
                class_basename($check['enum']),
                $check['status'],
            ];

            if ($check['status'] !== 'match') {
                $hasProblem = true;
            }
        }

        $this->table(['Column', 'Enum', 'Status'], $rows);

        $problems = array_filter($result['checks'], fn (array $check): bool => $check['status'] !== 'match');

        if ($problems !== []) {
            $this->newLine();
            $this->error(count($problems).' mismatch(es) found:');

            foreach ($problems as $check) {
                $this->line("  - {$check['table']}.{$check['column']} ({$check['status']}): {$check['detail']}");

                if ($check['missing_in_db'] !== []) {
                    $this->line('      missing in DB constraint: '.implode(', ', $check['missing_in_db']));
                }

                if ($check['extra_in_db'] !== []) {
                    $this->line('      extra in DB constraint (not in the PHP enum): '.implode(', ', $check['extra_in_db']));
                }
            }
        }

        if ($hasProblem) {
            return self::FAILURE;
        }

        $this->info(count($result['checks']).' enum-backed constraint(s) verified against PostgreSQL — all matched.');

        return self::SUCCESS;
    }
}
