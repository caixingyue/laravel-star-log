<?php

namespace Caixingyue\LaravelStarLog\Query;

final class QueryBindingColumnRegistry
{
    /**
     * @var array<string, array<string, array{sensitive?: bool, max_length?: int|null}>>
     */
    private array $tableColumns = [];

    /**
     * Register and merge binding column log settings declared by an Eloquent model.
     *
     * @param  array<string, array{sensitive?: bool, max_length?: int|null}>  $columns
     */
    public function register(string $table, array $columns): void
    {
        $table = SqlStatement::normalizeTable($table);

        if ($table === '') {
            return;
        }

        $this->tableColumns[$table] = array_replace_recursive($this->tableColumns[$table] ?? [], $columns);
    }

    /**
     * Get the binding column log settings registered for a table.
     *
     * @return array<string, array{sensitive?: bool, max_length?: int|null}>
     */
    public function forTable(?string $table): array
    {
        return is_null($table) ? [] : ($this->tableColumns[SqlStatement::normalizeTable($table)] ?? []);
    }
}
