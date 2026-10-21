<?php

declare(strict_types=1);

namespace AmjadIqbal\MysqlAdvisor\Database;

use LogicException;

/**
 * Refuses any statement that is not a read.
 *
 * Every query the package sends goes through Database::select(), and that
 * passes through here first, so "never writes" is a property of one function
 * you can read rather than a promise spread across every check.
 */
final class ReadOnlyGuard
{
    public static function assertReadOnly(string $sql): void
    {
        $statement = ltrim(preg_replace('~^(\s*(/\*.*?\*/|--[^\n]*\n|#[^\n]*\n))*~s', '', $sql) ?? $sql);

        if (! preg_match('~^(SELECT|SHOW|WITH)\b~i', $statement)) {
            throw new LogicException('laravel-mysql-advisor only runs SELECT and SHOW statements. Refused: '.substr($statement, 0, 60));
        }

        // A single statement only: no "SELECT 1; DROP TABLE x".
        $withoutStrings = preg_replace("~'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\"|`[^`]*`~s", "''", $statement) ?? $statement;

        if (str_contains(rtrim($withoutStrings, "; \n\t"), ';')) {
            throw new LogicException('laravel-mysql-advisor runs one statement at a time.');
        }

        if (preg_match('~\b(INTO\s+(OUTFILE|DUMPFILE)|FOR\s+UPDATE|LOCK\s+IN\s+SHARE\s+MODE)\b~i', $withoutStrings)) {
            throw new LogicException('laravel-mysql-advisor refuses statements that write files or take locks.');
        }
    }
}
