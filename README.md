<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="art/banner-dark.png">
    <img alt="Laravel MySQL Advisor: read-only MySQL and MariaDB health checks that show the SQL to fix each problem" src="art/banner-light.png" width="100%">
  </picture>
</p>

# Laravel MySQL Advisor: MySQL and MariaDB health checks for Laravel

A read-only health advisor for the MySQL or MariaDB database behind your Laravel app. It finds unused,
duplicate and missing indexes, full table scans, slow and N+1 queries, tables without a primary key,
fragmented tables and server memory or connection pressure. For each problem it shows **the exact SQL
or Laravel migration that fixes it**, as a Blade dashboard and as an artisan report that can fail CI.

It never runs a fix. Every statement it sends is a `SELECT` or `SHOW`, and you can check that in one
function ([`ReadOnlyGuard`](src/Database/ReadOnlyGuard.php)).

[![Latest Version](https://img.shields.io/packagist/v/amjadiqbal/laravel-mysql-advisor.svg?style=flat-square)](https://packagist.org/packages/amjadiqbal/laravel-mysql-advisor)
[![PHP Version](https://img.shields.io/packagist/php-v/amjadiqbal/laravel-mysql-advisor?style=flat-square)](https://packagist.org/packages/amjadiqbal/laravel-mysql-advisor)
[![License](https://img.shields.io/packagist/l/amjadiqbal/laravel-mysql-advisor.svg?style=flat-square)](LICENSE.md)

```bash
composer require amjadiqbal/laravel-mysql-advisor
php artisan mysql-advisor:report
```

```text
  WARNING unindexed-foreign-key  1. No index on posts.user_id (points at users)
     Looking up the posts rows that belong to one users row, which is what every Eloquent relation,
     eager load and cascading delete does, has to read the whole posts table because no index starts
     with this column user_id.
     Fix (SQL):
       ALTER TABLE `posts` ADD INDEX `posts_user_id_index` (`user_id`);
     Fix (Laravel):
       Schema::table('posts', function (Blueprint $table) {
           $table->index('user_id');
       });
```

## What it checks

| Area | Check | Finds | Needs |
|---|---|---|---|
| Indexes | `unused-index` | Secondary indexes no query has read since the server started | performance_schema |
| | `redundant-index` | Exact duplicates, and left prefixes such as `(user_id)` next to `(user_id, created_at)` | nothing extra |
| | `unindexed-foreign-key` | `*_id` columns and foreign keys that no index starts with | nothing extra |
| Queries | `full-table-scan` | Statements that used no index and read many rows per call | performance_schema |
| | `top-statements` | Where the database's time goes, ranked by total time | performance_schema |
| | `n-plus-one` | One tiny lookup run thousands of times: a relation loaded in a loop | performance_schema |
| Tables | `missing-primary-key` | Tables with no primary key | nothing extra |
| | `table-size` | Tables over 1 GB, framework tables nobody prunes, indexes bigger than the data | nothing extra |
| | `fragmentation` | Tables with a lot of free space inside their files | nothing extra |
| | `stale-statistics` | Optimizer statistics taken before most of the table changed | `mysql.innodb_table_stats` |
| Server | `buffer-pool-hit-ratio` | InnoDB reading from disk too often for its memory | nothing extra |
| | `disk-temp-tables` | GROUP BY / ORDER BY temporary tables spilling to disk | nothing extra |
| | `connection-headroom` | How close the server came to "Too many connections" | nothing extra |

Each finding has a severity (critical, warning or notice), the numbers it was based on, and where it
applies, the fix as SQL and as a Laravel migration or Eloquent snippet. Unused-index fixes come in two
steps: first hide the index from the optimizer (`INVISIBLE` on MySQL, `IGNORED` on MariaDB), which you
can undo instantly, and only then drop it.

## Why this package

MySQL already keeps most of this information in `performance_schema` and `information_schema`, but
nothing in a Laravel app reads it for you. Laravel's own `db:show` lists sizes, and `db:monitor` counts
connections. Neither tells you which index to drop or which relation to eager-load.

Two Laravel-specific blind spots are built in:

- **`foreignId('user_id')` without `->constrained()` creates no index.** InnoDB only indexes declared
  foreign keys, so the column every `$user->posts` filters on is scanned in full. This check reads Laravel's
  naming convention as well as the declared keys.
- **An N+1 never shows up in a slow-query log.** The database sees one fast statement run ten thousand
  times. Ranking by total time and call count is what catches it.

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- MySQL 8.0+ or MariaDB 10.6+ (tested on MySQL 8.4 and MariaDB 11)

### Make your queries visible: emulated prepares

Laravel sends queries as **native prepared statements** by default (`PDO::ATTR_EMULATE_PREPARES => false`).
MySQL and MariaDB keep **no per-query statistics** for those: they count them as `statement/com/Execute` with
no SQL text. With Laravel's defaults the three query checks have nothing to read. When that happens the
advisor reports them as skipped and tells you why, so it never looks like a clean bill of health.

To make every query visible, switch the connection to emulated prepares:

```php
// config/database.php
'mysql' => [
    // ...
    'options' => extension_loaded('pdo_mysql') ? array_filter([
        PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
        PDO::ATTR_EMULATE_PREPARES => true,
    ]) : [],
],
```

Emulated prepares send one round trip per query instead of three (prepare, execute, close). Since
PHP 8.1 they still return native `int` and `float` values. Try the change on staging first, like any
driver setting.

### performance_schema

The index-usage and query checks read `performance_schema`. It is **on by default in MySQL** and **off by
default in MariaDB**. To turn it on, add `performance_schema=ON` under `[mysqld]` and restart. When it is off,
or your database user may not read it, those checks are listed as skipped with the exact `GRANT` or
setting to enable them. The other checks still run.

## Installation

```bash
composer require amjadiqbal/laravel-mysql-advisor
```

The service provider registers itself. To change thresholds or the list of checks, publish the config:

```bash
php artisan vendor:publish --tag=mysql-advisor-config
```

### A read-only database user (recommended)

The advisor only reads, but the simplest proof is a user that cannot write:

```sql
CREATE USER 'advisor'@'%' IDENTIFIED BY 'a-long-password';
GRANT SELECT ON your_app.* TO 'advisor'@'%';
GRANT SELECT ON performance_schema.* TO 'advisor'@'%';
GRANT SELECT ON mysql.innodb_table_stats TO 'advisor'@'%';
```

Add it as its own connection in `config/database.php` (a copy of `mysql` with the new username and
password) and point the advisor at it:

```env
MYSQL_ADVISOR_CONNECTION=mysql_advisor
```

On managed services such as Amazon RDS, Aurora, Google Cloud SQL, DigitalOcean or PlanetScale, grant what the
service allows. Anything it does not allow appears as a skipped check, never as an error.

## The dashboard

Open `/mysql-advisor`. You get the findings worst first, each with a copy button for its fix, filters for
indexes, queries, tables and server, plus the skipped checks with the reason and the way to enable each one.
The page has no assets to build or publish: it is one Blade view with inline CSS, in light and dark themes.

**Access.** The dashboard works in the `local` environment and returns 403 everywhere else until you
decide who may see it. Define the `viewMysqlAdvisor` gate in `AppServiceProvider::boot()`:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewMysqlAdvisor', function ($user = null) {
    return $user?->email === 'you@example.com';
});
```

Keep the `= null` (or type it `?User $user`) if guests should be able to reach the callback at all. Laravel
skips a gate for guests otherwise. The gate is added after the configured middleware, so changing
`dashboard.middleware` (for example to `['web', 'auth']`) cannot remove it. Turn the dashboard off entirely
with `MYSQL_ADVISOR_DASHBOARD=false`.

## The artisan report and CI

```bash
php artisan mysql-advisor:report                      # everything, as text
php artisan mysql-advisor:report --only=indexes       # a category: indexes, queries, tables, server
php artisan mysql-advisor:report --only=unused-index  # or a single check id (repeat or comma-separate)
php artisan mysql-advisor:report --format=json        # for scripts and dashboards
php artisan mysql-advisor:report --connection=replica
```

Exit codes:

| Code | Meaning |
|---|---|
| `0` | No finding at or above `--fail-on` |
| `1` | At least one finding at or above `--fail-on` (default `critical`; also `warning`, `notice`, `never`) |
| `2` | The database could not be inspected at all (not MySQL/MariaDB, unreachable) |

A schema check runs well in CI against the database your tests already migrate:

```bash
php artisan migrate --force
php artisan mysql-advisor:report --only=redundant-index,unindexed-foreign-key,missing-primary-key --fail-on=warning
```

The usage-based checks (unused indexes, queries, buffer pool) only mean something on a server that has
been serving real traffic, so run those on a schedule against staging or a replica:

```php
// routes/console.php
Schedule::command('mysql-advisor:report --fail-on=warning')->dailyAt('07:00')->emailOutputOnFailure('ops@example.com');
```

## Reading the findings with care

- **Counters restart with the server.** "Unused since start" after a restart two days ago proves little.
  The report states the uptime, and until the server has been up `unused_index_min_uptime_days` (7)
  days, unused indexes are only notices.
- **Counters are per server.** An index unused on the primary may serve every read on a replica. Run the
  report against the replica too (`--connection=`).
- **Index suggestions for full scans are a starting point.** They index the columns compared with `= ?`.
  Confirm with `EXPLAIN` on a real query first.

## Configuration

`config/mysql-advisor.php`:

- `connection`: which connection to inspect (default: your default connection)
- `dashboard.enabled`, `dashboard.path`, `dashboard.domain`, `dashboard.middleware`
- `checks`: the list of check classes. Remove one to switch it off.
- `ignore_tables`: table names or shell patterns (`telescope_*`) that no check reports on
- `thresholds`: every number a check compares against, each with a comment

### Your own check

Implement `AmjadIqbal\MysqlAdvisor\Checks\Check` and add the class to `checks`:

```php
use AmjadIqbal\MysqlAdvisor\Category;
use AmjadIqbal\MysqlAdvisor\Checks\Check;
use AmjadIqbal\MysqlAdvisor\Checks\Thresholds;
use AmjadIqbal\MysqlAdvisor\Database\Database;
use AmjadIqbal\MysqlAdvisor\Finding;
use AmjadIqbal\MysqlAdvisor\Severity;

final class LatinTables implements Check
{
    public function id(): string { return 'latin1-tables'; }
    public function title(): string { return 'Tables not in utf8mb4'; }
    public function category(): Category { return Category::Tables; }

    public function run(Database $db, Thresholds $thresholds): array
    {
        $rows = $db->select(
            "SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_COLLATION NOT LIKE 'utf8mb4%'",
            [$db->info()->schema],
        );

        return array_map(fn (array $row) => new Finding(
            check: $this->id(), category: $this->category(), severity: Severity::Warning,
            subject: $row['name'], title: "{$row['name']} is not utf8mb4",
            detail: 'Emoji and some names will fail to save.',
            fix: "ALTER TABLE `{$row['name']}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",
        ), $rows);
    }
}
```

`$db->select()` refuses anything that is not a `SELECT` or `SHOW`. If your check throws
`CheckUnavailable`, it is reported as skipped with your reason. Any other exception is reported as a skipped
check with its message. Either way, one broken check never breaks the report or the dashboard.

## Testing

```bash
composer test                # unit and feature tests
scripts/integration.sh       # real MySQL 8.4 and MariaDB 11 in Docker: every check fires on a seeded schema, then clears
scripts/ci-local.sh          # PHP 8.3-8.5 x Laravel 12-13, Pint, PHPStan, then the integration run
```

## Need this built into your app, or something custom?

I tune MySQL-backed Laravel apps, from slow pages and N+1 hunts to index reviews and the queue and
connection settings around them, and I build Laravel packages. If you would rather have someone go through
these findings with you, or you need something this package does not do,
[get in touch](https://amjad.com.pk/contact?utm_source=github&utm_medium=readme&utm_campaign=laravel-mysql-advisor).

## Support

- 🐛 **Bug or feature request**: [open an issue](https://github.com/amjadiqbal/laravel-mysql-advisor/issues)
- 🔒 **Security issue**: please report privately via GitHub's security tab

## Need this customised, or something built?

I'm available for custom development, package integration, and technical consulting.

[![Hire me on Upwork](https://img.shields.io/badge/Hire%20me-Upwork-6FDA44?style=flat-square&logo=upwork&logoColor=white)](https://www.upwork.com/freelancers/amjadkhatri)

## More packages

Part of a family of open-source packages. See [all of them](https://github.com/amjadiqbal?tab=repositories).

| | |
|---|---|
| [kiln](https://github.com/amjadiqbal/kiln) | Deploy-time OPcache control for Laravel |
| [refresher](https://github.com/amjadiqbal/refresher) | Cache your test DB schema, skip `migrate:fresh` on every test run |
| [laravel-logpulse](https://github.com/amjadiqbal/laravel-logpulse) | Log health monitoring and alerting |
| [laravel-driver-js](https://github.com/amjadiqbal/laravel-driver-js) | Product tours and onboarding for Laravel |

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

MIT. See [LICENSE.md](LICENSE.md). MySQL is a trademark of Oracle and MariaDB is a trademark of MariaDB
plc; this package is independent and not affiliated with either.
