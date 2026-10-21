<?php

use AmjadIqbal\MysqlAdvisor\Checks\Indexes\UnindexedForeignKeys;
use AmjadIqbal\MysqlAdvisor\Checks\Queries\NPlusOneSuspects;
use AmjadIqbal\MysqlAdvisor\Checks\Queries\Statements;
use AmjadIqbal\MysqlAdvisor\Checks\Thresholds;
use AmjadIqbal\MysqlAdvisor\Database\MysqlDatabase;
use AmjadIqbal\MysqlAdvisor\ServerInfo;
use AmjadIqbal\MysqlAdvisor\Support\Sql;

it('quotes identifiers', function () {
    expect(Sql::id('order`s'))->toBe('`order``s`')->and(Sql::columns(['a', 'b']))->toBe('`a`, `b`');
});

it('formats bytes and picosecond durations', function () {
    expect(Sql::bytes(512))->toBe('512 B')
        ->and(Sql::bytes(1536))->toBe('1.5 KB')
        ->and(Sql::bytes(3 * 1024 ** 3))->toBe('3.0 GB')
        ->and(Sql::duration(2_500_000))->toBe('3 µs')
        ->and(Sql::duration(1.5e9))->toBe('1.5 ms')
        ->and(Sql::duration(2e12))->toBe('2.00 s');
});

it('writes a Laravel migration snippet', function () {
    expect(Sql::migration('posts', "\$table->index('user_id');"))->toBe("Schema::table('posts', function (Blueprint \$table) {\n    \$table->index('user_id');\n});");
});

it('turns CURRENT_USER() into a GRANT target', function () {
    expect(MysqlDatabase::grantee('advisor@%'))->toBe("'advisor'@'%'")
        ->and(MysqlDatabase::grantee('app@10.0.0.%'))->toBe("'app'@'10.0.0.%'")
        ->and(MysqlDatabase::grantee(''))->toBe("'your_user'@'%'");
});

it('finds the parent table by Laravel naming', function () {
    $tables = ['users' => 1, 'categories' => 1, 'statuses' => 1, 'media' => 1];

    expect(UnindexedForeignKeys::parentTable('user', $tables))->toBe('users')
        ->and(UnindexedForeignKeys::parentTable('category', $tables))->toBe('categories')
        ->and(UnindexedForeignKeys::parentTable('status', $tables))->toBe('statuses')
        ->and(UnindexedForeignKeys::parentTable('media', $tables))->toBe('media')
        ->and(UnindexedForeignKeys::parentTable('stripe', $tables))->toBeNull();
});

it('names the eager load for both sides of a relation', function () {
    expect(NPlusOneSuspects::eagerLoadHint('posts', 'user_id'))->toContain("User::with('posts')")
        ->and(NPlusOneSuspects::eagerLoadHint('users', 'id'))->toContain("::with('user')")
        ->and(NPlusOneSuspects::eagerLoadHint('posts', 'user_id'))->toContain('Model::preventLazyLoading');
});

it('uses SET PERSIST on MySQL and SET GLOBAL plus my.cnf on MariaDB', function () {
    $mysql = new ServerInfo('mysql', 'app', '8.4.3', false, 1, true);
    $maria = new ServerInfo('mysql', 'app', '11.4.2-MariaDB', true, 1, false);

    expect($mysql->setGlobal('max_connections', 300))->toBe('SET PERSIST max_connections = 300;')
        ->and($maria->setGlobal('max_connections', 300))->toStartWith('SET GLOBAL max_connections = 300;')->toContain('my.cnf');
});

it('matches ignored tables by pattern', function () {
    $t = new Thresholds([], ['telescope_*', 'sessions']);

    expect($t->ignores('telescope_entries'))->toBeTrue()->and($t->ignores('sessions'))->toBeTrue()->and($t->ignores('users'))->toBeFalse();
});

it('offers EXPLAIN only for reads, as a template to fill in', function () {
    expect(Statements::explain('SELECT * FROM `t` WHERE `a` = ?'))
        ->toBe("-- replace each ? with a real value from your logs, then run:\nEXPLAIN SELECT * FROM `t` WHERE `a` = ?;")
        ->and(Statements::explain('INSERT INTO `t` VALUES (...)'))->toBeNull();
});
