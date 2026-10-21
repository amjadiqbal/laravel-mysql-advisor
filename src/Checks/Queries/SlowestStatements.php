<?php

declare(strict_types=1);

namespace AmjadIqbal\MysqlAdvisor\Checks\Queries;

use AmjadIqbal\MysqlAdvisor\Category;
use AmjadIqbal\MysqlAdvisor\Checks\Check;
use AmjadIqbal\MysqlAdvisor\Checks\Thresholds;
use AmjadIqbal\MysqlAdvisor\Database\Database;
use AmjadIqbal\MysqlAdvisor\Finding;
use AmjadIqbal\MysqlAdvisor\Severity;
use AmjadIqbal\MysqlAdvisor\Support\Sql;

/**
 * Where the database's time actually goes: the statements with the largest total time.
 *
 * Ranked by total, not by the slowest single run, because a 2 ms query run a
 * million times costs more than a 4 s report run once a day.
 */
final class SlowestStatements implements Check
{
    public function id(): string
    {
        return 'top-statements';
    }

    public function title(): string
    {
        return 'Statements using the most database time';
    }

    public function category(): Category
    {
        return Category::Queries;
    }

    public function run(Database $db, Thresholds $thresholds): array
    {
        $statements = Statements::forSchema($db);
        $grandTotal = array_sum(array_column($statements, 'total'));
        $slowAvg = $thresholds->int('slow_statement_ms', 500) * 1e9; // ms to picoseconds
        $minShare = $thresholds->float('top_statement_min_share', 0.05);
        $findings = [];

        foreach (array_slice($statements, 0, $thresholds->int('top_statements', 5)) as $s) {
            $share = $grandTotal > 0 ? $s['total'] / $grandTotal : 0.0;

            if ($share < $minShare && $s['avg'] < $slowAvg) {
                continue;
            }

            $slow = $s['avg'] >= $slowAvg;

            $findings[] = new Finding(
                check: $this->id(),
                category: $this->category(),
                severity: $slow ? Severity::Warning : Severity::Notice,
                subject: $s['digest'],
                title: Sql::percent($share, 0).' of query time: '.Statements::short($s['text'], 90),
                detail: "Ran {$s['calls']} times, ".Sql::duration($s['avg']).' on average, '.Sql::duration($s['total']).' in total since the server started. '
                    .($slow
                        ? 'Each run is slow. Run EXPLAIN on a real query of this shape and look for "ALL" in the type column or "Using filesort" / "Using temporary".'
                        : 'Each run is fast, so the cost is volume: caching the result, or loading it once per request instead of per row, saves the most here.'),
                fix: 'EXPLAIN '.$s['text'].';',
                evidence: [
                    'statement' => Statements::short($s['text'], 400),
                    'calls' => $s['calls'],
                    'average' => Sql::duration($s['avg']),
                    'total_time' => Sql::duration($s['total']),
                    'share_of_time' => Sql::percent($share),
                ],
            );
        }

        // Mixed traffic: some statements are in the digests, but many ran as native prepares and are not.
        $invisible = Statements::invisibleExecutes($db);
        $visible = array_sum(array_column($statements, 'calls'));

        if ($invisible > $visible * $thresholds->float('invisible_prepares_ratio', 0.25)) {
            $findings[] = new Finding(
                check: $this->id(),
                category: $this->category(),
                severity: Severity::Notice,
                subject: 'prepared-statements',
                title: number_format($invisible).' statements ran as native prepared statements and are missing from these query checks',
                detail: 'MySQL and MariaDB keep no per-query statistics for native (binary protocol) prepared statements, which is how Laravel sends queries by default. '
                    .'Only '.number_format($visible).' statements for this schema are visible, so the query findings above describe part of the traffic. '
                    .'Emulated prepares make every query visible and save two round trips per query.',
                laravel: Statements::EMULATE_PREPARES_HINT,
                evidence: ['native_prepared_executes' => $invisible, 'visible_statement_calls' => $visible],
            );
        }

        return $findings;
    }
}
