<?php

namespace Caixingyue\LaravelStarLog\Query;

use Illuminate\Support\Str;

final readonly class SqlStatement
{
    /**
     * Store the inspected SQL statement.
     *
     * @param  array<int|string, string|null>  $bindingColumns
     */
    public function __construct(
        public string $sql,
        public string $normalizedSql,
        public ?string $table,
        public array $bindingColumns = []
    ) {}

    /**
     * Determine whether the statement targets a configured primary table.
     */
    public function targetsPrimaryTable(string $table): bool
    {
        if (is_null($this->table)) {
            return false;
        }

        $table = self::normalizeTable($table);

        return $table !== '' && str_ends_with(".{$this->table}", ".{$table}");
    }

    /**
     * Normalize a SQL identifier without changing its qualification.
     */
    public static function normalizeIdentifier(string $identifier): string
    {
        $segments = array_filter(array_map(
            static fn (string $segment): string => Str::lower(trim($segment, " \t\n\r\0\x0B`\"[]")),
            explode('.', trim($identifier))
        ), static fn (string $segment): bool => $segment !== '');

        return implode('.', $segments);
    }

    /**
     * Normalize a SQL table identifier for table matching.
     */
    public static function normalizeTable(string $table): string
    {
        return self::normalizeIdentifier($table);
    }

    /**
     * Normalize a SQL column identifier for binding column settings lookup.
     */
    public static function normalizeColumn(string $column): string
    {
        return Str::afterLast(self::normalizeIdentifier($column), '.');
    }
}
