<?php

declare(strict_types=1);

namespace AmjadIqbal\MysqlAdvisor;

use AmjadIqbal\MysqlAdvisor\Checks\Check;
use AmjadIqbal\MysqlAdvisor\Checks\Thresholds;
use AmjadIqbal\MysqlAdvisor\Database\Database;
use AmjadIqbal\MysqlAdvisor\Database\MysqlDatabase;
use AmjadIqbal\MysqlAdvisor\Exceptions\UnsupportedConnection;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DatabaseManager;

/**
 * The entry point: build the database reader for a connection and run the checks on it.
 */
final readonly class MysqlAdvisor
{
    public function __construct(
        private Container $container,
        private DatabaseManager $databases,
        private Repository $config,
    ) {}

    /**
     * @param  list<string>  $only  Category names or check ids; empty means all.
     */
    public function report(?string $connection = null, array $only = []): Report
    {
        return $this->advisor()->run($this->database($connection), $only);
    }

    public function database(?string $connection = null): Database
    {
        $name = $connection ?? $this->connectionName();
        $driver = $this->config->get("database.connections.{$name}.driver");

        if (! is_string($driver) || ! MysqlDatabase::supports($driver)) {
            throw UnsupportedConnection::for($name, is_string($driver) ? $driver : null);
        }

        return new MysqlDatabase($this->databases->connection($name), $name);
    }

    public function connectionName(): string
    {
        $name = $this->config->get('mysql-advisor.connection') ?? $this->config->get('database.default');

        return is_string($name) ? $name : 'mysql';
    }

    public function advisor(): Advisor
    {
        $checks = [];

        foreach ((array) $this->config->get('mysql-advisor.checks', []) as $class) {
            $check = is_string($class) ? $this->container->make($class) : $class;

            if ($check instanceof Check) {
                $checks[] = $check;
            }
        }

        $ignore = array_values(array_filter((array) $this->config->get('mysql-advisor.ignore_tables', []), 'is_string'));

        return new Advisor($checks, new Thresholds((array) $this->config->get('mysql-advisor.thresholds', []), $ignore));
    }
}
