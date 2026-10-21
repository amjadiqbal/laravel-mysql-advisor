<?php

declare(strict_types=1);

namespace AmjadIqbal\MysqlAdvisor\Checks\Indexes;

use AmjadIqbal\MysqlAdvisor\Category;
use AmjadIqbal\MysqlAdvisor\Checks\Check;
use AmjadIqbal\MysqlAdvisor\Checks\Requires;
use AmjadIqbal\MysqlAdvisor\Checks\Thresholds;
use AmjadIqbal\MysqlAdvisor\Database\Database;
use AmjadIqbal\MysqlAdvisor\Finding;
use AmjadIqbal\MysqlAdvisor\Severity;
use AmjadIqbal\MysqlAdvisor\Support\IndexCatalog;
use AmjadIqbal\MysqlAdvisor\Support\Sql;

/**
 * Secondary indexes no query has read since the server started.
 *
 * Reads the same performance_schema table that sys.schema_unused_indexes wraps,
 * directly, so it also works on MariaDB, which does not ship the sys schema.
 */
final class UnusedIndexes implements Check
{
    public function id(): string
    {
        return 'unused-index';
    }

    public function title(): string
    {
        return 'Unused indexes';
    }

    public function category(): Category
    {
        return Category::Indexes;
    }

    public function run(Database $db, Thresholds $thresholds): array
    {
        Requires::performanceSchema($db);

        $info = $db->info();
        $rows = $db->select(
            'SELECT OBJECT_NAME AS tbl, INDEX_NAME AS idx, COUNT_STAR AS uses
             FROM performance_schema.table_io_waits_summary_by_index_usage
             WHERE OBJECT_SCHEMA = ?',
            [$info->schema],
        );

        // A table nothing has read since startup tells us nothing about its indexes.
        $tableReads = [];
        $candidates = [];

        foreach ($rows as $row) {
            $table = (string) $row['tbl'];
            $tableReads[$table] = ($tableReads[$table] ?? 0) + (int) $row['uses'];

            if ($row['idx'] !== null && $row['idx'] !== 'PRIMARY' && (int) $row['uses'] === 0) {
                $candidates[] = [$table, (string) $row['idx']];
            }
        }

        $catalog = IndexCatalog::load($db);
        $foreignKeyColumns = ForeignKeys::declared($db);
        $young = $info->uptimeSeconds < $thresholds->int('unused_index_min_uptime_days', 7) * 86400;
        $findings = [];

        foreach ($candidates as [$table, $name]) {
            $index = $catalog->on($table)[$name] ?? null;

            if ($index === null || $index['unique'] || ($tableReads[$table] ?? 0) === 0 || $thresholds->ignores($table)) {
                continue;
            }

            // MySQL refuses to drop the only index behind a foreign key, and it serves cascades on the parent.
            if ($this->backsForeignKey($table, $index['columns'], $foreignKeyColumns, $catalog, $name)) {
                continue;
            }

            $hide = $info->mariaDb
                ? 'ALTER TABLE '.Sql::id($table).' ALTER INDEX '.Sql::id($name).' IGNORED;'
                : 'ALTER TABLE '.Sql::id($table).' ALTER INDEX '.Sql::id($name).' INVISIBLE;';

            $findings[] = new Finding(
                check: $this->id(),
                category: $this->category(),
                severity: $young ? Severity::Notice : Severity::Warning,
                subject: "{$table}.{$name}",
                title: "Index {$name} on {$table} has not been used in ".$info->uptimeForHumans().' of uptime',
                detail: 'Every insert, update and delete on '.$table.' still maintains it, and it takes buffer-pool memory. '
                    .'The counters restart with the server and are per server: check replicas and monthly jobs before dropping. '
                    .'The first statement hides the index from the optimizer without dropping it, so you can watch for slow queries and undo it instantly.'
                    .($young ? ' The server has been up for less than '.$thresholds->int('unused_index_min_uptime_days', 7).' days, so treat this as a hint only.' : ''),
                fix: $hide."\n-- once nothing got slower:\nALTER TABLE ".Sql::id($table).' DROP INDEX '.Sql::id($name).';',
                laravel: Sql::migration($table, "\$table->dropIndex('".addslashes($name)."');"),
                evidence: ['columns' => implode(', ', $index['parts']), 'reads_since_start' => 0, 'uptime' => $info->uptimeForHumans()],
            );
        }

        return $findings;
    }

    /**
     * @param  list<string>  $columns
     * @param  array<string, list<list<string>>>  $foreignKeyColumns
     */
    private function backsForeignKey(string $table, array $columns, array $foreignKeyColumns, IndexCatalog $catalog, string $name): bool
    {
        foreach ($foreignKeyColumns[$table] ?? [] as $fk) {
            if (array_slice($columns, 0, count($fk)) !== $fk) {
                continue;
            }

            foreach ($catalog->on($table) as $otherName => $other) {
                if ($otherName !== $name && array_slice($other['columns'], 0, count($fk)) === $fk) {
                    continue 2; // another index covers this foreign key
                }
            }

            return true;
        }

        return false;
    }
}
