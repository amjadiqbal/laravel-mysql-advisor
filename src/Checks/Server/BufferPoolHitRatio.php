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
 * How often InnoDB found the page it needed already in memory.
 */
final class BufferPoolHitRatio implements Check
{
    public function id(): string
    {
        return 'buffer-pool-hit-ratio';
    }

    public function title(): string
    {
        return 'InnoDB buffer pool hit ratio';
    }

    public function category(): Category
    {
        return Category::Server;
    }

    public function run(Database $db, Thresholds $thresholds): array
    {
        $requests = (int) $db->status('Innodb_buffer_pool_read_requests');
        $fromDisk = (int) $db->status('Innodb_buffer_pool_reads');
        $minRequests = $thresholds->int('buffer_pool_min_requests', 100000);

        if ($requests < $minRequests) {
            throw new CheckUnavailable('Only '.number_format($requests).' page reads since the server started: too few to judge the hit ratio yet.');
        }

        $ratio = 1 - $fromDisk / $requests;
        $warning = $thresholds->float('buffer_pool_hit_warning', 0.99);
        $critical = $thresholds->float('buffer_pool_hit_critical', 0.95);

        if ($ratio >= $warning) {
            return [];
        }

        $poolSize = (int) $db->variable('innodb_buffer_pool_size');
        $dataSize = $this->innodbDataSize($db);
        $chunk = 128 * 1024 ** 2;
        $suggested = (int) (ceil(max($poolSize * 2, $dataSize * 1.2) / $chunk) * $chunk);

        return [new Finding(
            check: $this->id(),
            category: $this->category(),
            severity: $ratio < $critical ? Severity::Critical : Severity::Warning,
            subject: 'innodb_buffer_pool_size',
            title: 'Only '.Sql::percent($ratio, 2).' of InnoDB page reads came from memory',
            detail: number_format($fromDisk).' of '.number_format($requests).' page reads had to go to disk. '
                .'The buffer pool is '.Sql::bytes($poolSize).' while this schema holds about '.Sql::bytes($dataSize).' of InnoDB data and indexes. '
                .'Give it more memory, up to roughly 70% of RAM on a server that only runs the database (less when PHP runs on the same machine).',
            fix: $db->info()->setGlobal('innodb_buffer_pool_size', $suggested).' -- '.Sql::bytes($suggested),
            evidence: ['hit_ratio' => Sql::percent($ratio, 2), 'buffer_pool' => Sql::bytes($poolSize), 'innodb_data' => Sql::bytes($dataSize), 'disk_reads' => $fromDisk],
        )];
    }

    private function innodbDataSize(Database $db): int
    {
        $row = $db->select(
            "SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) AS bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND ENGINE = 'InnoDB'",
            [$db->info()->schema],
        )[0] ?? [];

        return (int) ($row['bytes'] ?? 0);
    }
}
