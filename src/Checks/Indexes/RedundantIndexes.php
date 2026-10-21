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
 * Indexes that another index on the same table already covers: exact duplicates,
 * and left prefixes such as (user_id) next to (user_id, created_at).
 *
 * Worked out from information_schema, so it needs neither the sys schema nor
 * performance_schema, and gives the same answer on MySQL and MariaDB.
 */
final class RedundantIndexes implements Check
{
    public function id(): string
    {
        return 'redundant-index';
    }

    public function title(): string
    {
        return 'Duplicate and redundant indexes';
    }

    public function category(): Category
    {
        return Category::Indexes;
    }

    public function run(Database $db, Thresholds $thresholds): array
    {
        $catalog = IndexCatalog::load($db);
        $findings = [];

        foreach ($catalog->indexes as $table => $indexes) {
            if ($thresholds->ignores($table)) {
                continue;
            }

            foreach (self::redundantPairs($indexes) as [$redundant, $coveredBy]) {
                $same = $indexes[$redundant]['parts'] === $indexes[$coveredBy]['parts'];

                $findings[] = new Finding(
                    check: $this->id(),
                    category: $this->category(),
                    severity: Severity::Warning,
                    subject: "{$table}.{$redundant}",
                    title: $same
                        ? "Index {$redundant} on {$table} is a duplicate of {$coveredBy}"
                        : "Index {$redundant} on {$table} is covered by {$coveredBy}",
                    detail: ($same
                        ? 'Both indexes hold exactly the same columns, so the optimizer can only ever use one of them.'
                        : "{$coveredBy} starts with the same columns, so any query that can use {$redundant} can use {$coveredBy} instead.")
                        .' The extra index slows every write on '.$table.' and takes memory and disk for nothing.',
                    fix: 'ALTER TABLE '.Sql::id($table).' DROP INDEX '.Sql::id($redundant).';',
                    laravel: Sql::migration($table, "\$table->dropIndex('".addslashes($redundant)."');"),
                    evidence: [
                        'redundant' => $redundant.' ('.implode(', ', $indexes[$redundant]['parts']).')',
                        'covered_by' => $coveredBy.' ('.implode(', ', $indexes[$coveredBy]['parts']).')',
                    ],
                );
            }
        }

        return $findings;
    }

    /**
     * Pure logic, kept static so it can be tested without a server.
     *
     * @param  array<string, array{unique: bool, type: string, columns: list<string>, parts: list<string>}>  $indexes
     * @return list<array{0: string, 1: string}> [redundant index, the index that covers it]
     */
    public static function redundantPairs(array $indexes): array
    {
        $pairs = [];
        $dropped = [];

        foreach ($indexes as $name => $index) {
            if ($name === 'PRIMARY' || $index['type'] !== 'BTREE' || in_array('', $index['columns'], true)) {
                continue;
            }

            foreach ($indexes as $otherName => $other) {
                if ($otherName === $name || isset($dropped[$otherName]) || $other['type'] !== 'BTREE') {
                    continue;
                }

                $prefix = array_slice($other['parts'], 0, count($index['parts'])) === $index['parts'];

                if (! $prefix) {
                    continue;
                }

                $same = count($other['parts']) === count($index['parts']);

                // A unique index enforces a rule; it is only redundant next to an identical unique (or primary) one.
                if ($index['unique'] && ! ($same && $other['unique'])) {
                    continue;
                }

                // Of two identical indexes, report one: keep the unique/primary one, else the one that sorts first.
                if ($same && ! $index['unique'] && ! $other['unique'] && strcmp($name, $otherName) < 0) {
                    continue;
                }

                if ($same && $index['unique'] && $otherName !== 'PRIMARY' && strcmp($name, $otherName) < 0) {
                    continue;
                }

                $pairs[] = [$name, $otherName];
                $dropped[$name] = true;

                continue 2;
            }
        }

        return $pairs;
    }
}
