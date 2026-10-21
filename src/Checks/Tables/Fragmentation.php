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

/**
 * Tables carrying a lot of free space inside their files, usually after large deletes.
 */
final class Fragmentation implements Check
{
    public function id(): string
    {
        return 'fragmentation';
    }

    public function title(): string
    {
        return 'Fragmented tables';
    }

    public function category(): Category
    {
        return Category::Tables;
    }

    public function run(Database $db, Thresholds $thresholds): array
    {
        // With a shared system tablespace, DATA_FREE is the free space of the whole
        // tablespace, repeated on every table, and says nothing about any one of them.
        if (! in_array(strtoupper((string) $db->variable('innodb_file_per_table')), ['ON', '1'], true)) {
            throw new CheckUnavailable('innodb_file_per_table is off, so free space is reported for the shared tablespace, not per table.');
        }

        $minBytes = $thresholds->int('fragmentation_min_bytes', 100 * 1024 ** 2);
        $minRatio = $thresholds->float('fragmentation_min_ratio', 0.2);
        $findings = [];

        foreach (Tables::all($db) as $t) {
            $size = $t['data'] + $t['index'];

            if ($t['free'] < $minBytes || $size === 0 || $thresholds->ignores($t['name'])) {
                continue;
            }

            $ratio = $t['free'] / ($size + $t['free']);

            if ($ratio < $minRatio) {
                continue;
            }

            $findings[] = new Finding(
                check: $this->id(),
                category: $this->category(),
                severity: Severity::Warning,
                subject: $t['name'],
                title: "{$t['name']} has ".Sql::bytes($t['free']).' of free space inside it ('.Sql::percent($ratio, 0).' of its file)',
                detail: 'Deleted rows leave space InnoDB reuses for new rows but never gives back to the disk. Rebuilding the table compacts it and returns the space. '
                    .'For InnoDB the rebuild is online, but it copies the whole table, so on a large table run it in a quiet period.',
                fix: 'OPTIMIZE TABLE '.Sql::id($t['name']).';',
                evidence: ['free' => Sql::bytes($t['free']), 'data' => Sql::bytes($t['data']), 'indexes' => Sql::bytes($t['index']), 'engine' => $t['engine']],
            );
        }

        return $findings;
    }
}
