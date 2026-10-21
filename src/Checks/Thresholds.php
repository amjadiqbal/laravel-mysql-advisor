<?php

declare(strict_types=1);

namespace AmjadIqbal\MysqlAdvisor\Checks;

/**
 * The numbers each check compares against, from config('mysql-advisor.thresholds').
 */
final readonly class Thresholds
{
    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $ignoreTables
     */
    public function __construct(private array $values = [], public array $ignoreTables = []) {}

    public function int(string $key, int $default): int
    {
        $value = $this->values[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    public function float(string $key, float $default): float
    {
        $value = $this->values[$key] ?? null;

        return is_numeric($value) ? (float) $value : $default;
    }

    public function ignores(string $table): bool
    {
        foreach ($this->ignoreTables as $pattern) {
            if (fnmatch($pattern, $table)) {
                return true;
            }
        }

        return false;
    }
}
