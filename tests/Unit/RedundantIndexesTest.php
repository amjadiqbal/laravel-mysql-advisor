<?php

use AmjadIqbal\MysqlAdvisor\Checks\Indexes\RedundantIndexes;

function idx(array $columns, bool $unique = false, string $type = 'BTREE'): array
{
    return ['unique' => $unique, 'type' => $type, 'columns' => $columns, 'parts' => $columns];
}

it('finds a left prefix of a longer index', function () {
    expect(RedundantIndexes::redundantPairs([
        'PRIMARY' => idx(['id'], true),
        'posts_user_id_index' => idx(['user_id']),
        'posts_user_id_created_at_index' => idx(['user_id', 'created_at']),
    ]))->toBe([['posts_user_id_index', 'posts_user_id_created_at_index']]);
});

it('reports only one of two identical indexes', function () {
    expect(RedundantIndexes::redundantPairs([
        'a_idx' => idx(['email']),
        'b_idx' => idx(['email']),
    ]))->toBe([['b_idx', 'a_idx']]);
});

it('keeps a unique index next to a longer non-unique one', function () {
    expect(RedundantIndexes::redundantPairs([
        'users_email_unique' => idx(['email'], true),
        'users_email_name_index' => idx(['email', 'name']),
    ]))->toBe([]);
});

it('drops the plain index when a unique one has the same columns', function () {
    expect(RedundantIndexes::redundantPairs([
        'users_email_index' => idx(['email']),
        'users_email_unique' => idx(['email'], true),
    ]))->toBe([['users_email_index', 'users_email_unique']]);
});

it('treats an index on the primary key columns as redundant', function () {
    expect(RedundantIndexes::redundantPairs([
        'PRIMARY' => idx(['id'], true),
        'id_index' => idx(['id']),
    ]))->toBe([['id_index', 'PRIMARY']]);
});

it('ignores different column order, fulltext and prefix lengths that differ', function () {
    expect(RedundantIndexes::redundantPairs([
        'ab' => idx(['a', 'b']),
        'ba' => idx(['b', 'a']),
        'ft' => idx(['a'], false, 'FULLTEXT'),
        'p10' => ['unique' => false, 'type' => 'BTREE', 'columns' => ['c'], 'parts' => ['c(10)']],
        'cfull' => idx(['c', 'd']),
    ]))->toBe([]);
});
