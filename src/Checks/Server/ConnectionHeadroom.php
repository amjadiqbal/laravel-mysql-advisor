<?php

declare(strict_types=1);

namespace AmjadIqbal\MysqlAdvisor\Checks\Server;

use AmjadIqbal\MysqlAdvisor\Category;
use AmjadIqbal\MysqlAdvisor\Checks\Check;
use AmjadIqbal\MysqlAdvisor\Checks\Thresholds;
use AmjadIqbal\MysqlAdvisor\Database\Database;
use AmjadIqbal\MysqlAdvisor\Finding;
use AmjadIqbal\MysqlAdvisor\Severity;
use AmjadIqbal\MysqlAdvisor\Support\Sql;

/**
 * How close the server has come to refusing connections ("Too many connections").
 */
final class ConnectionHeadroom implements Check
{
    public function id(): string
    {
        return 'connection-headroom';
    }

    public function title(): string
    {
        return 'Connection headroom';
    }

    public function category(): Category
    {
        return Category::Server;
    }

    public function run(Database $db, Thresholds $thresholds): array
    {
        $max = max(1, (int) $db->variable('max_connections'));
        $peak = (int) $db->status('Max_used_connections');
        $now = (int) $db->status('Threads_connected');
        $ratio = $peak / $max;

        if ($ratio < $thresholds->float('connections_warning', 0.8)) {
            return [];
        }

        $suggested = (int) (ceil($peak * 1.5 / 10) * 10);

        return [new Finding(
            check: $this->id(),
            category: $this->category(),
            severity: $ratio >= $thresholds->float('connections_critical', 0.95) ? Severity::Critical : Severity::Warning,
            subject: 'max_connections',
            title: 'Connections peaked at '.$peak.' of '.$max.' allowed ('.Sql::percent($ratio, 0).')',
            detail: 'When every slot is taken, new requests and queue workers fail with "Too many connections". '
                .'Count what connects: PHP-FPM max_children, Octane workers, Horizon/queue processes and cron, on every app server. '
                .'If that total is right, raise the limit; each connection costs memory, so check RAM first. '
                ."{$now} are connected right now.",
            fix: $db->info()->setGlobal('max_connections', $suggested),
            evidence: ['max_connections' => $max, 'max_used_connections' => $peak, 'threads_connected' => $now],
        )];
    }
}
