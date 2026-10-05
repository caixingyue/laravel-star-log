<?php

namespace Caixingyue\LaravelStarLog\Listeners;

use Caixingyue\LaravelStarLog\Query\QueryBindingColumnRegistry;
use Caixingyue\LaravelStarLog\Query\QueryBindingFormatter;
use Caixingyue\LaravelStarLog\Query\QueryCallerResolver;
use Caixingyue\LaravelStarLog\Query\QueryLogState;
use Caixingyue\LaravelStarLog\Query\SqlStatement;
use Caixingyue\LaravelStarLog\Query\SqlStatementInspector;
use Caixingyue\LaravelStarLog\StarLog;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Log;
use Random\RandomException;
use Throwable;

final readonly class QueryExecutedToLog
{
    /**
     * Create a query log listener.
     */
    public function __construct(
        private Application $app,
        private SqlStatementInspector $statementInspector,
        private QueryCallerResolver $callerResolver,
        private QueryBindingFormatter $bindingFormatter,
        private QueryBindingColumnRegistry $bindingColumnRegistry
    ) {}

    /**
     * Write one query event when it passes the configured logging filters.
     */
    public function handle(QueryExecuted $event): void
    {
        try {
            $state = $this->app->make(QueryLogState::class);
            $config = $this->queryConfig();

            if ($config === null || ! $this->shouldLog($event, $state, $config)) {
                return;
            }

            $statement = $this->statementInspector->inspect($event->sql, $event->connection->getDriverName());

            if ($this->shouldIgnore($statement, $state, $config) || ! $state->passesFilters($event)) {
                return;
            }

            $this->write($event, $statement, $state, $config);
        } catch (Throwable) {
            // SQL logging must never alter the outcome of the query it observes.
        }
    }

    /**
     * Get the SQL logging configuration when it is an array.
     *
     * @throws BindingResolutionException
     */
    private function queryConfig(): ?array
    {
        $config = $this->app->make(StarLog::class)->getConfig('query', []);

        return is_array($config) ? $config : null;
    }

    /**
     * Determine whether the query is eligible to be logged before SQL inspection.
     *
     * @throws RandomException
     */
    private function shouldLog(QueryExecuted $event, QueryLogState $state, array $config): bool
    {
        $entryLimit = $this->nullablePositiveInteger($config['max_entries'] ?? null);

        return ($config['enable'] ?? false) === true
            && ! $state->isLoggingPaused()
            && ! $state->hasReachedEntryLimit($entryLimit)
            && $this->meetsMinimumDuration($event, $config)
            && $this->isSampled($config);
    }

    /**
     * Determine whether the event meets the configured per-query duration threshold.
     */
    private function meetsMinimumDuration(QueryExecuted $event, array $config): bool
    {
        $minimumDuration = $config['min_time'] ?? 0;

        if (! is_numeric($minimumDuration)) {
            return true;
        }

        return (float) $event->time >= max(0, (float) $minimumDuration);
    }

    /**
     * Determine whether this event is selected by the configured sample rate.
     *
     * @throws RandomException
     */
    private function isSampled(array $config): bool
    {
        $rate = $config['sample_rate'] ?? 1.0;

        if (! is_numeric($rate)) {
            return true;
        }

        $rate = (float) $rate;

        if ($rate >= 1) {
            return true;
        }

        return $rate > 0 && random_int(0, PHP_INT_MAX) / PHP_INT_MAX < $rate;
    }

    /**
     * Determine whether the statement is ignored by the current state or configuration.
     */
    private function shouldIgnore(SqlStatement $statement, QueryLogState $state, array $config): bool
    {
        return $state->isTableIgnored($statement->table) || $this->matchesIgnoreRule($statement, $config);
    }

    /**
     * Determine whether the query matches a configured ignore rule.
     */
    private function matchesIgnoreRule(SqlStatement $statement, array $config): bool
    {
        $ignore = $config['ignore'] ?? [];

        if (! is_array($ignore)) {
            return false;
        }

        if ($this->matchesAnyRule($statement, $ignore['global'] ?? [])) {
            return true;
        }

        $classes = $ignore['classes'] ?? null;

        if (! is_array($classes) || $classes === []) {
            return false;
        }

        $caller = $this->callerResolver->resolveClassName();

        return is_string($caller) && $this->matchesAnyRule($statement, $classes[$caller] ?? []);
    }

    /**
     * Determine whether an inspected statement matches one of the configured rules.
     */
    private function matchesAnyRule(SqlStatement $statement, mixed $rules): bool
    {
        if (! is_array($rules)) {
            return false;
        }

        foreach ($rules as $rule) {
            if ($this->matchesRule($statement, $rule)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether one configured ignore rule matches the inspected statement.
     */
    private function matchesRule(SqlStatement $statement, mixed $rule): bool
    {
        if (! is_array($rule)) {
            return false;
        }

        $table = $rule['table'] ?? null;
        $contains = $rule['contains'] ?? null;

        if ($table === null && $contains === null) {
            return false;
        }

        if ($table !== null && (! is_string($table) || ! $statement->targetsPrimaryTable($table))) {
            return false;
        }

        if ($contains !== null && ! $this->matchesContains($statement, $contains)) {
            return false;
        }

        return true;
    }

    /**
     * Match a non-empty string or every fragment in a non-empty array against the SQL.
     */
    private function matchesContains(SqlStatement $statement, mixed $contains): bool
    {
        $fragments = is_string($contains) ? [$contains] : $contains;

        if (! is_array($fragments) || $fragments === []) {
            return false;
        }

        foreach ($fragments as $fragment) {
            if (! is_string($fragment)) {
                return false;
            }

            $normalizedFragment = $this->statementInspector->normalizeSql($fragment);

            if ($normalizedFragment === '' || ! str_contains($statement->normalizedSql, $normalizedFragment)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Write one structured SQL log entry without allowing recursive query logging.
     *
     * @throws BindingResolutionException
     */
    private function write(QueryExecuted $event, SqlStatement $statement, QueryLogState $state, array $config): void
    {
        $state->withoutLogging(function () use ($event, $statement, $config, $state): void {
            $context = [
                'sql' => $this->truncateSql($statement->sql, $config),
            ];

            if ($event->bindings !== []) {
                $context['bindings'] = $this->bindingFormatter->format(
                    $event->bindings,
                    $statement,
                    is_array($config['bindings'] ?? null) ? $config['bindings'] : [],
                    $this->bindingColumnRegistry,
                    $state->sensitiveColumnRules(),
                );
            }

            Log::info($this->app->make(StarLog::class)->translate('query.executed', [
                'connection' => $event->connectionName,
                'duration' => "{$event->time}ms",
            ]), $context);

            $state->incrementEntryCount();
        });
    }

    /**
     * Limit SQL text without changing the statement used for rule matching.
     */
    private function truncateSql(string $sql, array $config): string
    {
        $maximumLength = $this->nullablePositiveInteger($config['max_sql_length'] ?? null);

        if ($maximumLength === null || mb_strlen($sql) <= $maximumLength) {
            return $sql;
        }

        return mb_substr($sql, 0, $maximumLength) . '…';
    }

    /**
     * Treat only positive integer configuration values as limits.
     */
    private function nullablePositiveInteger(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }
}
