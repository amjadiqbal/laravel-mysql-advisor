<?php

declare(strict_types=1);

namespace AmjadIqbal\MysqlAdvisor\Checks\Indexes;

use AmjadIqbal\MysqlAdvisor\Database\Database;

/**
 * Declared foreign keys of the current schema.
 */
final class ForeignKeys
{
    /**
     * @return array<string, list<list<string>>> table => list of column lists
     */
    public static function declared(Database $db): array
    {
        return array_map(
            static fn (array $constraints): array => array_values(array_map(static fn (array $fk): array => $fk['columns'], $constraints)),
            self::detailed($db),
        );
    }

    /**
     * @return array<string, array<string, array{columns: list<string>, references: string}>> table => constraint => details
     */
    public static function detailed(Database $db): array
    {
        $rows = $db->select(
            'SELECT TABLE_NAME AS tbl, CONSTRAINT_NAME AS name, COLUMN_NAME AS col, REFERENCED_TABLE_NAME AS ref
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL
             ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION',
            [$db->info()->schema],
        );

        $keys = [];

        foreach ($rows as $row) {
            $keys[(string) $row['tbl']][(string) $row['name']] ??= ['columns' => [], 'references' => (string) $row['ref']];
            $keys[(string) $row['tbl']][(string) $row['name']]['columns'][] = (string) $row['col'];
        }

        return $keys;
    }
}
