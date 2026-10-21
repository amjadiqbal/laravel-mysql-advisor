<?php

use AmjadIqbal\MysqlAdvisor\Console\ReportCommand;

it('refuses a connection that is not MySQL or MariaDB with exit code 2', function () {
    config(['database.default' => 'testing', 'database.connections.testing' => ['driver' => 'sqlite', 'database' => ':memory:']]);

    $this->artisan('mysql-advisor:report')
        ->expectsOutputToContain("uses the 'sqlite' driver")
        ->assertExitCode(ReportCommand::UNAVAILABLE);
});

it('rejects an unknown --fail-on value', function () {
    $this->artisan('mysql-advisor:report', ['--fail-on' => 'sometimes'])->assertExitCode(2);
});

it('adds itself to artisan about', function () {
    $this->artisan('about', ['--only' => 'mysql_advisor'])
        ->expectsOutputToContain('MySQL Advisor')
        ->assertSuccessful();
});
