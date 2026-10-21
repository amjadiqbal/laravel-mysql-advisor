<?php

declare(strict_types=1);

namespace AmjadIqbal\MysqlAdvisor\Checks\Tables;

use AmjadIqbal\MysqlAdvisor\Category;
use AmjadIqbal\MysqlAdvisor\Checks\Check;
use AmjadIqbal\MysqlAdvisor\Checks\Thresholds;
use AmjadIqbal\MysqlAdvisor\Database\Database;
use AmjadIqbal\MysqlAdvisor\Finding;
use AmjadIqbal\MysqlAdvisor\Severity;
use AmjadIqbal\MysqlAdvisor\Support\IndexCatalog;
use AmjadIqbal\MysqlAdvisor\Support\Sql;
use AmjadIqbal\MysqlAdvisor\Support\Tables;

/**
 * Tables with no primary key.
 *
 * InnoDB then clusters rows on a hidden 6-byte row id it never shows you,
 * row-based replication has to scan the whole table for every changed row,
 * and Group Replication and most managed MySQL services refuse the table.
 */
final class MissingPrimaryKeys implements Check
{
    public function id(): string
    {
        return 'missing-primary-key';
    }

    public function title(): string
    {
        return 'Tables without a primary key';
    }

    public function category(): Category
    {
        return Category::Tables;
    }

    public function run(Database $db, Thresholds $thresholds): array
    {
        $catalog = IndexCatalog::load($db);
        $notNull = $this->notNullColumns($db);
        $findings = [];

        foreach (Tables::all($db) as $t) {
            $indexes = $catalog->on($t['name']);

            if (isset($indexes['PRIMARY']) || $thresholds->ignores($t['name'])) {
                continue;
            }

            $promote = $this->promotableUnique($indexes, $notNull[$t['name']] ?? []);
            $table = Sql::id($t['name']);

            $findings[] = new Finding(
                check: $this->id(),
                category: $this->category(),
                severity: Severity::Warning,
                subject: $t['name'],
                title: "{$t['name']} has no primary key",
                detail: 'InnoDB stores the rows in primary-key order, so without one it invents a hidden row id you cannot use. '
                    .'Replicas have to scan the whole table for every row changed on the primary, and Eloquent cannot update or delete a single row reliably. '
                    .($promote !== null
                        ? "The unique index {$promote} on NOT NULL columns can become the primary key."
                        : 'Add an auto-increment id; on a large table this rebuilds it, so run it in a quiet period.'),
                fix: $promote !== null
                    ? "ALTER TABLE {$table} DROP INDEX ".Sql::id($promote).', ADD PRIMARY KEY ('.Sql::columns($indexes[$promote]['columns']).');'
                    : "ALTER TABLE {$table} ADD COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST;",
                laravel: $promote !== null
                    ? Sql::migration($t['name'], "\$table->dropUnique('".addslashes($promote)."');", '$table->primary('.Sql::phpList($indexes[$promote]['columns']).');')
                    : Sql::migration($t['name'], '$table->id()->first();'),
                evidence: ['rows_estimate' => $t['rows'], 'engine' => $t['engine']],
            );
        }

        return $findings;
    }

    /**
     * @return array<string, array<string, true>>
     */
    private function notNullColumns(Database $db): array
    {
        $columns = [];

        foreach ($db->select("SELECT TABLE_NAME AS tbl, COLUMN_NAME AS col FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND IS_NULLABLE = 'NO'", [$db->info()->schema]) as $row) {
            $columns[(string) $row['tbl']][(string) $row['col']] = true;
        }

        return $columns;
    }

    /**
     * @param  array<string, array{unique: bool, type: string, columns: list<string>, parts: list<string>}>  $indexes
     * @param  array<string, true>  $notNull
     */
    private function promotableUnique(array $indexes, array $notNull): ?string
    {
        foreach ($indexes as $name => $index) {
            if ($index['unique'] && $index['type'] === 'BTREE' && $index['parts'] === $index['columns']
                && array_diff($index['columns'], array_keys($notNull)) === []) {
                return $name;
            }
        }

        return null;
    }
}
