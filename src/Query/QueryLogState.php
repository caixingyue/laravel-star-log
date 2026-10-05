<?php

namespace Caixingyue\LaravelStarLog\Query;

use Caixingyue\LaravelStarLog\Support\LogOptions;
use Closure;
use Illuminate\Database\Events\QueryExecuted;

final class QueryLogState
{
    private int $loggingPauseDepth = 0;

    /**
     * @var array<string, int>
     */
    private array $ignoredTables = [];

    private int $loggedQueryCount = 0;

    /**
     * @var array<int, int>
     */
    private array $entryCountStack = [];

    private array $options = [];

    private array $sensitiveColumnRules = [];

    /**
     * @var array<int, Closure>
     */
    private array $filters = [];

    /**
     * Resolve temporary options without changing configured defaults or entry counts.
     */
    public function resolve(array $config): array
    {
        return LogOptions::merge($config, $this->options);
    }

    /**
     * Run the callback with temporary SQL log options, then restore the outer scope.
     */
    public function withOptions(array $options, Closure $callback): mixed
    {
        $previous = $this->options;

        $this->options = LogOptions::merge($this->options, array_intersect_key($options, array_flip([
            'enable', 'min_time', 'sample_rate', 'max_entries', 'max_sql_length', 'bindings', 'ignore',
        ])));

        try {
            return $callback();
        } finally {
            $this->options = $previous;
        }
    }

    /**
     * Temporarily mask or expose bindings for selected table columns, restoring rules on exit.
     * Use '*' as the table name to apply a column rule to all tables.
     */
    public function withSensitiveColumns(array $columns, bool $sensitive, Closure $callback): mixed
    {
        $normalized = [];

        foreach ($columns as $table => $fields) {
            if (! is_string($table) || ! is_array($fields)) {
                continue;
            }

            foreach ($fields as $field) {
                if (is_string($field) && $field !== '') {
                    $normalized[$table === '*' ? '*' : SqlStatement::normalizeTable($table)][SqlStatement::normalizeColumn($field)] = $sensitive;
                }
            }
        }

        $this->sensitiveColumnRules[] = $normalized;

        try {
            return $callback();
        } finally {
            array_pop($this->sensitiveColumnRules);
        }
    }

    /**
     * Get the active column masking rules.
     *
     * @return array<int, array<string, array<string, bool>>>
     */
    public function sensitiveColumnRules(): array
    {
        return $this->sensitiveColumnRules;
    }

    /**
     * Add a query predicate for the callback's scope; all active predicates must return true to log.
     */
    public function withFilter(Closure $filter, Closure $callback): mixed
    {
        $this->filters[] = $filter;

        try {
            return $callback();
        } finally {
            array_pop($this->filters);
        }
    }

    /**
     * Run predicates with recursive SQL logging paused.
     * All active predicates must explicitly return true.
     */
    public function passesFilters(QueryExecuted $event): bool
    {
        return $this->withoutLogging(function () use ($event): bool {
            foreach ($this->filters as $filter) {
                if ($filter($event) !== true) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * Start a separate entry budget for a nested command or queue job.
     */
    public function beginExecution(): void
    {
        $this->entryCountStack[] = $this->loggedQueryCount;
        $this->resetEntryCount();
    }

    /**
     * Restore the caller's entry budget after a command or queue job finishes.
     */
    public function finishExecution(): void
    {
        $this->loggedQueryCount = array_pop($this->entryCountStack) ?? 0;
    }

    /**
     * Reset the entry budget for a new request or execution unit.
     */
    public function resetEntryCount(): void
    {
        $this->loggedQueryCount = 0;
    }

    /**
     * Temporarily stop SQL logging while the callback is running.
     */
    public function withoutLogging(Closure $callback): mixed
    {
        $this->loggingPauseDepth++;

        try {
            return $callback();
        } finally {
            $this->loggingPauseDepth--;
        }
    }

    /**
     * Temporarily ignore queries whose primary table matches one of the given tables.
     *
     * @param  array<int, string>  $tables
     */
    public function withoutTables(array $tables, Closure $callback): mixed
    {
        $tables = array_values(array_filter($tables, 'is_string'));

        foreach ($tables as $table) {
            $this->ignoreTable($table);
        }

        try {
            return $callback();
        } finally {
            foreach ($tables as $table) {
                $this->resumeTable($table);
            }
        }
    }

    /**
     * Determine whether SQL logging is temporarily paused in the current execution.
     */
    public function isLoggingPaused(): bool
    {
        return $this->loggingPauseDepth > 0;
    }

    /**
     * Ignore queries for a primary table until the matching resume call.
     */
    public function ignoreTable(string $table): void
    {
        $table = SqlStatement::normalizeTable($table);

        if ($table === '') {
            return;
        }

        $this->ignoredTables[$table] = ($this->ignoredTables[$table] ?? 0) + 1;
    }

    /**
     * Restore one level of query logging for a primary table.
     */
    public function resumeTable(string $table): void
    {
        $table = SqlStatement::normalizeTable($table);
        $count = $this->ignoredTables[$table] ?? 0;

        if ($table === '' || $count === 0) {
            return;
        }

        if ($count === 1) {
            unset($this->ignoredTables[$table]);

            return;
        }

        $this->ignoredTables[$table] = $count - 1;
    }

    /**
     * Determine whether queries for a primary table should be ignored.
     */
    public function isTableIgnored(?string $table): bool
    {
        if ($table === null) {
            return false;
        }

        $table = SqlStatement::normalizeTable($table);

        foreach ($this->ignoredTables as $ignoredTable => $count) {
            if ($count > 0 && str_ends_with(".{$table}", ".{$ignoredTable}")) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether the configured entry limit has been reached.
     */
    public function hasReachedEntryLimit(?int $limit): bool
    {
        return $limit !== null && $this->loggedQueryCount >= $limit;
    }

    /**
     * Increase the number of SQL query logs written during this execution.
     */
    public function incrementEntryCount(): void
    {
        $this->loggedQueryCount++;
    }
}
