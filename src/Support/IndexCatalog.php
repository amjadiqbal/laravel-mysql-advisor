<?php

declare(strict_types=1);

namespace AmjadIqbal\MysqlAdvisor\Support;

use AmjadIqbal\MysqlAdvisor\Database\Database;

/**
 * The indexes of one schema, read from information_schema (needs no extra privilege).
 */
final class IndexCatalog
{
    /**
     * @param  array<string, array<string, array{unique: bool, type: string, columns: list<string>, parts: list<string>}>>  $indexes
     */
    public function __construct(public readonly array $indexes) {}

    public static function load(Database $db): self
    {
        $rows = $db->select(
            'SELECT TABLE_NAME AS tbl, INDEX_NAME AS idx, NON_UNIQUE AS non_unique, INDEX_TYPE AS type,
                    COLUMN_NAME AS col, SUB_PART AS sub_part
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ?
             ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX',
            [$db->info()->schema],
        );

        $indexes = [];

        foreach ($rows as $row) {
            $table = (string) $row['tbl'];
            $name = (string) $row['idx'];
            $column = (string) ($row['col'] ?? ''); // functional key parts have no column name

            $indexes[$table][$name] ??= ['unique' => (int) $row['non_unique'] === 0, 'type' => strtoupper((string) $row['type']), 'columns' => [], 'parts' => []];
            $indexes[$table][$name]['columns'][] = $column;
            $indexes[$table][$name]['parts'][] = $column.($row['sub_part'] !== null ? '('.$row['sub_part'].')' : '');
        }

        return new self($indexes);
    }

    /**
     * @return array<string, array{unique: bool, type: string, columns: list<string>, parts: list<string>}>
     */
    public function on(string $table): array
    {
        return $this->indexes[$table] ?? [];
    }

    /**
     * Whether some index on the table starts with exactly these columns, in this order.
     *
     * @param  list<string>  $columns
     */
    public function hasLeading(string $table, array $columns): bool
    {
        foreach ($this->on($table) as $index) {
            if ($index['type'] === 'BTREE' && array_slice($index['columns'], 0, count($columns)) === $columns) {
                return true;
            }
        }

        return false;
    }
}
