<?php

namespace App\Health;

use App\Enums\IntegrationProblem;
use App\Services\IntegrationHealth;

/**
 * @phpstan-import-type IntegrationRow from IntegrationHealth
 */
trait ListsIntegrationProblems
{
    /**
     * "Client — source" names of the rows having any of these problems.
     *
     * @param  list<IntegrationRow>  $rows
     * @param  list<IntegrationProblem>  $problems
     * @return list<string>
     */
    private function sourcesWith(array $rows, array $problems): array
    {
        $names = [];

        foreach ($rows as $row) {
            foreach ($row['problems'] as $problem) {
                if (in_array($problem['problem'], $problems, true)) {
                    $names[] = $row['source']->tenant->name.' — '.$row['source']->name;

                    break;
                }
            }
        }

        return $names;
    }

    /**
     * @param  list<string>  $names
     */
    private function listed(array $names, int $max = 5): string
    {
        $shown = implode(', ', array_slice($names, 0, $max));

        return count($names) > $max ? $shown.' '.__('and :count more', ['count' => count($names) - $max]) : $shown;
    }
}
