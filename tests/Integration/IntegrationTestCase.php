<?php

namespace AmjadIqbal\MysqlAdvisor\Tests\Integration;

use AmjadIqbal\MysqlAdvisor\Checks\Queries\Statements;
use AmjadIqbal\MysqlAdvisor\Tests\TestCase;

/**
 * Runs against a real server. Start one with scripts/integration.sh, or point
 * MYSQL_ADVISOR_TEST_HOST / _PORT / _USER / _PASSWORD / _DATABASE at your own.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected function setUp(): void
    {
        if (! getenv('MYSQL_ADVISOR_TEST_PORT')) {
            $this->markTestSkipped('Set MYSQL_ADVISOR_TEST_PORT (see scripts/integration.sh) to run against a real MySQL or MariaDB.');
        }

        parent::setUp();
        Statements::flush();
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'advisor');
        $app['config']->set('database.connections.advisor', [
            'driver' => getenv('MYSQL_ADVISOR_TEST_DRIVER') ?: 'mysql',
            'host' => getenv('MYSQL_ADVISOR_TEST_HOST') ?: '127.0.0.1',
            'port' => getenv('MYSQL_ADVISOR_TEST_PORT'),
            'database' => getenv('MYSQL_ADVISOR_TEST_DATABASE') ?: 'advisor',
            'username' => getenv('MYSQL_ADVISOR_TEST_USER') ?: 'root',
            'password' => getenv('MYSQL_ADVISOR_TEST_PASSWORD') ?: 'root',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            // Native prepares are invisible to performance_schema digests (see RealServerTest).
            'options' => [\PDO::ATTR_EMULATE_PREPARES => true],
        ]);
    }
}
