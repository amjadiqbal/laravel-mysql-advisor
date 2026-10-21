<?php

use AmjadIqbal\MysqlAdvisor\Checks;

return [

    /*
    |--------------------------------------------------------------------------
    | Connection
    |--------------------------------------------------------------------------
    |
    | The MySQL or MariaDB connection to inspect. Null uses your default
    | connection. Everything this package runs is a SELECT or SHOW, so the
    | safest setup is a separate connection with a read-only user:
    |
    |   CREATE USER 'advisor'@'%' IDENTIFIED BY '...';
    |   GRANT SELECT ON your_app.* TO 'advisor'@'%';
    |   GRANT SELECT ON performance_schema.* TO 'advisor'@'%';
    |   GRANT SELECT ON mysql.innodb_table_stats TO 'advisor'@'%';
    |
    | Without the last two grants the checks that need them are skipped and
    | the report says so; nothing fails.
    |
    */

    'connection' => env('MYSQL_ADVISOR_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    |
    | The Blade dashboard is served at /{path}. Access is decided by the
    | "viewMysqlAdvisor" gate, which allows the local environment only until
    | you define it yourself (see the README). The gate check is always added
    | after the middleware below, so emptying this list cannot expose it.
    |
    */

    'dashboard' => [
        'enabled' => env('MYSQL_ADVISOR_DASHBOARD', true),
        'path' => env('MYSQL_ADVISOR_PATH', 'mysql-advisor'),
        'domain' => env('MYSQL_ADVISOR_DOMAIN'),
        'middleware' => ['web'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Checks
    |--------------------------------------------------------------------------
    |
    | Remove a line to switch a check off, or add your own class that
    | implements AmjadIqbal\MysqlAdvisor\Checks\Check.
    |
    */

    'checks' => [
        Checks\Indexes\UnusedIndexes::class,
        Checks\Indexes\RedundantIndexes::class,
        Checks\Indexes\UnindexedForeignKeys::class,
        Checks\Queries\FullTableScans::class,
        Checks\Queries\SlowestStatements::class,
        Checks\Queries\NPlusOneSuspects::class,
        Checks\Tables\LargeTables::class,
        Checks\Tables\Fragmentation::class,
        Checks\Tables\MissingPrimaryKeys::class,
        Checks\Tables\StaleStatistics::class,
        Checks\Server\BufferPoolHitRatio::class,
        Checks\Server\DiskTemporaryTables::class,
        Checks\Server\ConnectionHeadroom::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Ignored tables
    |--------------------------------------------------------------------------
    |
    | Table names (shell patterns, e.g. "telescope_*") no check reports on.
    |
    */

    'ignore_tables' => [],

    /*
    |--------------------------------------------------------------------------
    | Thresholds
    |--------------------------------------------------------------------------
    */

    'thresholds' => [
        // Indexes
        'unused_index_min_uptime_days' => 7,     // below this, "unused" is only a notice: counters reset on restart

        // Queries (performance_schema statement digests)
        'full_scan_min_calls' => 10,
        'full_scan_min_rows_per_call' => 1000,
        'full_scan_critical_rows_per_call' => 100000,
        'top_statements' => 5,
        'top_statement_min_share' => 0.05,       // report a top statement using at least 5% of all query time
        'slow_statement_ms' => 500,              // ...or averaging this long per call
        'n_plus_one_min_calls' => 1000,
        'n_plus_one_max_avg_ms' => 5.0,
        'n_plus_one_max_rows_per_call' => 20,
        'invisible_prepares_ratio' => 0.25,      // note when native prepared statements outnumber visible ones by this share

        // Tables
        'large_table_bytes' => 1024 ** 3,        // 1 GB
        'index_to_data_ratio' => 2.0,
        'fragmentation_min_bytes' => 100 * 1024 ** 2,
        'fragmentation_min_ratio' => 0.2,
        'stale_statistics_days' => 30,
        'stale_statistics_change_ratio' => 0.1,

        // Server
        'buffer_pool_min_requests' => 100000,
        'buffer_pool_hit_warning' => 0.99,
        'buffer_pool_hit_critical' => 0.95,
        'temp_tables_min_created' => 1000,
        'temp_tables_disk_warning' => 0.25,
        'temp_tables_disk_critical' => 0.5,
        'connections_warning' => 0.8,
        'connections_critical' => 0.95,
    ],

];
