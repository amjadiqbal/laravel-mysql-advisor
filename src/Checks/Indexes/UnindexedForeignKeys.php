<?php

declare(strict_types=1);

namespace AmjadIqbal\MysqlAdvisor\Checks\Indexes;

use AmjadIqbal\MysqlAdvisor\Category;
use AmjadIqbal\MysqlAdvisor\Checks\Check;
use AmjadIqbal\MysqlAdvisor\Checks\Thresholds;
use AmjadIqbal\MysqlAdvisor\Database\Database;
use AmjadIqbal\MysqlAdvisor\Finding;
use AmjadIqbal\MysqlAdvisor\Severity;
use AmjadIqbal\MysqlAdvisor\Support\IndexCatalog;
use AmjadIqbal\MysqlAdvisor\Support\Sql;

/**
 * Foreign keys, declared or by Laravel naming convention, that no index starts with.
 *
 * InnoDB indexes a declared foreign key itself, so the common Laravel case is
 * the other one: `$table->foreignId('user_id')` or `unsignedBigInteger('user_id')`
 * without `->constrained()`, which creates the column and no index at all. Every
 * `$user->posts` then scans the whole posts table.
 */
final class UnindexedForeignKeys implements Check
{
    public function id(): string
    {
        return 'unindexed-foreign-key';
    }

    public function title(): string
    {
        return 'Foreign keys without an index';
    }

    public function category(): Category
    {
        return Category::Indexes;
    }

    public function run(Database $db, Thresholds $thresholds): array
    {
        $schema = $db->info()->schema;
        $catalog = IndexCatalog::load($db);
        $declared = ForeignKeys::detailed($db);
        $findings = [];

        foreach ($declared as $table => $constraints) {
            foreach ($constraints as $name => $fk) {
                if (! $thresholds->ignores($table) && ! $catalog->hasLeading($table, $fk['columns'])) {
                    $findings[] = $this->finding($table, $fk['columns'], $fk['references'], "foreign key {$name}", Severity::Warning);
                }
            }
        }

        $columns = $db->select(
            "SELECT c.TABLE_NAME AS tbl, c.COLUMN_NAME AS col, c.DATA_TYPE AS type
             FROM information_schema.COLUMNS c
             JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
             WHERE c.TABLE_SCHEMA = ? AND t.TABLE_TYPE = 'BASE TABLE' AND c.COLUMN_NAME LIKE '%\\_id'
               AND c.DATA_TYPE IN ('bigint', 'int', 'mediumint', 'smallint', 'char', 'varchar', 'binary', 'uuid')
             ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION",
            [$schema],
        );

        $tables = array_flip(array_map(
            static fn (array $r): string => (string) $r['tbl'],
            $db->select("SELECT TABLE_NAME AS tbl FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'", [$schema]),
        ));

        foreach ($columns as $row) {
            $table = (string) $row['tbl'];
            $column = (string) $row['col'];

            if ($thresholds->ignores($table) || $this->isDeclared($declared[$table] ?? [], $column) || $this->indexedAnywhereFirst($catalog, $table, $column)) {
                continue;
            }

            $relation = substr($column, 0, -3);

            // A polymorphic pair: (x_type, x_id) is the index Laravel's morphs() makes.
            if ($catalog->hasLeading($table, [$relation.'_type', $column])) {
                continue;
            }

            $parent = self::parentTable($relation, $tables);

            $findings[] = $this->finding(
                $table,
                [$column],
                $parent,
                "column {$column}",
                $parent !== null ? Severity::Warning : Severity::Notice,
            );
        }

        return $findings;
    }

    private function indexedAnywhereFirst(IndexCatalog $catalog, string $table, string $column): bool
    {
        return $catalog->hasLeading($table, [$column]);
    }

    /**
     * @param  array<string, array{columns: list<string>, references: string}>  $constraints
     */
    private function isDeclared(array $constraints, string $column): bool
    {
        foreach ($constraints as $fk) {
            if (in_array($column, $fk['columns'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The table a `<relation>_id` column most likely points at, by Laravel's naming convention.
     *
     * @param  array<string, int>  $tables
     */
    public static function parentTable(string $relation, array $tables): ?string
    {
        $candidates = [$relation.'s', $relation.'es', $relation];

        if (str_ends_with($relation, 'y')) {
            $candidates[] = substr($relation, 0, -1).'ies';
        }

        foreach ($candidates as $candidate) {
            if (isset($tables[$candidate])) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $columns
     */
    private function finding(string $table, array $columns, ?string $parent, string $what, Severity $severity): Finding
    {
        $indexName = $table.'_'.implode('_', $columns).'_index';

        return new Finding(
            check: $this->id(),
            category: $this->category(),
            severity: $severity,
            subject: $table.'.'.implode(',', $columns),
            title: "No index on {$table}.".implode(', ', $columns).($parent !== null ? " (points at {$parent})" : ''),
            detail: 'Looking up the '.$table.' rows that belong to one '.($parent ?? 'parent').' row, which is what every Eloquent relation, eager load and cascading delete does, '
                ."has to read the whole {$table} table because no index starts with this {$what}."
                .($parent === null ? ' No table matching the name was found, so check whether this column is really a relation.' : ''),
            fix: 'ALTER TABLE '.Sql::id($table).' ADD INDEX '.Sql::id($indexName).' ('.Sql::columns($columns).');',
            laravel: Sql::migration($table, '$table->index('.Sql::phpList($columns).');'),
            evidence: ['columns' => implode(', ', $columns), 'references' => $parent ?? 'unknown'],
        );
    }
}
