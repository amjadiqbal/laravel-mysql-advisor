<?php

declare(strict_types=1);

namespace AmjadIqbal\MysqlAdvisor;

/**
 * Which server the advisor looked at, and what it was able to see there.
 */
final readonly class ServerInfo
{
    public function __construct(
        public string $connection,
        public string $schema,
        public string $version,
        public bool $mariaDb,
        public int $uptimeSeconds,
        public bool $performanceSchema,
        public string $grantee = "'your_user'@'%'",
    ) {}

    public function flavour(): string
    {
        return $this->mariaDb ? 'MariaDB' : 'MySQL';
    }

    /**
     * How long the server's counters have been collecting, in words.
     *
     * Every "unused" or "slow" claim is only as good as this number: counters
     * reset on restart, so an index used by a monthly job looks unused on day 3.
     */
    public function uptimeForHumans(): string
    {
        $s = $this->uptimeSeconds;

        return match (true) {
            $s >= 86400 => intdiv($s, 86400).' day'.(intdiv($s, 86400) === 1 ? '' : 's'),
            $s >= 3600 => intdiv($s, 3600).' hour'.(intdiv($s, 3600) === 1 ? '' : 's'),
            default => intdiv($s, 60).' minutes',
        };
    }

    /**
     * The statement to put a server setting into effect, the way this flavour does it.
     */
    public function setGlobal(string $variable, string|int $value): string
    {
        return $this->mariaDb
            ? "SET GLOBAL {$variable} = {$value};\n-- and under [mysqld] in my.cnf, so it survives a restart: {$variable} = {$value}"
            : "SET PERSIST {$variable} = {$value};";
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'connection' => $this->connection,
            'schema' => $this->schema,
            'flavour' => $this->flavour(),
            'version' => $this->version,
            'uptime_seconds' => $this->uptimeSeconds,
            'performance_schema' => $this->performanceSchema,
        ];
    }
}
