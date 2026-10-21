<?php

declare(strict_types=1);

namespace AmjadIqbal\MysqlAdvisor\Support;

final class Sql
{
    public static function id(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    /**
     * @param  list<string>  $columns
     */
    public static function columns(array $columns): string
    {
        return implode(', ', array_map(self::id(...), $columns));
    }

    public static function bytes(int|float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return ($i === 0 ? (string) (int) $bytes : number_format($bytes, 1)).' '.$units[$i];
    }

    /** Picoseconds (performance_schema timers) to a readable duration. */
    public static function duration(int|float $picoseconds): string
    {
        $ms = $picoseconds / 1e9;

        return match (true) {
            $ms >= 60000 => number_format($ms / 60000, 1).' min',
            $ms >= 1000 => number_format($ms / 1000, 2).' s',
            $ms >= 1 => number_format($ms, 1).' ms',
            default => number_format($ms * 1000, 0).' µs',
        };
    }

    public static function percent(float $ratio, int $decimals = 1): string
    {
        return number_format($ratio * 100, $decimals).'%';
    }

    /** A Laravel migration snippet wrapping the given Blueprint lines. */
    public static function migration(string $table, string ...$lines): string
    {
        $body = implode("\n", array_map(static fn (string $l): string => '    '.$l, $lines));

        return "Schema::table('".addslashes($table)."', function (Blueprint \$table) {\n{$body}\n});";
    }

    /**
     * @param  list<string>  $columns
     */
    public static function phpList(array $columns): string
    {
        return count($columns) === 1
            ? "'".addslashes($columns[0])."'"
            : '['.implode(', ', array_map(static fn (string $c): string => "'".addslashes($c)."'", $columns)).']';
    }
}
