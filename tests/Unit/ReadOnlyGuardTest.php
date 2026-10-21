<?php

use AmjadIqbal\MysqlAdvisor\Database\ReadOnlyGuard;

it('allows reads', function (string $sql) {
    ReadOnlyGuard::assertReadOnly($sql);
    expect(true)->toBeTrue();
})->with([
    'select' => 'SELECT 1',
    'lowercase show' => 'show global status',
    'leading comment' => '/* advisor */ SELECT * FROM information_schema.TABLES',
    'cte' => 'WITH x AS (SELECT 1) SELECT * FROM x',
    'semicolon in a string' => "SELECT * FROM t WHERE name = 'a;b'",
    'trailing semicolon' => 'SELECT 1;',
]);

it('refuses anything that is not a single read', function (string $sql) {
    ReadOnlyGuard::assertReadOnly($sql);
})->with([
    'drop' => 'DROP INDEX x ON t',
    'alter' => 'ALTER TABLE t DROP INDEX x',
    'optimize' => 'OPTIMIZE TABLE t',
    'set' => 'SET GLOBAL max_connections = 10',
    'stacked' => 'SELECT 1; DROP TABLE t',
    'comment hiding a write' => '/* SELECT */ DELETE FROM t',
    'into outfile' => "SELECT * FROM t INTO OUTFILE '/tmp/x'",
    'for update' => 'SELECT * FROM t FOR UPDATE',
])->throws(LogicException::class);
