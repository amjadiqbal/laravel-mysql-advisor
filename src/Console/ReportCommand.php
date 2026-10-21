<?php

declare(strict_types=1);

namespace AmjadIqbal\MysqlAdvisor\Console;

use AmjadIqbal\MysqlAdvisor\CheckResult;
use AmjadIqbal\MysqlAdvisor\Finding;
use AmjadIqbal\MysqlAdvisor\MysqlAdvisor;
use AmjadIqbal\MysqlAdvisor\Report;
use AmjadIqbal\MysqlAdvisor\Severity;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'mysql-advisor:report')]
final class ReportCommand extends Command
{
    /** Exit code when findings reach --fail-on. */
    public const FINDINGS = 1;

    /** Exit code when the database could not be inspected at all. */
    public const UNAVAILABLE = 2;

    protected $signature = 'mysql-advisor:report
        {--connection= : The database connection to inspect (default: config, then the default connection)}
        {--only=* : Limit to categories (indexes, queries, tables, server) or check ids}
        {--fail-on=critical : Exit 1 when a finding is at least this severe: critical, warning, notice or never}
        {--format=text : text or json}';

    protected $description = 'Check a MySQL or MariaDB database for index, query, table and server problems (read-only)';

    public function handle(MysqlAdvisor $advisor): int
    {
        $failOn = strtolower((string) $this->option('fail-on'));
        $threshold = $failOn === 'never' ? null : Severity::tryFrom($failOn);

        if ($threshold === null && $failOn !== 'never') {
            $this->error("--fail-on must be critical, warning, notice or never, not '{$failOn}'.");

            return self::INVALID;
        }

        $only = [];

        foreach ((array) $this->option('only') as $value) {
            array_push($only, ...array_filter(array_map('trim', explode(',', (string) $value))));
        }

        try {
            $connection = $this->option('connection');
            $report = $advisor->report(is_string($connection) ? $connection : null, $only);
        } catch (Throwable $e) {
            $this->error('Could not inspect the database: '.$e->getMessage());

            return self::UNAVAILABLE;
        }

        if ($this->option('format') === 'json') {
            $this->line((string) json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->render($report);
        }

        return $report->failsAt($threshold) ? self::FINDINGS : self::SUCCESS;
    }

    private function render(Report $report): void
    {
        $s = $report->server;

        $this->newLine();
        $this->line("  <options=bold>MySQL Advisor</> · {$s->flavour()} {$s->version} · schema <options=bold>{$s->schema}</> · connection {$s->connection} · up {$s->uptimeForHumans()}");

        if (! $s->performanceSchema) {
            $this->line('  <fg=yellow>performance_schema is off: index usage and query checks are skipped.</>');
        }

        $findings = $report->findings();

        foreach ($findings as $i => $finding) {
            $this->finding($i + 1, $finding);
        }

        $skipped = $report->skipped();

        if ($skipped !== []) {
            $this->newLine();
            $this->line('  <options=bold>Skipped</>');

            foreach ($skipped as $result) {
                $this->skipped($result);
            }
        }

        $this->newLine();
        $this->line(sprintf(
            '  <fg=red>%d critical</> · <fg=yellow>%d warning</> · <fg=cyan>%d notice</> · %d checks passed · %d skipped',
            $report->count(Severity::Critical),
            $report->count(Severity::Warning),
            $report->count(Severity::Notice),
            count($report->passed()),
            count($skipped),
        ));
        $this->line('  <fg=gray>Read-only: nothing above was run. Review each fix before applying it.</>');
        $this->newLine();
    }

    private function finding(int $n, Finding $f): void
    {
        $color = match ($f->severity) {
            Severity::Critical => 'red',
            Severity::Warning => 'yellow',
            Severity::Notice => 'cyan',
        };

        $this->newLine();
        $this->line("  <fg={$color};options=bold>".strtoupper($f->severity->value)."</> <fg=gray>{$f->check}</>  <options=bold>{$n}. {$f->title}</>");
        $this->line('     '.wordwrap($f->detail, 110, "\n     "));

        if ($f->fix !== null) {
            $this->line('     <fg=gray>Fix (SQL):</>');
            $this->line('       <fg=green>'.str_replace("\n", "\n       ", $f->fix).'</>');
        }

        if ($f->laravel !== null) {
            $this->line('     <fg=gray>Fix (Laravel):</>');
            $this->line('       <fg=green>'.str_replace("\n", "\n       ", $f->laravel).'</>');
        }
    }

    private function skipped(CheckResult $r): void
    {
        $this->line("   - {$r->title}: <fg=gray>{$r->skippedBecause}</>");

        if ($r->howToEnable !== null) {
            $this->line('     <fg=gray>To enable:</> '.str_replace("\n", "\n                ", $r->howToEnable));
        }
    }
}
