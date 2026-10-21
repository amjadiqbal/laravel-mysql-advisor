<?php

declare(strict_types=1);

namespace AmjadIqbal\MysqlAdvisor\Database;

use AmjadIqbal\MysqlAdvisor\ServerInfo;

/**
 * Read access to one MySQL or MariaDB connection.
 */
interface Database
{
    /**
     * Run a read-only statement and return its rows as associative arrays.
     *
     * @param  array<int, mixed>  $bindings
     * @return list<array<string, mixed>>
     *
     * @throws CheckUnavailable when the server refuses or lacks what was asked for.
     */
    public function select(string $sql, array $bindings = []): array;

    /** A SHOW GLOBAL STATUS value, or null when the server does not report it. */
    public function status(string $name): ?string;

    /** A global system variable, or null when the server does not have it. */
    public function variable(string $name): ?string;

    public function info(): ServerInfo;
}
