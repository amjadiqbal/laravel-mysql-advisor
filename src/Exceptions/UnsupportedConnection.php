<?php

declare(strict_types=1);

namespace AmjadIqbal\MysqlAdvisor\Exceptions;

use InvalidArgumentException;

final class UnsupportedConnection extends InvalidArgumentException
{
    public static function for(string $connection, ?string $driver): self
    {
        return new self($driver === null
            ? "The database connection '{$connection}' is not configured."
            : "The database connection '{$connection}' uses the '{$driver}' driver. laravel-mysql-advisor only inspects MySQL and MariaDB (driver 'mysql' or 'mariadb'). Set MYSQL_ADVISOR_CONNECTION to a MySQL connection.");
    }
}
