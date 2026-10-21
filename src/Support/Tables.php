<?php

declare(strict_types=1);

namespace AmjadIqbal\MysqlAdvisor\Support;

use AmjadIqbal\MysqlAdvisor\Database\Database;

/**
 * Base tables of the current schema with their size estimates, from information_schema.TABLES.
 */
final class Tables
{
    /**
     * @return list<array{name: string, engine: string, rows: int, data: int, index: int, free: int, options: string}>
     */
    public static function all(Database $db): array
    {
        $rows = $db->select(
            "SELECT TABLE_NAME AS name, ENGINE AS engine, TABLE_ROWS AS row_estimate, DATA_LENGTH AS data_bytes,
                    INDEX_LENGTH AS index_bytes, DATA_FREE AS free_bytes, CREATE_OPTIONS AS options
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'
             ORDER BY DATA_LENGTH + INDEX_LENGTH DESC",
            [$db->info()->schema],
        );

        return array_map(static fn (array $r): array => [
            'name' => (string) $r['name'],
            'engine' => (string) ($r['engine'] ?? ''),
            'rows' => (int) ($r['row_estimate'] ?? 0),
            'data' => (int) ($r['data_bytes'] ?? 0),
            'index' => (int) ($r['index_bytes'] ?? 0),
            'free' => (int) ($r['free_bytes'] ?? 0),
            'options' => strtolower((string) ($r['options'] ?? '')),
        ], $rows);
    }
}
