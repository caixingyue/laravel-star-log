<?php

namespace Caixingyue\LaravelStarLog;

use Caixingyue\LaravelStarLog\Correlation\CorrelationContext;
use Caixingyue\LaravelStarLog\Http\Client\HttpClientLogState;
use Caixingyue\LaravelStarLog\Query\QueryLogState;
use Caixingyue\LaravelStarLog\Support\DailyIdGenerator;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use OverflowException;
use RuntimeException;

readonly class StarLog
{
    private CorrelationContext $correlationContext;

    /**
     * Create a new star log instance.
     */
    public function __construct(
        private Request $request,
        private array $config,
        DailyIdGenerator $idGenerator,
        private QueryLogState $queryLogState,
        private HttpClientLogState $httpClientLogState = new HttpClientLogState,
    ) {
        $this->correlationContext = new CorrelationContext($idGenerator);
    }

    /**
     * Get the complete correlation chain for the active execution context.
     */
    public function getCorrelationChain(): array
    {
        return $this->correlationContext->getCorrelationChain();
    }

    /**
     * Get the correlation item for the currently executing unit.
     */
    public function getCurrentCorrelation(): ?array
    {
        return $this->correlationContext->getCurrentCorrelation();
    }

    /**
     * Get the correlation item that directly invoked the current unit.
     */
    public function getParentCorrelation(): ?array
    {
        return $this->correlationContext->getParentCorrelation();
    }

    /**
     * Get the request correlation ID.
     */
    public function getRequestId(): ?int
    {
        return $this->correlationContext->getRequestId();
    }

    /**
     * Get the current or nearest preceding Artisan command correlation ID.
     */
    public function getArtisanId(): ?int
    {
        return $this->correlationContext->getArtisanId();
    }

    /**
     * Get the current or nearest preceding queue job correlation ID.
     */
    public function getQueueId(): ?int
    {
        return $this->correlationContext->getQueueId();
    }

    /**
     * Get the nearest Artisan correlation ID before the current execution unit.
     */
    public function getNearestArtisanId(): ?int
    {
        return $this->correlationContext->getNearestArtisanId();
    }

    /**
     * Get the nearest queue correlation ID before the current execution unit.
     */
    public function getNearestQueueId(): ?int
    {
        return $this->correlationContext->getNearestQueueId();
    }

    /**
     * Get the first and current items for the default log format.
     */
    public function getDisplayCorrelations(): array
    {
        return $this->correlationContext->getDisplayCorrelations();
    }

    /**
     * Replace the active correlation chain.
     */
    public function setCorrelationChain(array $chain): void
    {
        $this->correlationContext->setCorrelationChain($chain);
    }

    /**
     * Start a new correlation chain for the current HTTP request.
     *
     * @throws RuntimeException
     * @throws OverflowException
     * @throws LockTimeoutException
     */
    public function startRequestCorrelation(): int
    {
        $this->queryLogState->resetEntryCount();

        return $this->correlationContext->startRequestCorrelation($this->request);
    }

    /**
     * Append a new Artisan correlation ID to the active chain.
     *
     * @throws RuntimeException
     * @throws OverflowException
     * @throws LockTimeoutException
     */
    public function appendArtisanCorrelation(object|string $object): int
    {
        return $this->correlationContext->appendArtisanCorrelation($object);
    }

    /**
     * Create a queue correlation chain without changing the active context.
     *
     * @throws RuntimeException
     * @throws OverflowException
     * @throws LockTimeoutException
     */
    public function createQueueCorrelationChain(object|string $object): array
    {
        return $this->correlationContext->createQueueCorrelationChain($object);
    }

    /**
     * Generate an ID that does not collide with the active correlation chain.
     *
     * @throws RuntimeException
     * @throws OverflowException
     * @throws LockTimeoutException
     */
    public function generateCorrelationId(): int
    {
        return $this->correlationContext->generateCorrelationId();
    }

    /**
     * Get star log config.
     */
    public function getConfig(?string $key = null, mixed $default = null): mixed
    {
        $config = $this->config;
        $section = $key === null ? null : explode('.', $key, 2)[0];

        if ($section === null || $section === 'http_client') {
            $config['http_client'] = $this->httpClientLogState->resolve(is_array($config['http_client'] ?? null) ? $config['http_client'] : []);
        }

        if ($section === null || $section === 'query') {
            $config['query'] = $this->queryLogState->resolve(is_array($config['query'] ?? null) ? $config['query'] : []);
        }

        return $key ? data_get($config, $key, $default) : $config;
    }

    /**
     * Translate one package log message using its configured locale or configured application locale.
     */
    public function translate(string $key, array $replace = []): string
    {
        $locale = $this->getConfig('locale') ?? config('app.locale');

        return __('star-log::star-log.' . $key, $replace, $locale);
    }

    /**
     * Ignore SQL logging for a primary table until it is resumed.
     */
    public function ignoreQueryTable(string $table): void
    {
        $this->queryLogState->ignoreTable($table);
    }

    /**
     * Restore SQL logging for one ignored primary table level.
     */
    public function resumeQueryTable(string $table): void
    {
        $this->queryLogState->resumeTable($table);
    }

    /**
     * Run a callback without writing SQL logs.
     */
    public function withoutQueryLogging(Closure $callback): mixed
    {
        return $this->queryLogState->withoutLogging($callback);
    }

    /**
     * Run a callback without logging queries for the specified primary tables.
     *
     * @param  array<int, string>  $tables
     */
    public function withoutQueryLoggingForTables(array $tables, Closure $callback): mixed
    {
        return $this->queryLogState->withoutTables($tables, $callback);
    }

    /**
     * Run a callback with HTTP client logging enabled, unless an outer scope paused it.
     */
    public function withHttpClientLogging(Closure $callback): mixed
    {
        return $this->withHttpClientLogOptions(['enable' => true], $callback);
    }

    /**
     * Run a callback without HTTP client request, response, or connection failure logs.
     */
    public function withoutHttpClientLogging(Closure $callback): mixed
    {
        return $this->httpClientLogState->withoutLogging($callback);
    }

    /**
     * Temporarily override HTTP client log options; header lists replace inherited lists.
     */
    public function withHttpClientLogOptions(array $options, Closure $callback): mixed
    {
        return $this->httpClientLogState->withOptions($options, $callback);
    }

    /**
     * Temporarily mask HTTP client headers, query fields, and body fields matching these paths.
     */
    public function withHttpClientSensitiveFields(array $fields, Closure $callback): mixed
    {
        return $this->httpClientLogState->withSensitiveFields($fields, true, $callback);
    }

    /**
     * Temporarily allow matching HTTP client fields to be logged without masking.
     */
    public function withoutHttpClientSensitiveFields(array $fields, Closure $callback): mixed
    {
        return $this->httpClientLogState->withSensitiveFields($fields, false, $callback);
    }

    /**
     * Run a callback with SQL logging enabled, while retaining pauses and other filters.
     */
    public function withQueryLogging(Closure $callback): mixed
    {
        return $this->withQueryLogOptions(['enable' => true], $callback);
    }

    /**
     * Temporarily override SQL log options without resetting the execution's entry count.
     */
    public function withQueryLogOptions(array $options, Closure $callback): mixed
    {
        return $this->queryLogState->withOptions($options, $callback);
    }

    /**
     * Temporarily mask verified SQL binding columns, overriding model column settings.
     *
     * @param  array<string, array<int, string>>  $columns  Table names (or *) mapped to column names.
     */
    public function withQuerySensitiveColumns(array $columns, Closure $callback): mixed
    {
        return $this->queryLogState->withSensitiveColumns($columns, true, $callback);
    }

    /**
     * Temporarily cancel masking for verified columns; disabled bindings stay hidden.
     *
     * @param  array<string, array<int, string>>  $columns  Table names (or *) mapped to column names.
     */
    public function withoutQuerySensitiveColumns(array $columns, Closure $callback): mixed
    {
        return $this->queryLogState->withSensitiveColumns($columns, false, $callback);
    }

    /**
     * Add a predicate to the existing SQL log filters while the callback runs.
     */
    public function withQueryLogFilter(Closure $filter, Closure $callback): mixed
    {
        return $this->queryLogState->withFilter($filter, $callback);
    }
}
