<?php

namespace Caixingyue\LaravelStarLog\Query;

use Illuminate\Support\Str;

final class SqlStatementInspector
{
    private const IDENTIFIER_SEGMENT = '(?:`[^`.]+`|"[^".]+"|\[[^.\]]+\]|[A-Za-z_][A-Za-z0-9_$]*)';

    private const IDENTIFIER = self::IDENTIFIER_SEGMENT . '(?:\s*\.\s*' . self::IDENTIFIER_SEGMENT . ')*';

    private const INSERT_PREFIX = '(?:insert\s+(?:(?:low_priority|high_priority)\s+)?(?:(?:or\s+(?:rollback|abort|fail|ignore|replace)|ignore)\s+)?|replace\s+(?:low_priority\s+)?)into';

    private const UPDATE_PREFIX = 'update(?:\s+low_priority)?(?:\s+(?:or\s+(?:rollback|abort|fail|ignore|replace)|ignore|only))?';

    private const DELETE_PREFIX = 'delete(?:\s+low_priority)?(?:\s+quick)?(?:\s+ignore)?';

    /**
     * Inspect the SQL text without interpolating its bindings.
     */
    public function inspect(string $sql, string $driver = 'mysql'): SqlStatement
    {
        $normalizedSql = $this->normalizeSql($sql);
        $mappingSql = $this->withoutComments($sql, $driver);
        $table = $this->extractPrimaryTable($mappingSql ?? $sql, $driver);

        return new SqlStatement(
            $sql,
            $normalizedSql,
            $table,
            $mappingSql === null ? [] : $this->extractBindingFields($mappingSql, $table)
        );
    }

    /**
     * Normalize SQL fragments before matching configured contains rules.
     */
    public function normalizeSql(string $sql): string
    {
        return Str::of($sql)->replace(['`', '"', '[', ']'], '')->squish()->lower()->toString();
    }

    /**
     * Extract the table directly targeted by a simple SQL statement.
     */
    private function extractPrimaryTable(string $sql, string $driver): ?string
    {
        $sql = ltrim($sql);

        if (preg_match('/^with\b/i', $sql) === 1) {
            return null;
        }

        $writePattern = sprintf(/** @lang text */ <<<'REGEX'
        ~
            ^
            (?:
                %s
                | %s
                | %s \s+ from (?: \s+ only)?
            )
            \s+ (?<table>%s)
        ~ix
        REGEX, self::INSERT_PREFIX, self::UPDATE_PREFIX, self::DELETE_PREFIX, self::IDENTIFIER);

        if (preg_match($writePattern, $sql, $matches) === 1) {
            return SqlStatement::normalizeTable($matches['table']);
        }

        $deleteTarget = $this->extractAliasedDeleteTable($sql);

        if ($deleteTarget !== null) {
            return $deleteTarget;
        }

        if (preg_match('/^select\b/i', $sql) !== 1) {
            return null;
        }

        $selectPattern = sprintf(/** @lang text */ <<<'REGEX'
        ~
            (?:
                %s
                | /\*.*?\*/
                | %s
                | \#[^\r\n]*
            ) (*SKIP)(*F)
            | (?<parenthesis> [()] )
            | \sfrom\s+(?:only\s+)?(?<table>\(|%s)
        ~isx
        REGEX, $this->quotedTokenPattern($driver), $this->lineCommentPattern($driver), self::IDENTIFIER);

        preg_match_all($selectPattern, $sql, $matches, PREG_SET_ORDER);
        $depth = 0;

        foreach ($matches as $match) {
            if (($match['parenthesis'] ?? '') !== '') {
                $depth += $match['parenthesis'] === '(' ? 1 : -1;

                continue;
            }

            if ($depth === 0) {
                return $match['table'] === '(' ? null : SqlStatement::normalizeTable($match['table']);
            }

            if ($match['table'] === '(') {
                $depth++;
            }
        }

        return null;
    }

    /**
     * Resolve a single DELETE target only when it names the first table or its alias.
     */
    private function extractAliasedDeleteTable(string $sql): ?string
    {
        $pattern = sprintf(
            '~^%s\s+(?<target>%s)(?:\.\*)?\s+from\s+(?<table>%s)(?:\s+(?:as\s+)?(?<alias>(?!(?:where|join|inner|outer|left|right|cross|natural|straight_join|using|on|order|limit|partition|use|force|ignore|index|group|having|union|returning)\b)%s))?~i',
            self::DELETE_PREFIX,
            self::IDENTIFIER,
            self::IDENTIFIER,
            self::IDENTIFIER_SEGMENT
        );

        if (preg_match($pattern, $sql, $matches) !== 1) {
            return null;
        }

        $table = SqlStatement::normalizeTable($matches['table']);
        $target = SqlStatement::normalizeTable($matches['target']);

        return in_array($target, [$table, SqlStatement::normalizeColumn($table), SqlStatement::normalizeIdentifier($matches['alias'] ?? '')], true)
            ? $table
            : null;
    }

    /**
     * SQLite string literals do not treat backslashes as escapes.
     */
    private function stringLiteralPattern(string $driver): string
    {
        return $driver === 'sqlite'
            ? "'(?:''|[^'])*'"
            : "'(?:''|\\\\.|[^'\\\\])*'";
    }

    /**
     * Keep comments and FROM tokens inside quoted text out of SQL inspection.
     */
    private function quotedTokenPattern(string $driver): string
    {
        $doubleQuoted = in_array($driver, ['mysql', 'mariadb'], true)
            ? '"(?:""|\\\\.|[^"\\\\])*"'
            : '"(?:""|[^"])*"';

        return $this->stringLiteralPattern($driver) . '|' . $doubleQuoted . '|`(?:``|[^`])*`|\[(?:\]\]|[^\]])*\]';
    }

    /**
     * MySQL requires whitespace after --; SQLite and standard SQL do not.
     */
    private function lineCommentPattern(string $driver): string
    {
        return in_array($driver, ['mysql', 'mariadb'], true)
            ? '--(?=\s|$)[^\r\n]*'
            : '--[^\r\n]*';
    }

    /**
     * Map bindings when the SQL shape can be interpreted safely.
     *
     * @return array<int|string, string|null>
     */
    private function extractBindingFields(string $sql, ?string $table): array
    {
        if ($table === null) {
            return [];
        }

        $insert = $this->extractInsertBindingFields($sql);

        if ($insert !== null) {
            return $insert;
        }

        $assignments = $this->extractUpdateBindingFields($sql);
        $where = $this->extractWhereBindingFields($sql, $table);

        if ($assignments !== null) {
            // Mixed named and positional parameters are not verified.
            return $where !== null && array_filter(array_keys($where), 'is_string') === []
                ? array_merge($assignments, $where)
                : $assignments;
        }

        return $where ?? [];
    }

    /**
     * Remove ordinary comments while preserving quoted text.
     * Executable or hash comments leave bindings unverified.
     */
    private function withoutComments(string $sql, string $driver): ?string
    {
        $unsafe = false;
        $pattern = sprintf(<<<'REGEX'
        ~(?<quoted>%s)|(?<comment>/\*.*?\*/|%s|\#[^\r\n]*)~s
        REGEX, $this->quotedTokenPattern($driver), $this->lineCommentPattern($driver));
        $stripped = preg_replace_callback($pattern, static function (array $match) use (&$unsafe): string {
            if ($match['quoted'] !== '') {
                return $match[0];
            }

            if (str_starts_with($match[0], '#') || preg_match('~^/\*(?:!|M!)~i', $match[0]) === 1) {
                $unsafe = true;
            }

            return ' ';
        }, $sql);

        return $unsafe || $stripped === null ? null : ltrim($stripped);
    }

    /**
     * Map supported WHERE bindings to columns.
     *
     * @return array<int|string, string|null>|null
     */
    private function extractWhereBindingFields(string $sql, string $table): ?array
    {
        $projection = '(?:\*|' . self::IDENTIFIER . '(?:\s+as\s+' . self::IDENTIFIER_SEGMENT . ')?|count\s*\(\s*\*\s*\)(?:\s+as\s+' . self::IDENTIFIER_SEGMENT . ')?)';
        $prefix = '(?:select\s+' . $projection . '(?:\s*,\s*' . $projection . ')*\s+from|' . self::DELETE_PREFIX . '\s+from)\s+(?:only\s+)?';
        $suffix = '(?:\s+order\s+by\s+' . self::IDENTIFIER . '(?:\s+(?:asc|desc))?(?:\s*,\s*' . self::IDENTIFIER . '(?:\s+(?:asc|desc))?)*)?(?:\s+limit\s+[0-9]+(?:\s+offset\s+[0-9]+)?)?\s*;?\s*';
        $pattern = '~^\s*' . $prefix . '(?<table>' . self::IDENTIFIER . ')(?:\s+(?:as\s+)?(?<alias>' . self::IDENTIFIER_SEGMENT . '))?\s+where\s+(?<conditions>.*?)' . $suffix . '$~is';

        if (preg_match($pattern, $sql, $matches) !== 1) {
            // UPDATE assignments are verified separately and cannot contain literals.
            $pattern = '~^\s*' . self::UPDATE_PREFIX . '\s+(?<table>' . self::IDENTIFIER . ')\s+set\s+(?:' . self::IDENTIFIER . '\s*=\s*\?\s*,\s*)*' . self::IDENTIFIER . '\s*=\s*\?\s+where\s+(?<conditions>.*?)' . $suffix . '$~is';

            if (preg_match($pattern, $sql, $matches) !== 1) {
                return null;
            }
        }

        if (SqlStatement::normalizeTable($matches['table']) !== $table) {
            return null;
        }

        $qualifiers = [$table, SqlStatement::normalizeColumn($table)];

        if (($matches['alias'] ?? '') !== '') {
            $qualifiers[] = SqlStatement::normalizeIdentifier($matches['alias']);
        }

        return (new SqlWhereBindingMapper)->map($matches['conditions'], $qualifiers);
    }

    /**
     * Map a simple INSERT statement's positional bindings to its columns.
     *
     * @return array<int, string|null>|null
     */
    private function extractInsertBindingFields(string $sql): ?array
    {
        $pattern = sprintf(/** @lang text */ <<<'REGEX'
        ~
            ^
            %s \s+ %s
            \s* \( (?<columns>[^()]+) \) \s*
            values \s*
            (?<rows>
                \( [^()]+ \)
                (?: \s* , \s* \( [^()]+ \))*
            )
            (?= \s* (?: on \b | returning \b | ; | \z ))
        ~ix
        REGEX, self::INSERT_PREFIX, self::IDENTIFIER);

        if (preg_match($pattern, $sql, $matches) !== 1) {
            return null;
        }

        $columns = $this->splitColumns($matches['columns']);

        if ($columns === []) {
            return null;
        }

        if (str_contains(substr($sql, strlen($matches[0])), '?')) {
            return null;
        }

        preg_match_all('/\((?<values>[^()]+)\)/', $matches['rows'], $rows);
        $fields = [];

        foreach ($rows['values'] as $values) {
            $values = array_map('trim', explode(',', $values));

            if (count($columns) !== count($values) || array_filter($values, static fn (string $value): bool => $value !== '?') !== []) {
                return null;
            }

            array_push($fields, ...$columns);
        }

        return $fields;
    }

    /**
     * Map a simple UPDATE statement's positional bindings to its assigned columns.
     *
     * @return array<int, string|null>|null
     */
    private function extractUpdateBindingFields(string $sql): ?array
    {
        $pattern = sprintf(/** @lang text */ <<<'REGEX'
        ~
            ^
            %s \s+ %s
            \s+ set \s+ (?<assignments> .+? )
            (?: \s+ where \b | $)
        ~isx
        REGEX, self::UPDATE_PREFIX, self::IDENTIFIER);

        if (preg_match($pattern, $sql, $matches) !== 1) {
            return null;
        }

        $assignmentPattern = sprintf('/^\s*(?<column>%s)\s*=\s*\?\s*$/i', self::IDENTIFIER);
        $fields = [];

        foreach (explode(',', $matches['assignments']) as $assignment) {
            if (preg_match($assignmentPattern, $assignment, $column) !== 1) {
                return null;
            }

            $fields[] = SqlStatement::normalizeColumn($column['column']);
        }

        return $fields;
    }

    /**
     * Normalize a comma-separated INSERT column list.
     *
     * @return array<int, string>
     */
    private function splitColumns(string $columns): array
    {
        $fields = [];

        foreach (explode(',', $columns) as $column) {
            if (preg_match('/^\s*' . self::IDENTIFIER . '\s*$/D', $column) !== 1) {
                return [];
            }

            $fields[] = SqlStatement::normalizeColumn($column);
        }

        return $fields;
    }
}
