<?php

declare(strict_types=1);

namespace AmjadIqbal\MysqlAdvisor\Checks\Queries;

use AmjadIqbal\MysqlAdvisor\Category;
use AmjadIqbal\MysqlAdvisor\Checks\Check;
use AmjadIqbal\MysqlAdvisor\Checks\Thresholds;
use AmjadIqbal\MysqlAdvisor\Database\Database;
use AmjadIqbal\MysqlAdvisor\Finding;
use AmjadIqbal\MysqlAdvisor\Severity;
use AmjadIqbal\MysqlAdvisor\Support\DigestShape;
use AmjadIqbal\MysqlAdvisor\Support\Sql;

/**
 * Statements that ran without any index and read many rows to do it.
 */
final class FullTableScans implements Check
{
    public function id(): string
    {
        return 'full-table-scan';
    }

    public function title(): string
    {
        return 'Queries doing full table scans';
    }

    public function category(): Category
    {
        return Category::Queries;
    }

    public function run(Database $db, Thresholds $thresholds): array
    {
        $minCalls = $thresholds->int('full_scan_min_calls', 10);
        $minRows = $thresholds->int('full_scan_min_rows_per_call', 1000);
        $findings = [];

        foreach (Statements::forSchema($db) as $s) {
            if ($s['no_index'] === 0 || $s['calls'] < $minCalls) {
                continue;
            }

            $perCall = intdiv($s['rows_examined'], max(1, $s['calls']));

            if ($perCall < $minRows) {
                continue;
            }

            $shape = DigestShape::parse($s['text']);

            if ($shape->table !== null && $thresholds->ignores($shape->table)) {
                continue;
            }

            $suggest = $shape->table !== null && ! $shape->joins && $shape->equalityColumns !== [];
            $indexName = $suggest ? $shape->table.'_'.implode('_', $shape->equalityColumns).'_index' : null;

            $findings[] = new Finding(
                check: $this->id(),
                category: $this->category(),
                severity: $perCall >= $thresholds->int('full_scan_critical_rows_per_call', 100000) ? Severity::Critical : Severity::Warning,
                subject: $s['digest'],
                title: 'Full scan reading '.number_format($perCall).' rows per call: '.Statements::short($s['text'], 90),
                detail: "This statement ran {$s['calls']} times without using any index"
                    .($shape->table !== null ? " on {$shape->table}" : '')
                    .' and read '.number_format($s['rows_examined']).' rows in total to return '.number_format($s['rows_sent']).'. '
                    .($suggest
                        ? 'An index on the columns it filters by would let MySQL jump straight to the rows. Confirm with EXPLAIN on a real query of this shape first.'
                        : 'Index the columns used in its WHERE, JOIN and ORDER BY clauses, then confirm with EXPLAIN on a real query of this shape.'),
                fix: $suggest
                    ? 'ALTER TABLE '.Sql::id((string) $shape->table).' ADD INDEX '.Sql::id((string) $indexName).' ('.Sql::columns($shape->equalityColumns).');'
                    : 'EXPLAIN '.$s['text'].';',
                laravel: $suggest ? Sql::migration((string) $shape->table, '$table->index('.Sql::phpList($shape->equalityColumns).');') : null,
                evidence: [
                    'statement' => Statements::short($s['text'], 400),
                    'calls' => $s['calls'],
                    'rows_examined_per_call' => $perCall,
                    'total_time' => Sql::duration($s['total']),
                ],
            );
        }

        return $findings;
    }
}
