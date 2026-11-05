<?php

declare(strict_types=1);

namespace ThreadSafe\Migration;

use ThreadSafe\Core\Change;
use ThreadSafe\Core\Notice;
use ThreadSafe\Core\Plan;
use ThreadSafe\Replace\TableRewriteResult;

/**
 * Turns what the content replacement found in a table into the entries of a plan, the same way for every website type.
 */
final class ContentReport
{
    public static function describe(TableRewriteResult $result, Plan $plan): void
    {
        if ($result->candidatesWithoutKey !== null) {
            $plan->notice(Notice::warning(
                "{$result->candidatesWithoutKey} row(s) mention the domain but the table has no primary key; not changed.",
                $result->table,
            ));

            return;
        }

        foreach ($result->columns as $column => $outcome) {
            $stats = $outcome->stats;
            if ($outcome->rowsChanged > 0) {
                $plan->add(Change::update(
                    "db {$result->table}.$column",
                    sprintf('replace the old domain (%d URL(s) in %d row(s))', $stats->urlsReplaced, $outcome->rowsChanged),
                    $outcome->sampleBefore,
                    $outcome->sampleAfter,
                    $outcome->rowsChanged,
                ));
            }
            if ($outcome->tooLong > 0) {
                $plan->notice(Notice::warning("{$outcome->tooLong} value(s) would be too long for the column with the new domain and keep the old one (shorten them by hand if they matter, for example a redirect).", "{$result->table}.$column"));
            }
            if ($stats->bareFound > 0) {
                $plan->notice(Notice::info("{$stats->bareFound} value(s) mention the domain without a URL form and are left untouched (replace them with --replace-bare).", "{$result->table}.$column"));
            }
            if ($stats->bareReplaced > 0) {
                $plan->notice(Notice::info("{$stats->bareReplaced} bare domain name(s) replaced.", "{$result->table}.$column"));
            }
            if ($stats->emailsSkipped > 0) {
                $plan->notice(Notice::info("{$stats->emailsSkipped} e-mail address(es) at the old domain left untouched.", "{$result->table}.$column"));
            }
            if ($stats->unparseable > 0) {
                $plan->notice(Notice::warning("{$stats->unparseable} structured value(s) could not be parsed and were left untouched.", "{$result->table}.$column"));
            }
        }
    }

    private function __construct()
    {
    }
}
