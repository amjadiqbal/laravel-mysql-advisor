<?php

declare(strict_types=1);

namespace AmjadIqbal\MysqlAdvisor\Checks;

use AmjadIqbal\MysqlAdvisor\Category;
use AmjadIqbal\MysqlAdvisor\Database\CheckUnavailable;
use AmjadIqbal\MysqlAdvisor\Database\Database;
use AmjadIqbal\MysqlAdvisor\Finding;

/**
 * One question asked of the database. Register your own in config('mysql-advisor.checks').
 */
interface Check
{
    /** Stable kebab-case id, used in config, JSON output and --only. */
    public function id(): string;

    public function title(): string;

    public function category(): Category;

    /**
     * @return list<Finding>
     *
     * @throws CheckUnavailable when the server cannot answer; the check is reported as skipped.
     */
    public function run(Database $db, Thresholds $thresholds): array;
}
