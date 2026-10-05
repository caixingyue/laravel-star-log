<?php

namespace Caixingyue\LaravelStarLog\Formatters;

use Caixingyue\LaravelStarLog\Facades\StarLog;
use Caixingyue\LaravelStarLog\Http\Client\Middleware\RequestConnectionFailureToLog;
use Caixingyue\LaravelStarLog\Http\Middleware\RouteLog;
use Caixingyue\LaravelStarLog\Listeners\Http\RequestHandledToLog;
use Caixingyue\LaravelStarLog\Listeners\HttpClient\RequestSendingToLog;
use Caixingyue\LaravelStarLog\Listeners\HttpClient\ResponseReceivedToLog;
use Caixingyue\LaravelStarLog\Listeners\QueryExecutedToLog;
use Caixingyue\LaravelStarLog\Logging\LogContextSnapshot;
use Caixingyue\LaravelStarLog\StarLog as StarLogImpl;
use Illuminate\Http\Request;
use Illuminate\Log\Logger;
use Illuminate\Log\LogManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Facade;
use Monolog\Formatter\LineFormatter as MonologLineFormatter;
use Monolog\Logger as MonologLogger;
use Monolog\LogRecord;

/**
 * Format log records with correlation and caller information.
 *
 * The formatter extends Monolog's line formatter so it can be configured as a
 * standard Laravel log channel formatter.
 *
 * @author Xiaorui Waldesbelia <xinghuangying@gmail.com>
 */
class StarLogFormatter extends MonologLineFormatter
{
    public const SIMPLE_FORMAT = "[%datetime%] %channel%.%level_name% [%correlation%] [%call_site%]: %message% %context% %extra%\n";

    private const CALL_SITE_BACKTRACE_LIMIT = 25;

    /**
     * Create a log formatter.
     */
    public function __construct(?string $format = null, ?string $dateFormat = null, bool $allowInlineLineBreaks = true, bool $ignoreEmptyContextAndExtra = true, bool $includeStacktraces = false)
    {
        parent::__construct($format, $dateFormat, $allowInlineLineBreaks, $ignoreEmptyContextAndExtra, $includeStacktraces);
    }

    /**
     * Capture the template's execution fields while the original call is active.
     */
    public function captureContext(LogRecord $record): LogRecord
    {
        foreach ($record->extra as $value) {
            if ($value instanceof LogContextSnapshot) {
                return $record;
            }
        }

        $snapshot = new LogContextSnapshot(
            correlation: str_contains($this->format, '%correlation%') ? $this->formatCorrelation() : null,
            callSite: str_contains($this->format, '%call_site%') ? $this->resolveCallSite() : null,
            ips: str_contains($this->format, '%ips%') ? $this->formatRequestIps() : null,
        );

        $extra = $record->extra;
        $key = '_starlog_snapshot';

        while (array_key_exists($key, $extra)) {
            $key .= '_';
        }

        $extra[$key] = $snapshot;

        return $record->with(extra: $extra);
    }

    /**
     * {@inheritdoc}
     */
    public function format(LogRecord $record): string
    {
        $format = $this->format;
        $extra = $record->extra;
        $snapshot = null;

        foreach ($extra as $key => $value) {
            if ($value instanceof LogContextSnapshot) {
                $snapshot = $value;
                unset($extra[$key]);

                break;
            }
        }

        $record = $record->with(extra: $extra);

        foreach (['correlation', 'call_site', 'ips'] as $field) {
            $placeholder = "%{$field}%";

            if (! str_contains($format, $placeholder)) {
                continue;
            }

            $value = match ($field) {
                'correlation' => $snapshot === null ? $this->formatCorrelation() : $snapshot->correlation,
                'call_site' => $snapshot === null ? $this->resolveCallSite() : $snapshot->callSite,
                'ips' => $snapshot === null ? $this->formatRequestIps() : $snapshot->ips,
            };

            if ($field === 'correlation') {
                $record = $this->withoutDuplicateRequestId($record, $value);
            }

            if ($value === null || $value === '') {
                $format = $this->withoutOptionalSection($format, $placeholder);

                continue;
            }

            $key = '_starlog_' . $field;

            while (array_key_exists($key, $extra)) {
                $key .= '_';
            }

            $extra[$key] = $value;
            $format = str_replace($placeholder, "%extra.{$key}%", $format);
        }

        if ($this->format === static::SIMPLE_FORMAT && $this->ignoreEmptyContextAndExtra) {
            if ($record->context === []) {
                $format = str_replace(' %context%', '', $format);
            }

            if ($record->extra === []) {
                $format = str_replace(' %extra%', '', $format);
            }
        }

        return (clone $this)->formatWithTemplate($record->with(extra: $extra), $format);
    }

    /**
     * Render on a copy so nested log calls keep the original template and options.
     */
    private function formatWithTemplate(LogRecord $record, string $format): string
    {
        $this->format = $format;

        return parent::format($record);
    }

    /**
     * Omit a duplicate ID only when the correlation section displays it.
     * An explicitly requested context.request_id placeholder always retains it.
     */
    private function withoutDuplicateRequestId(LogRecord $record, ?string $correlation): LogRecord
    {
        $requestId = $record->context['request_id'] ?? null;

        if (! is_int($requestId) || $correlation === null
            || str_contains($this->format, '%context.request_id%')
            || ! in_array('request_id=' . $requestId, explode(', ', $correlation), true)) {
            return $record;
        }

        return $record->with(context: Arr::except($record->context, 'request_id'));
    }

    /**
     * Format the entry and current execution IDs for the log record.
     */
    protected function formatCorrelation(): ?string
    {
        $app = StarLog::getFacadeApplication();

        if ($app === null || ! $app->bound(StarLogImpl::class)) {
            return null;
        }

        $correlations = [];

        foreach (StarLog::getDisplayCorrelations() as $item) {
            $correlations[] = "{$item['type']}_id={$item['id']}";
        }

        return $correlations === [] ? null : implode(', ', $correlations);
    }

    /**
     * Remove an unavailable section and its brackets from the format template.
     */
    private function withoutOptionalSection(string $format, string $placeholder): string
    {
        return str_replace([" [{$placeholder}]", "[{$placeholder}] ", "[{$placeholder}]", $placeholder], '', $format);
    }

    /**
     * Resolve the application call site for the current log record.
     */
    protected function resolveCallSite(): ?string
    {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::CALL_SITE_BACKTRACE_LIMIT);
        $loggerFrameIndex = null;

        foreach ($backtrace as $index => $frame) {
            $class = $frame['class'] ?? null;
            $type = $frame['type'] ?? null;

            if ($class === Facade::class && $type === '::') {
                return $this->formatCallSite($backtrace, $index);
            }

            if ($type === '->' && in_array($class, [LogManager::class, Logger::class, MonologLogger::class], true)) {
                $loggerFrameIndex = $index;
            }
        }

        return $loggerFrameIndex === null ? null : $this->formatCallSite($backtrace, $loggerFrameIndex);
    }

    /**
     * Format the class, method, and line from a matching stack frame.
     */
    private function formatCallSite(array $backtrace, int $index): ?string
    {
        $line = $backtrace[$index]['line'] ?? null;
        $index++;

        while ($this->isLoggerHelper($backtrace[$index] ?? null)) {
            $line = $backtrace[$index]['line'] ?? $line;
            $index++;
        }

        $frame = $backtrace[$index] ?? null;

        if ($frame === null) {
            return null;
        }

        $className = $this->resolveClassName($frame['class'] ?? '');
        $className = str_replace('\\', '.', $className);
        $functionName = $this->resolveFunctionName($frame);

        return trim("{$className}@{$functionName}:{$line}", '@:');
    }

    /**
     * Determine whether a stack frame is Laravel's global logger helper.
     */
    private function isLoggerHelper(?array $frame): bool
    {
        return ! isset($frame['class']) && ($frame['function'] ?? null) === 'logger';
    }

    /**
     * Replace framework integration classes with concise log labels.
     */
    private function resolveClassName(string $name): string
    {
        return match ($name) {
            QueryExecutedToLog::class, RouteLog::class, RequestHandledToLog::class => 'System',
            RequestSendingToLog::class, ResponseReceivedToLog::class, RequestConnectionFailureToLog::class => 'HttpClient',
            default => $name,
        };
    }

    /**
     * Replace framework integration methods with concise log labels.
     */
    private function resolveFunctionName(array $frame): string
    {
        $label = match ($frame['class'] ?? null) {
            QueryExecutedToLog::class => 'db',
            RouteLog::class, RequestSendingToLog::class => 'request',
            RequestHandledToLog::class, ResponseReceivedToLog::class => 'response',
            RequestConnectionFailureToLog::class => 'failure',
            default => null,
        };

        if ($label !== null) {
            return $label;
        }

        $function = $frame['function'] ?? null;

        if (is_string($function) && (str_starts_with($function, '{closure') || str_ends_with($function, '\\{closure}'))) {
            return 'closure';
        }

        return is_string($function) ? $function : 'unknown';
    }

    /**
     * Format every client and proxy address reported by the request.
     */
    protected function formatRequestIps(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        $ips = app(Request::class)->ips();

        return $ips === [] ? null : implode(' -> ', $ips);
    }
}
