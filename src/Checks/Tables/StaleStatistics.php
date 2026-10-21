<?php

declare(strict_types=1);

namespace AmjadIqbal\MysqlAdvisor\Checks\Tables;

use AmjadIqbal\MysqlAdvisor\Category;
use AmjadIqbal\MysqlAdvisor\Checks\Check;
use AmjadIqbal\MysqlAdvisor\Checks\Thresholds;
use AmjadIqbal\MysqlAdvisor\Database\CheckUnavailable;
use AmjadIqbal\MysqlAdvisor\Database\Database;
use AmjadIqbal\MysqlAdvisor\Finding;
use AmjadIqbal\MysqlAdvisor\Severity;
use AmjadIqbal\MysqlAdvisor\Support\Sql;
use AmjadIqbal\MysqlAdvisor\Support\Tables;
use DateTimeImmutable;
use Throwable;

/**
 * Tables whose optimizer statistics no longer describe them.
 *
 * InnoDB recalculates persistent statistics after about 10% of a table changes,
 * unless that is switched off for the server or the table. Statistics from a
 * table a tenth the size make the optimizer pick the wrong index.
 */
final class StaleStatistics implements Check
{
    public function id(): string
    {
        return 'stale-statistics';
    }

    public function title(): string
    {
        return 'Stale optimizer statistics';
    }

    public function category(): Category
    {
        return Category::Tables;
    }

    public function run(Database $db, Thresholds $thresholds): array
    {
        $findings = [];
        $autoRecalc = in_array(strtoupper((string) $db->variable('innodb_stats_auto_recalc')), ['ON', '1'], true);
        $persistent = in_array(strtoupper((string) $db->variable('innodb_stats_persistent')), ['ON', '1'], true);

        if ($persistent && ! $autoRecalc) {
            $findings[] = new Finding(
                check: $this->id(),
                category: $this->category(),
                severity: Severity::Warning,
                subject: 'innodb_stats_auto_recalc',
                title: 'innodb_stats_auto_recalc is OFF: statistics only change when you run ANALYZE TABLE',
                detail: 'With persistent statistics and no automatic recalculation, the optimizer keeps planning with the row counts from the last ANALYZE, however much the tables have grown since.',
                fix: $db->info()->setGlobal('innodb_stats_auto_recalc', 'ON'),
            );
        }

        if (! $persistent) {
            return $findings;
        }

        if (! $db->info()->performanceSchema) {
            if ($findings !== []) {
                return $findings;
            }

            throw new CheckUnavailable(
                'performance_schema is off, so there is no count of rows changed since the statistics were taken.',
                'Add performance_schema=ON under [mysqld] in my.cnf and restart the server.',
            );
        }

        $stats = $this->persistentStats($db);
        $writes = $this->writesSinceStart($db);
        $info = $db->info();
        $maxDays = $thresholds->int('stale_statistics_days', 30);
        $changeRatio = $thresholds->float('stale_statistics_change_ratio', 0.1);
        $now = new DateTimeImmutable;
        $startedAt = $now->modify('-'.$info->uptimeSeconds.' seconds');

        foreach (Tables::all($db) as $t) {
            if ($t['engine'] !== 'InnoDB' || $thresholds->ignores($t['name'])) {
                continue;
            }

            $stat = $stats[$t['name']] ?? null;
            $tableOff = str_contains($t['options'], 'stats_auto_recalc=0');

            if ($stat === null) {
                continue;
            }

            $age = (int) floor(($now->getTimestamp() - $stat['updated']->getTimestamp()) / 86400);
            $changed = $writes[$t['name']] ?? null;
            $base = max(1, $stat['rows']);

            // Only writes after the last ANALYZE count, and the counters only go back to server start.
            $measurable = $changed !== null && $stat['updated'] <= $startedAt;
            $drifted = $measurable && $changed / $base >= $changeRatio;

            if (! ($tableOff && $drifted) && ! ($age >= $maxDays && $drifted)) {
                continue;
            }

            $findings[] = new Finding(
                check: $this->id(),
                category: $this->category(),
                severity: Severity::Warning,
                subject: $t['name'],
                title: "Statistics for {$t['name']} are {$age} days old and ".number_format((int) $changed).' rows have changed since',
                detail: 'The optimizer believes the table has '.number_format($stat['rows']).' rows'
                    .($tableOff ? ' and automatic recalculation is switched off for this table (STATS_AUTO_RECALC=0).' : '.')
                    .' Refreshing statistics is cheap: ANALYZE TABLE samples a few pages and does not rebuild anything.',
                fix: 'ANALYZE TABLE '.Sql::id($t['name']).';'.($tableOff ? "\nALTER TABLE ".Sql::id($t['name']).' STATS_AUTO_RECALC = DEFAULT;' : ''),
                evidence: ['stats_rows' => $stat['rows'], 'rows_changed_since_start' => (int) $changed, 'stats_updated' => $stat['updated']->format('Y-m-d H:i')],
            );
        }

        return $findings;
    }

    /**
     * @return array<string, array{rows: int, updated: DateTimeImmutable}>
     */
    private function persistentStats(Database $db): array
    {
        $rows = $db->select(
            'SELECT table_name AS tbl, n_rows AS n_rows, last_update AS last_update FROM mysql.innodb_table_stats WHERE database_name = ?',
            [$db->info()->schema],
        );

        $stats = [];

        foreach ($rows as $row) {
            try {
                $stats[(string) $row['tbl']] = ['rows' => (int) $row['n_rows'], 'updated' => new DateTimeImmutable((string) $row['last_update'])];
            } catch (Throwable) {
                continue;
            }
        }

        return $stats;
    }

    /**
     * Rows written per table since the server started.
     *
     * @return array<string, int>
     */
    private function writesSinceStart(Database $db): array
    {
        $writes = [];

        foreach ($db->select(
            'SELECT OBJECT_NAME AS tbl, COUNT_INSERT + COUNT_UPDATE + COUNT_DELETE AS writes
             FROM performance_schema.table_io_waits_summary_by_table WHERE OBJECT_SCHEMA = ?',
            [$db->info()->schema],
        ) as $row) {
            $writes[(string) $row['tbl']] = (int) $row['writes'];
        }

        return $writes;
    }
}
