<?php

declare(strict_types=1);

namespace AmjadIqbal\MysqlAdvisor\Checks\Tables;

use AmjadIqbal\MysqlAdvisor\Category;
use AmjadIqbal\MysqlAdvisor\Checks\Check;
use AmjadIqbal\MysqlAdvisor\Checks\Thresholds;
use AmjadIqbal\MysqlAdvisor\Database\Database;
use AmjadIqbal\MysqlAdvisor\Finding;
use AmjadIqbal\MysqlAdvisor\Severity;
use AmjadIqbal\MysqlAdvisor\Support\Sql;
use AmjadIqbal\MysqlAdvisor\Support\Tables;

/**
 * The tables big enough that their size alone deserves a decision, and those whose indexes outweigh their data.
 */
final class LargeTables implements Check
{
    private const LARAVEL_LOG_TABLES = ['jobs', 'failed_jobs', 'job_batches', 'sessions', 'cache', 'telescope_entries', 'pulse_entries', 'activity_log', 'notifications'];

    public function id(): string
    {
        return 'table-size';
    }

    public function title(): string
    {
        return 'Large tables';
    }

    public function category(): Category
    {
        return Category::Tables;
    }

    public function run(Database $db, Thresholds $thresholds): array
    {
        $large = $thresholds->int('large_table_bytes', 1024 ** 3);
        $findings = [];

        foreach (Tables::all($db) as $t) {
            $total = $t['data'] + $t['index'];

            if ($total < $large || $thresholds->ignores($t['name'])) {
                continue;
            }

            $indexHeavy = $t['data'] > 0 && $t['index'] > $t['data'] * $thresholds->float('index_to_data_ratio', 2.0);
            $logLike = in_array($t['name'], self::LARAVEL_LOG_TABLES, true);

            $findings[] = new Finding(
                check: $this->id(),
                category: $this->category(),
                severity: $indexHeavy || $logLike ? Severity::Warning : Severity::Notice,
                subject: $t['name'],
                title: "{$t['name']} is ".Sql::bytes($total).' (about '.number_format($t['rows']).' rows)',
                detail: match (true) {
                    $logLike => "{$t['name']} is a Laravel framework table that only grows unless something prunes it. Schedule pruning so it stays small.",
                    $indexHeavy => 'Its indexes ('.Sql::bytes($t['index']).') are larger than its data ('.Sql::bytes($t['data']).'). Check the redundant and unused index findings for this table: each index you drop makes every write cheaper.',
                    default => 'Not a problem in itself, but backups, ALTER TABLE and full scans of it take time in proportion. Make sure the queries that read it use indexes, and archive or prune rows you no longer need.',
                },
                fix: null,
                laravel: $logLike ? self::pruneHint($t['name']) : null,
                evidence: ['data' => Sql::bytes($t['data']), 'indexes' => Sql::bytes($t['index']), 'rows_estimate' => $t['rows'], 'engine' => $t['engine']],
            );
        }

        return $findings;
    }

    private static function pruneHint(string $table): string
    {
        return match ($table) {
            'failed_jobs' => "// routes/console.php\nSchedule::command('queue:prune-failed --hours=168')->daily();",
            'job_batches' => "// routes/console.php\nSchedule::command('queue:prune-batches --hours=48')->daily();",
            'telescope_entries' => "// routes/console.php\nSchedule::command('telescope:prune --hours=48')->daily();",
            'sessions' => '// config/session.php: the lottery prunes expired rows; or switch SESSION_DRIVER to redis',
            'cache' => "// routes/console.php: expired rows are only removed on read\nSchedule::command('cache:prune-stale-tags')->hourly(); // or move CACHE_STORE to redis",
            default => "// Use Laravel's Prunable trait on the model, then:\nSchedule::command('model:prune')->daily();",
        };
    }
}
