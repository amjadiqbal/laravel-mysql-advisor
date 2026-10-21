<?php

use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    config(['database.default' => 'testing', 'database.connections.testing' => ['driver' => 'sqlite', 'database' => ':memory:']]);
});

it('is closed outside the local environment by default', function () {
    $this->get('/mysql-advisor')->assertForbidden();
});

it('is open in local and shows a readable error instead of a 500 for a non-MySQL connection', function () {
    app()->detectEnvironment(fn () => 'local');

    $this->get('/mysql-advisor')
        ->assertOk()
        ->assertSee('Could not inspect the')
        ->assertSee("uses the 'sqlite' driver");
});

it('lets the app decide access through the viewMysqlAdvisor gate', function () {
    Gate::define('viewMysqlAdvisor', fn ($user = null) => true);

    $this->get('/mysql-advisor')->assertOk();
});
