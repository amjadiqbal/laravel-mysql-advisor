<?php

declare(strict_types=1);

namespace AmjadIqbal\MysqlAdvisor\Checks\Queries;

use AmjadIqbal\MysqlAdvisor\Category;
use AmjadIqbal\MysqlAdvisor\Checks\Check;
use AmjadIqbal\MysqlAdvisor\Checks\Thresholds;
use AmjadIqbal\MysqlAdvisor\Database\Database;
use AmjadIqbal\MysqlAdvisor\Finding;
use AmjadIqbal\MysqlAdvisor\Severity;
use AmjadIqbal\MysqlAdvisor\Support\DigestShape;
use AmjadIqbal\MysqlAdvisor\Support\Sql;
use Illuminate\Support\Str;

/**
 * The database's view of an N+1: one tiny single-row lookup, run an enormous number of times.
 *
 * The database never sees your foreach loop. It sees
 * "select * from `posts` where `posts`.`user_id` = ?" ten thousand times, each
 * one fast, which is why a slow-query log never shows it.
 */
final class NPlusOneSuspects implements Check
{
    public function id(): string
    {
        return 'n-plus-one';
    }

    public function title(): string
    {
        return 'N+1 query signatures';
    }

    public function category(): Category
    {
        return Category::Queries;
    }

    /**
     * Guess the Eloquent relation from Laravel's naming conventions.
     *
     * posts.user_id = ?  is User hasMany posts:  User::with('posts')
     * users.id = ?       is a belongsTo user:    Model::with('user')
     */
    public static function eagerLoadHint(string $table, string $column): string
    {
        if (str_ends_with($column, '_id')) {
            $parent = Str::studly(substr($column, 0, -3));
            $line = "{$parent}::with('".Str::camel($table)."')->get();";
        } elseif ($column === 'id') {
            $line = "ChildModel::with('".Str::camel(Str::singular($table))."')->get(); // the model that belongsTo ".Str::studly(Str::singular($table));
        } else {
            $line = "Model::with('relation')->get();";
        }

        return "// eager-load the relation instead of touching it inside a loop:\n{$line}\n\n"
            ."// AppServiceProvider::boot(), so the offending loop throws outside production:\n"
            .'Model::preventLazyLoading(! app()->isProduction());';
    }

    public function run(Database $db, Thresholds $thresholds): array
    {
        $statements = Statements::forSchema($db);
        $minCalls = $thresholds->int('n_plus_one_min_calls', 1000);
        $maxAvg = $thresholds->float('n_plus_one_max_avg_ms', 5.0) * 1e9;
        $findings = [];

        foreach ($statements as $s) {
            if ($s['calls'] < $minCalls || $s['avg'] > $maxAvg) {
                continue;
            }

            $shape = DigestShape::parse($s['text']);

            if (! $shape->isSingleTableLookup() || count($shape->equalityColumns) > 2 || ($shape->table !== null && $thresholds->ignores($shape->table))) {
                continue;
            }

            // A lookup returning about one row per call is what a lazy-loaded relation looks like.
            $rowsPerCall = $s['rows_sent'] / max(1, $s['calls']);

            if ($rowsPerCall > $thresholds->float('n_plus_one_max_rows_per_call', 20.0)) {
                continue;
            }

            $column = $shape->equalityColumns[0];

            $findings[] = new Finding(
                check: $this->id(),
                category: $this->category(),
                severity: Severity::Warning,
                subject: $s['digest'],
                title: number_format($s['calls']).' calls to one lookup on '.$shape->table.' by '.$column,
                detail: 'The same single-table lookup ran '.number_format($s['calls']).' times at '.Sql::duration($s['avg']).' each ('.Sql::duration($s['total']).' in total). '
                    .'That is the signature of an Eloquent relation loaded inside a loop. Eager-load it with with() so it becomes one query per page instead of one per row, '
                    .'and turn on lazy-loading prevention outside production to find the line that does it.',
                fix: null,
                laravel: self::eagerLoadHint((string) $shape->table, $column),
                evidence: [
                    'statement' => Statements::short($s['text'], 400),
                    'calls' => $s['calls'],
                    'average' => Sql::duration($s['avg']),
                    'rows_per_call' => round($rowsPerCall, 1),
                ],
            );
        }

        return $findings;
    }
}
