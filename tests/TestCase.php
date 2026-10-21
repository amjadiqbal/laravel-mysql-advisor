<?php

namespace AmjadIqbal\MysqlAdvisor\Tests;

use AmjadIqbal\MysqlAdvisor\MysqlAdvisorServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [MysqlAdvisorServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    }
}
