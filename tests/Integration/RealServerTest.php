<?php

use AmjadIqbal\MysqlAdvisor\Checks\Queries\Statements;
use AmjadIqbal\MysqlAdvisor\Finding;
use AmjadIqbal\MysqlAdvisor\MysqlAdvisor;
use AmjadIqbal\MysqlAdvisor\Report;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Builds a schema that really has each problem, runs a real workload against
 * it, and checks every finding appears, then applies the fixes (as the test,
 * never through the package) and checks every finding clears.
 */

function seedProblems(): void
{
    foreach (['comments', 'posts', 'tags', 'users'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('name');
    });

    Schema::create('posts', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('user_id');          // no index, no constraint: the common Laravel slip
        $t->string('title');
        $t->string('slug');
        $t->timestamp('created_at')->nullable();
        $t->index('slug', 'posts_slug_index');
        $t->index('slug', 'posts_slug_copy_index'); // exact duplicate
        $t->index(['slug', 'created_at']);          // makes posts_slug_index redundant too
    });

    Schema::create('comments', function (Blueprint $t) {
        $t->id();
        $t->foreignId('post_id')->constrained();     // InnoDB indexes this itself: must NOT be reported
        $t->string('body_hash', 64);
        $t->index('body_hash');                      // never used by the workload
    });

    Schema::create('tags', function (Blueprint $t) {  // no primary key
        $t->string('name');
        $t->unsignedBigInteger('post_id')->index();
    });

    DB::table('users')->insert(array_map(fn ($i) => ['name' => "user {$i}"], range(1, 50)));

    foreach (array_chunk(range(1, 3000), 500) as $chunk) {
        DB::table('posts')->insert(array_map(fn ($i) => [
            'user_id' => $i % 50 + 1, 'title' => "Post {$i}", 'slug' => "post-{$i}", 'created_at' => now(),
        ], $chunk));
    }

    DB::table('comments')->insert(array_map(fn ($i) => ['post_id' => $i, 'body_hash' => md5((string) $i)], range(1, 200)));
    DB::table('tags')->insert(array_map(fn ($i) => ['name' => "tag {$i}", 'post_id' => $i], range(1, 20)));
}

function runWorkload(): void
{
    // An N+1: a lazy-loaded hasMany touched inside a loop, 1,200 times.
    for ($i = 0; $i < 1200; $i++) {
        DB::select('select * from `users` where `users`.`id` = ? limit 1', [$i % 50 + 1]);
    }

    // A full scan: filtering on an unindexed column of a 3,000-row table.
    for ($i = 0; $i < 25; $i++) {
        DB::select('select * from `posts` where `title` = ?', ["Post {$i}"]);
    }

    // comments is read (so its counters exist), but never through body_hash.
    DB::select('select * from `comments` where `id` = ?', [1]);
    DB::select('select count(*) from `comments`');
}

function resetStatistics(): void
{
    // Test-only housekeeping, as root. The package itself never writes anything.
    DB::statement('TRUNCATE TABLE performance_schema.events_statements_summary_by_digest');
    DB::statement('TRUNCATE TABLE performance_schema.table_io_waits_summary_by_index_usage');
    DB::statement('TRUNCATE TABLE performance_schema.table_io_waits_summary_by_table');
    Statements::flush();
}

function advisorReport(array $only = []): Report
{
    Statements::flush();

    return app(MysqlAdvisor::class)->report(null, $only);
}

/** @return list<string> */
function subjects(Report $report, string $check): array
{
    return array_values(array_map(fn (Finding $f) => $f->subject, array_filter($report->findings(), fn (Finding $f) => $f->check === $check)));
}

beforeEach(function () {
    config(['mysql-advisor.thresholds.unused_index_min_uptime_days' => 0]);
    seedProblems();
    resetStatistics();
    runWorkload();
});

it('sees the server it is talking to', function () {
    $info = advisorReport()->server;

    expect($info->schema)->toBe('advisor')
        ->and($info->performanceSchema)->toBeTrue()
        ->and($info->mariaDb)->toBe((bool) getenv('MYSQL_ADVISOR_TEST_MARIADB'));
});

it('fires every index, query and table check on the seeded problems', function () {
    $report = advisorReport();

    expect(subjects($report, 'redundant-index'))->toContain('posts.posts_slug_copy_index')->toContain('posts.posts_slug_index')
        ->and(subjects($report, 'unindexed-foreign-key'))->toBe(['posts.user_id'])
        ->and(subjects($report, 'unused-index'))->toContain('comments.comments_body_hash_index')
        ->and(subjects($report, 'missing-primary-key'))->toBe(['tags'])
        ->and(count(subjects($report, 'full-table-scan')))->toBe(1)
        ->and(count(subjects($report, 'n-plus-one')))->toBe(1);

    $scan = array_values(array_filter($report->findings(), fn (Finding $f) => $f->check === 'full-table-scan'))[0];
    expect($scan->fix)->toBe('ALTER TABLE `posts` ADD INDEX `posts_title_index` (`title`);');

    $fk = array_values(array_filter($report->findings(), fn (Finding $f) => $f->check === 'unindexed-foreign-key'))[0];
    expect($fk->title)->toContain('points at users')->and($fk->laravel)->toContain("\$table->index('user_id');");

    $nPlusOne = array_values(array_filter($report->findings(), fn (Finding $f) => $f->check === 'n-plus-one'))[0];
    expect($nPlusOne->laravel)->toContain("::with('user')");
});

it('clears every finding once the fixes are applied', function () {
    Schema::table('posts', function (Blueprint $t) {
        $t->dropIndex('posts_slug_copy_index');
        $t->dropIndex('posts_slug_index');
        $t->index('user_id');
        $t->index('title');
    });
    Schema::table('comments', fn (Blueprint $t) => $t->dropIndex('comments_body_hash_index'));
    DB::statement('ALTER TABLE `tags` ADD COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST');

    resetStatistics();

    // The same pages, written properly: one eager load instead of 1,200 lookups, and an indexed filter.
    DB::select('select * from `users` where `users`.`id` in ('.implode(',', range(1, 50)).')');
    for ($i = 0; $i < 25; $i++) {
        DB::select('select * from `posts` where `title` = ?', ["Post {$i}"]);
    }

    $report = advisorReport(['indexes', 'queries', 'tables']);

    foreach (['redundant-index', 'unindexed-foreign-key', 'missing-primary-key', 'full-table-scan', 'n-plus-one'] as $check) {
        expect(subjects($report, $check))->toBe([], "{$check} should have cleared");
    }

    // The indexes the fix just added are unused too, until traffic reaches them; the dropped one is gone.
    expect(subjects($report, 'unused-index'))->not->toContain('comments.comments_body_hash_index');
});

it('says so instead of passing when the app uses native prepared statements', function () {
    config(['database.connections.advisor.options' => [PDO::ATTR_EMULATE_PREPARES => false]]);
    DB::purge('advisor');
    resetStatistics();
    runWorkload();

    $report = advisorReport(['queries']);
    $skipped = collect($report->skipped())->keyBy('check');

    expect($skipped->keys()->all())->toBe(['full-table-scan', 'top-statements', 'n-plus-one'])
        ->and($skipped['n-plus-one']->skippedBecause)->toContain('native prepared statements')
        ->and($skipped['n-plus-one']->howToEnable)->toContain('PDO::ATTR_EMULATE_PREPARES => true');
});

it('fires the size and server checks when their thresholds are reached', function () {
    config(['mysql-advisor.thresholds' => [
        'large_table_bytes' => 1,
        'fragmentation_min_bytes' => 0, 'fragmentation_min_ratio' => 0,
        'buffer_pool_min_requests' => 1, 'buffer_pool_hit_warning' => 1.01,
        'temp_tables_min_created' => 0, 'temp_tables_disk_warning' => 0,
        'connections_warning' => 0,
    ]]);

    // Make sure at least one internal temporary table has been created.
    DB::select('select `user_id`, count(*) from `posts` group by `user_id` order by count(*) desc');

    $report = advisorReport(['tables', 'server']);

    expect(subjects($report, 'table-size'))->toContain('posts')
        ->and(subjects($report, 'buffer-pool-hit-ratio'))->toBe(['innodb_buffer_pool_size'])
        ->and(subjects($report, 'connection-headroom'))->toBe(['max_connections'])
        ->and(subjects($report, 'disk-temp-tables'))->toBe(['tmp_table_size']);

    $fix = array_values(array_filter($report->findings(), fn (Finding $f) => $f->check === 'connection-headroom'))[0]->fix;
    expect($fix)->toStartWith(getenv('MYSQL_ADVISOR_TEST_MARIADB') ? 'SET GLOBAL max_connections' : 'SET PERSIST max_connections');
});

it('passes the server checks on a healthy idle server at default thresholds', function () {
    $report = advisorReport(['server']);

    expect(subjects($report, 'connection-headroom'))->toBe([])
        ->and(subjects($report, 'buffer-pool-hit-ratio'))->toBe([]);
});

it('degrades to skipped checks for a user with no performance_schema access', function () {
    DB::statement("DROP USER IF EXISTS 'restricted'@'%'");
    DB::statement("CREATE USER 'restricted'@'%' IDENTIFIED BY 'restricted'");
    DB::statement("GRANT SELECT ON advisor.* TO 'restricted'@'%'");

    config(['database.connections.restricted' => array_merge(config('database.connections.advisor'), ['username' => 'restricted', 'password' => 'restricted'])]);

    $report = app(MysqlAdvisor::class)->report('restricted');
    $skipped = collect($report->skipped())->keyBy('check');

    expect($skipped->keys()->all())->toContain('unused-index', 'full-table-scan', 'top-statements', 'n-plus-one', 'stale-statistics')
        ->and($skipped['unused-index']->howToEnable)->toBe("GRANT SELECT ON performance_schema.* TO 'restricted'@'%';")
        ->and($skipped['stale-statistics']->howToEnable)->toContain('mysql.innodb_table_stats')
        // information_schema checks still run for the restricted user
        ->and(subjects($report, 'redundant-index'))->toContain('posts.posts_slug_copy_index')
        ->and(subjects($report, 'missing-primary-key'))->toBe(['tags']);

    DB::statement("DROP USER 'restricted'@'%'");
});

it('reports through artisan with a CI exit code', function () {
    $this->artisan('mysql-advisor:report', ['--fail-on' => 'warning'])->assertExitCode(1);
    $this->artisan('mysql-advisor:report', ['--fail-on' => 'never'])->assertExitCode(0);
    $this->artisan('mysql-advisor:report', ['--only' => ['server']])->expectsOutputToContain('checks passed')->assertExitCode(0);
});
