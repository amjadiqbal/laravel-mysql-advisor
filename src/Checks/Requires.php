<?php

declare(strict_types=1);

namespace AmjadIqbal\MysqlAdvisor\Checks;

use AmjadIqbal\MysqlAdvisor\Database\CheckUnavailable;
use AmjadIqbal\MysqlAdvisor\Database\Database;

/**
 * Shared preconditions, so every check explains a missing feature the same way.
 */
final class Requires
{
    public static function performanceSchema(Database $db): void
    {
        if ($db->info()->performanceSchema) {
            return;
        }

        throw new CheckUnavailable(
            'performance_schema is switched off on this server, so it keeps no index or statement statistics.',
            $db->info()->mariaDb
                ? 'Add performance_schema=ON under [mysqld] in my.cnf and restart MariaDB (it is off by default on MariaDB).'
                : 'Add performance_schema=ON under [mysqld] in my.cnf and restart MySQL.',
        );
    }

    public static function consumer(Database $db, string $consumer): void
    {
        $rows = $db->select('SELECT ENABLED AS enabled FROM performance_schema.setup_consumers WHERE NAME = ?', [$consumer]);

        if (strtoupper((string) ($rows[0]['enabled'] ?? 'NO')) !== 'YES') {
            throw new CheckUnavailable(
                "The performance_schema consumer '{$consumer}' is disabled, so this data is not being collected.",
                "UPDATE performance_schema.setup_consumers SET ENABLED = 'YES' WHERE NAME = '{$consumer}';",
            );
        }
    }
}
