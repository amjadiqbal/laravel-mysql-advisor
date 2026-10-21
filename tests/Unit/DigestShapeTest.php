<?php

use AmjadIqbal\MysqlAdvisor\Support\DigestShape;

it('reads an Eloquent lazy load', function () {
    $shape = DigestShape::parse('SELECT * FROM `posts` WHERE `posts` . `user_id` = ? AND `posts` . `user_id` IS NOT NULL');

    expect($shape->table)->toBe('posts')
        ->and($shape->equalityColumns)->toBe(['user_id'])
        ->and($shape->isSingleTableLookup())->toBeTrue();
});

it('reads a belongsTo lookup with a limit', function () {
    $shape = DigestShape::parse('SELECT * FROM `users` WHERE `users` . `id` = ? LIMIT ?');

    expect($shape->table)->toBe('users')->and($shape->equalityColumns)->toBe(['id']);
});

it('reads IN lists and several equality columns', function () {
    $shape = DigestShape::parse('SELECT * FROM `orders` WHERE `status` = ? AND `customer_id` IN (...) ORDER BY `id`');

    expect($shape->equalityColumns)->toBe(['status', 'customer_id']);
});

it('does not guess at joins', function () {
    $shape = DigestShape::parse('SELECT * FROM `a` JOIN `b` ON `a` . `id` = `b` . `a_id` WHERE `b` . `x` = ?');

    expect($shape->joins)->toBeTrue()->and($shape->isSingleTableLookup())->toBeFalse();
});

it('handles schema-qualified tables and non-select verbs', function () {
    $shape = DigestShape::parse('UPDATE `app` . `jobs` SET `reserved_at` = ? WHERE `id` = ?');

    expect($shape->verb)->toBe('UPDATE')->and($shape->table)->toBe('jobs')->and($shape->isSingleTableLookup())->toBeFalse();
});
