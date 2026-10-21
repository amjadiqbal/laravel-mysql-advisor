<?php

declare(strict_types=1);

namespace AmjadIqbal\MysqlAdvisor\Checks\Server;

use AmjadIqbal\MysqlAdvisor\Category;
use AmjadIqbal\MysqlAdvisor\Checks\Check;
use AmjadIqbal\MysqlAdvisor\Checks\Thresholds;
use AmjadIqbal\MysqlAdvisor\Database\CheckUnavailable;
use AmjadIqbal\MysqlAdvisor\Database\Database;
use AmjadIqbal\MysqlAdvisor\Finding;
use AmjadIqbal\MysqlAdvisor\Severity;
use AmjadIqbal\MysqlAdvisor\Support\Sql;

/**
 * Internal temporary tables (GROUP BY, ORDER BY, DISTINCT, UNION) that spilled from memory to disk.
 */
final class DiskTemporaryTables implements Check
{
    public function id(): string
    {
        return 'disk-temp-tables';
    }

    public function title(): string
    {
        return 'Temporary tables written to disk';
    }

    public function category(): Category
    {
        return Category::Server;
    }

    public function run(Database $db, Thresholds $thresholds): array
    {
        $created = (int) $db->status('Created_tmp_tables');
        $onDisk = (int) $db->status('Created_tmp_disk_tables');

        if ($created < $thresholds->int('temp_tables_min_created', 1000)) {
            throw new CheckUnavailable('Only '.number_format($created).' temporary tables since the server started: too few to judge yet.');
        }

        $ratio = $onDisk / $created;

        if ($ratio < $thresholds->float('temp_tables_disk_warning', 0.25)) {
            return [];
        }

        $tmp = (int) $db->variable('tmp_table_size');
        $heap = (int) $db->variable('max_heap_table_size');
        $limit = min($tmp, $heap);
        $suggested = max(64 * 1024 ** 2, $limit * 2);
        $info = $db->info();

        return [new Finding(
            check: $this->id(),
            category: $this->category(),
            severity: $ratio >= $thresholds->float('temp_tables_disk_critical', 0.5) ? Severity::Critical : Severity::Warning,
            subject: 'tmp_table_size',
            title: Sql::percent($ratio, 0).' of internal temporary tables went to disk',
            detail: number_format($onDisk).' of '.number_format($created).' temporary tables were too big for memory. '
                .'The in-memory limit is the smaller of tmp_table_size and max_heap_table_size, now '.Sql::bytes($limit).'. '
                .'Before raising it, look at the top statements: selecting TEXT/BLOB columns, or GROUP BY / ORDER BY on unindexed columns, is the usual cause, and fixing the query beats more memory. '
                .'Every connection can use this much, so raise it in steps.',
            fix: $info->setGlobal('tmp_table_size', $suggested)."\n".$info->setGlobal('max_heap_table_size', $suggested),
            evidence: ['on_disk' => $onDisk, 'created' => $created, 'tmp_table_size' => Sql::bytes($tmp), 'max_heap_table_size' => Sql::bytes($heap)],
        )];
    }
}
