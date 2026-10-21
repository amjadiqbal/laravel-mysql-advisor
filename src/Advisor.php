<?php

declare(strict_types=1);

namespace AmjadIqbal\MysqlAdvisor;

use AmjadIqbal\MysqlAdvisor\Checks\Check;
use AmjadIqbal\MysqlAdvisor\Checks\Thresholds;
use AmjadIqbal\MysqlAdvisor\Database\CheckUnavailable;
use AmjadIqbal\MysqlAdvisor\Database\Database;
use Throwable;

/**
 * Runs every check against one database and collects the results.
 *
 * A check that throws is reported as skipped with its error; it never takes the
 * report (or the dashboard) down with it.
 */
final readonly class Advisor
{
    /**
     * @param  list<Check>  $checks
     */
    public function __construct(private array $checks, private Thresholds $thresholds) {}

    /**
     * @param  list<string>  $only  Category values or check ids; empty means everything.
     */
    public function run(Database $db, array $only = []): Report
    {
        $info = $db->info();
        $results = [];

        foreach ($this->checks as $check) {
            if ($only !== [] && ! in_array($check->id(), $only, true) && ! in_array($check->category()->value, $only, true)) {
                continue;
            }

            try {
                $results[] = CheckResult::ran($check->id(), $check->title(), $check->category(), $check->run($db, $this->thresholds));
            } catch (CheckUnavailable $e) {
                $results[] = CheckResult::skipped($check->id(), $check->title(), $check->category(), $e->getMessage(), $e->howToEnable);
            } catch (Throwable $e) {
                $results[] = CheckResult::skipped($check->id(), $check->title(), $check->category(), 'The check failed: '.$e->getMessage());
            }
        }

        return new Report($info, $results);
    }

    /**
     * @return list<Check>
     */
    public function checks(): array
    {
        return $this->checks;
    }
}
