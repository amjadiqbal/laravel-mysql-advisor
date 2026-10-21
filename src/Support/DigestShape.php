<?php

declare(strict_types=1);

namespace AmjadIqbal\MysqlAdvisor\Support;

/**
 * Reads the simple shape out of a normalized performance_schema digest:
 * which single table it reads and which columns it compares with "= ?".
 *
 * Deliberately modest. It only recognises single-table statements, which is
 * what Eloquent's lazy loads and most full scans look like; anything with a
 * JOIN or a subquery is left alone rather than guessed at.
 */
final readonly class DigestShape
{
    /**
     * @param  list<string>  $equalityColumns
     */
    private function __construct(
        public string $verb,
        public ?string $table,
        public array $equalityColumns,
        public bool $joins,
    ) {}

    public static function parse(string $digest): self
    {
        $sql = preg_replace('~\s+~', ' ', trim($digest)) ?? $digest;
        $verb = strtoupper((string) strtok($sql, ' '));
        $joins = (bool) preg_match('~\bJOIN\b|\(\s*SELECT\b|\bUNION\b~i', $sql);

        $table = null;

        if (preg_match('~\b(?:FROM|UPDATE|INTO)\s+((?:`[^`]+`\s*\.\s*)?`[^`]+`|[\w$]+(?:\.[\w$]+)?)~i', $sql, $m)) {
            $parts = explode('.', str_replace(['`', ' '], '', $m[1]));
            $table = end($parts) ?: null;
        }

        $columns = [];

        if (! $joins && preg_match('~\bWHERE\b(.*?)(?:\bGROUP\s+BY\b|\bORDER\s+BY\b|\bLIMIT\b|$)~i', $sql, $w)) {
            preg_match_all('~((?:`[^`]+`\s*\.\s*)?`[^`]+`|\b[a-z_][\w$]*\b)\s*(?:=\s*\?|IN\s*\()~i', $w[1], $cols);

            foreach ($cols[1] as $col) {
                $parts = explode('.', str_replace(['`', ' '], '', $col));
                $name = (string) end($parts);

                if (! in_array($name, $columns, true) && ! in_array(strtoupper($name), ['AND', 'OR', 'NOT'], true)) {
                    $columns[] = $name;
                }
            }
        }

        return new self($verb, $table, $columns, $joins);
    }

    public function isSingleTableLookup(): bool
    {
        return $this->verb === 'SELECT' && ! $this->joins && $this->table !== null && count($this->equalityColumns) >= 1;
    }
}
