<?php

declare(strict_types=1);

namespace AmjadIqbal\MysqlAdvisor\Checks\Queries;

use AmjadIqbal\MysqlAdvisor\Checks\Requires;
use AmjadIqbal\MysqlAdvisor\Database\CheckUnavailable;
use AmjadIqbal\MysqlAdvisor\Database\Database;

/**
 * The statement digests performance_schema has summarised for this schema since startup.
 */
final class Statements
{
    /** @var array<string, list<array<string, mixed>>> */
    private static array $cache = [];

    /** @var array<string, int> */
    private static array $executes = [];

    /** @var array<string, CheckUnavailable> */
    private static array $unavailable = [];

    public const EMULATE_PREPARES_HINT = "// config/database.php, in your mysql/mariadb connection:\n"
        ."'options' => [\n    PDO::ATTR_EMULATE_PREPARES => true,\n    // ...keep any options already there\n],";

    /**
     * @return list<array{digest: string, text: string, calls: int, total: float, avg: float, rows_examined: int, rows_sent: int, no_index: int}>
     */
    public static function forSchema(Database $db): array
    {
        Requires::performanceSchema($db);

        $key = spl_object_id($db).':'.$db->info()->schema;

        // Every query check asks; they should all get the same answer, with the same numbers.
        if (isset(self::$unavailable[$key])) {
            throw self::$unavailable[$key];
        }

        if (! isset(self::$cache[$key])) {
            Requires::consumer($db, 'statements_digest');

            $rows = $db->select(
                "SELECT DIGEST AS digest, DIGEST_TEXT AS text, COUNT_STAR AS calls, SUM_TIMER_WAIT AS total,
                        AVG_TIMER_WAIT AS avg, SUM_ROWS_EXAMINED AS rows_examined, SUM_ROWS_SENT AS rows_sent,
                        SUM_NO_INDEX_USED AS no_index
                 FROM performance_schema.events_statements_summary_by_digest
                 WHERE SCHEMA_NAME = ? AND DIGEST_TEXT IS NOT NULL
                   AND (DIGEST_TEXT LIKE 'SELECT%' OR DIGEST_TEXT LIKE 'UPDATE%' OR DIGEST_TEXT LIKE 'DELETE%' OR DIGEST_TEXT LIKE 'INSERT%' OR DIGEST_TEXT LIKE 'WITH%')
                   AND DIGEST_TEXT NOT LIKE '%performance_schema%' AND DIGEST_TEXT NOT LIKE '%information_schema%'
                   AND DIGEST_TEXT NOT LIKE '%`mysql`.%' AND DIGEST_TEXT NOT LIKE '%mysql.innodb_%'
                   AND DIGEST_TEXT NOT LIKE 'SELECT @@%'
                 ORDER BY SUM_TIMER_WAIT DESC
                 LIMIT 500",
                [$db->info()->schema],
            );

            self::$executes[$key] = (int) ($db->select(
                "SELECT COUNT_STAR AS n FROM performance_schema.events_statements_summary_global_by_event_name WHERE EVENT_NAME = 'statement/com/Execute'",
            )[0]['n'] ?? 0);

            // Native (binary protocol) prepared statements, Laravel's default, are executed as
            // statement/com/Execute and get no SQL text and no digest on MySQL or MariaDB. When they
            // outnumber what is visible, a clean result would be a false all-clear, so skip instead.
            $visible = array_sum(array_map(static fn (array $r): int => (int) $r['calls'], $rows));

            if (self::$executes[$key] > 0 && self::$executes[$key] > $visible) {
                throw self::$unavailable[$key] = new CheckUnavailable(
                    number_format(self::$executes[$key]).' statements on this server were sent as native prepared statements, which MySQL and MariaDB do not record per query, '
                    .'against '.number_format($visible).' recorded for this schema. Laravel sends queries this way by default, so most of your app is not in the statistics.',
                    "Switch the connection to emulated prepares: one round trip per query instead of three, and PHP 8.1+ still returns native int/float types.\n"
                    .self::EMULATE_PREPARES_HINT,
                );
            }

            self::$cache[$key] = array_map(static fn (array $r): array => [
                'digest' => (string) $r['digest'],
                'text' => (string) $r['text'],
                'calls' => (int) $r['calls'],
                'total' => (float) $r['total'],
                'avg' => (float) $r['avg'],
                'rows_examined' => (int) $r['rows_examined'],
                'rows_sent' => (int) $r['rows_sent'],
                'no_index' => (int) $r['no_index'],
            ], $rows);
        }

        /** @var list<array{digest: string, text: string, calls: int, total: float, avg: float, rows_examined: int, rows_sent: int, no_index: int}> */
        return self::$cache[$key];
    }

    /**
     * How many statements on the server ran as native prepared statements, invisible to the digests.
     */
    public static function invisibleExecutes(Database $db): int
    {
        self::forSchema($db);

        return self::$executes[spl_object_id($db).':'.$db->info()->schema] ?? 0;
    }

    public static function flush(): void
    {
        self::$cache = [];
        self::$executes = [];
        self::$unavailable = [];
    }

    /**
     * A digest is a template: values are "?". Offer EXPLAIN only for reads, and say what to fill in.
     */
    public static function explain(string $text): ?string
    {
        if (! preg_match('~^\s*(SELECT|WITH)\b~i', $text)) {
            return null;
        }

        return "-- replace each ? with a real value from your logs, then run:\nEXPLAIN ".$text.';';
    }

    public static function short(string $text, int $length = 120): string
    {
        $text = preg_replace('~\s+~', ' ', $text) ?? $text;

        return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1).'…' : $text;
    }
}
