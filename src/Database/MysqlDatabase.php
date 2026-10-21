<?php

declare(strict_types=1);

namespace AmjadIqbal\MysqlAdvisor\Database;

use AmjadIqbal\MysqlAdvisor\ServerInfo;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

final class MysqlDatabase implements Database
{
    /** @var array<string, string>|null */
    private ?array $status = null;

    /** @var array<string, string>|null */
    private ?array $variables = null;

    private ?ServerInfo $info = null;

    public function __construct(private readonly ConnectionInterface $connection, private readonly string $name) {}

    public static function supports(string $driver): bool
    {
        return in_array($driver, ['mysql', 'mariadb'], true);
    }

    public function select(string $sql, array $bindings = []): array
    {
        ReadOnlyGuard::assertReadOnly($sql);

        try {
            $rows = $this->connection->select($sql, $bindings);
        } catch (QueryException $e) {
            throw $this->translate($e);
        }

        return array_values(array_map(static fn (object|array $row): array => array_change_key_case((array) $row, CASE_LOWER), $rows));
    }

    public function status(string $name): ?string
    {
        $this->status ??= $this->pairs('SHOW GLOBAL STATUS');

        return $this->status[strtolower($name)] ?? null;
    }

    public function variable(string $name): ?string
    {
        $this->variables ??= $this->pairs('SHOW GLOBAL VARIABLES');

        return $this->variables[strtolower($name)] ?? null;
    }

    public function info(): ServerInfo
    {
        if ($this->info !== null) {
            return $this->info;
        }

        $row = $this->select('SELECT VERSION() AS version, DATABASE() AS db, CURRENT_USER() AS grantee')[0] ?? [];
        $version = (string) ($row['version'] ?? '');

        if (($row['db'] ?? null) === null) {
            throw new InvalidArgumentException("The '{$this->name}' connection has no database selected.");
        }

        return $this->info = new ServerInfo(
            connection: $this->name,
            schema: (string) $row['db'],
            version: $version,
            mariaDb: stripos($version, 'mariadb') !== false,
            uptimeSeconds: (int) ($this->status('Uptime') ?? 0),
            performanceSchema: in_array(strtoupper((string) $this->variable('performance_schema')), ['ON', '1'], true),
            grantee: self::grantee((string) ($row['grantee'] ?? '')),
        );
    }

    /** 'app@%' as returned by CURRENT_USER() becomes 'app'@'%' for a GRANT statement. */
    public static function grantee(string $currentUser): string
    {
        $at = strrpos($currentUser, '@');

        if ($at === false) {
            return "'your_user'@'%'";
        }

        return "'".substr($currentUser, 0, $at)."'@'".substr($currentUser, $at + 1)."'";
    }

    /**
     * @return array<string, string>
     */
    private function pairs(string $sql): array
    {
        $pairs = [];

        foreach ($this->select($sql) as $row) {
            $pairs[strtolower((string) $row['variable_name'])] = (string) $row['value'];
        }

        return $pairs;
    }

    /**
     * Turn "you may not look at that" into a skipped check, not a crash.
     */
    private function translate(QueryException $e): \Throwable
    {
        $code = (int) ($e->errorInfo[1] ?? 0);
        $grantee = $this->info->grantee ?? "'your_user'@'%'";
        $hint = str_contains(strtolower($e->getMessage()), 'innodb_table_stats') || str_contains(strtolower($e->getMessage()), "'mysql'")
            ? "GRANT SELECT ON mysql.innodb_table_stats TO {$grantee};"
            : "GRANT SELECT ON performance_schema.* TO {$grantee};";

        return match ($code) {
            // ER_TABLEACCESS_DENIED_ERROR, ER_DBACCESS_DENIED_ERROR, ER_SPECIFIC_ACCESS_DENIED_ERROR, ER_COLUMNACCESS_DENIED_ERROR
            1142, 1044, 1227, 1143 => new CheckUnavailable(
                'The database user is not allowed to read this: '.$this->firstLine($e),
                $hint,
            ),
            // ER_NO_SUCH_TABLE, ER_BAD_DB_ERROR
            1146, 1049 => new CheckUnavailable(
                'This server does not have the table this check reads: '.$this->firstLine($e),
            ),
            default => $e,
        };
    }

    private function firstLine(QueryException $e): string
    {
        $message = $e->getPrevious()?->getMessage() ?? $e->getMessage();

        return trim(strtok($message, "\n") ?: $message);
    }
}
