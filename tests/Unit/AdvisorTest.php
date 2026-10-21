<?php

use AmjadIqbal\MysqlAdvisor\Advisor;
use AmjadIqbal\MysqlAdvisor\Category;
use AmjadIqbal\MysqlAdvisor\Checks\Check;
use AmjadIqbal\MysqlAdvisor\Checks\Thresholds;
use AmjadIqbal\MysqlAdvisor\Database\CheckUnavailable;
use AmjadIqbal\MysqlAdvisor\Database\Database;
use AmjadIqbal\MysqlAdvisor\Finding;
use AmjadIqbal\MysqlAdvisor\ServerInfo;
use AmjadIqbal\MysqlAdvisor\Severity;

function fakeDb(): Database
{
    return new class implements Database
    {
        public function select(string $sql, array $bindings = []): array
        {
            return [];
        }

        public function status(string $name): ?string
        {
            return null;
        }

        public function variable(string $name): ?string
        {
            return null;
        }

        public function info(): ServerInfo
        {
            return new ServerInfo('mysql', 'app', '8.4.0', false, 3600, true);
        }
    };
}

function check(string $id, Category $category, Closure $run): Check
{
    return new class($id, $category, $run) implements Check
    {
        public function __construct(private string $checkId, private Category $cat, private Closure $body) {}

        public function id(): string
        {
            return $this->checkId;
        }

        public function title(): string
        {
            return ucfirst($this->checkId);
        }

        public function category(): Category
        {
            return $this->cat;
        }

        public function run(Database $db, Thresholds $thresholds): array
        {
            return ($this->body)();
        }
    };
}

function finding(string $check, Severity $severity): Finding
{
    return new Finding($check, Category::Tables, $severity, 'subject', 'title', 'detail');
}

it('sorts findings worst first and keeps running after a check fails', function () {
    $advisor = new Advisor([
        check('a', Category::Tables, fn () => [finding('a', Severity::Notice)]),
        check('b', Category::Server, fn () => throw new RuntimeException('boom')),
        check('c', Category::Queries, fn () => throw new CheckUnavailable('no privilege', 'GRANT ...')),
        check('d', Category::Indexes, fn () => [finding('d', Severity::Critical), finding('d', Severity::Warning)]),
        check('e', Category::Indexes, fn () => []),
    ], new Thresholds);

    $report = $advisor->run(fakeDb());

    expect(array_map(fn (Finding $f) => $f->severity, $report->findings()))->toBe([Severity::Critical, Severity::Warning, Severity::Notice])
        ->and(array_map(fn ($r) => $r->check, $report->skipped()))->toBe(['b', 'c'])
        ->and($report->skipped()[0]->skippedBecause)->toBe('The check failed: boom')
        ->and($report->skipped()[1]->howToEnable)->toBe('GRANT ...')
        ->and(array_map(fn ($r) => $r->check, $report->passed()))->toBe(['e']);
});

it('decides the CI exit by the worst finding', function () {
    $report = (new Advisor([check('a', Category::Tables, fn () => [finding('a', Severity::Warning)])], new Thresholds))->run(fakeDb());

    expect($report->failsAt(Severity::Critical))->toBeFalse()
        ->and($report->failsAt(Severity::Warning))->toBeTrue()
        ->and($report->failsAt(Severity::Notice))->toBeTrue()
        ->and($report->failsAt(null))->toBeFalse();
});

it('limits a run to categories or check ids', function () {
    $advisor = new Advisor([
        check('a', Category::Tables, fn () => []),
        check('b', Category::Server, fn () => []),
        check('c', Category::Indexes, fn () => []),
    ], new Thresholds);

    expect(array_map(fn ($r) => $r->check, $advisor->run(fakeDb(), ['server', 'c'])->results))->toBe(['b', 'c']);
});

it('serialises to a stable JSON shape', function () {
    $report = (new Advisor([check('a', Category::Tables, fn () => [finding('a', Severity::Critical)])], new Thresholds))->run(fakeDb());

    expect($report->toArray())->toHaveKeys(['generated_at', 'server', 'summary', 'checks'])
        ->and($report->toArray()['summary'])->toMatchArray(['critical' => 1, 'warning' => 0, 'checks_run' => 1, 'checks_skipped' => 0])
        ->and($report->toArray()['checks'][0]['findings'][0])->toHaveKeys(['check', 'severity', 'fix', 'laravel', 'evidence']);
});
