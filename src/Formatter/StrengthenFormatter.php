<?php

namespace Caixingyue\LaravelStarLog\Formatter;

use Caixingyue\LaravelStarLog\Facades\StarLog;
use Caixingyue\LaravelStarLog\Http\Middleware\RouteLog;
use Caixingyue\LaravelStarLog\Listeners\Http\RequestSendingToLog;
use Caixingyue\LaravelStarLog\Listeners\Http\ResponseReceivedToLog;
use Caixingyue\LaravelStarLog\QueryServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Monolog\Formatter\LineFormatter as SourceLineFormatter;
use Monolog\LogRecord;

/**
 * Formats incoming records into a one-line string
 *
 * This is especially useful for logging to files
 *
 * This is enhanced edition inherited the source line-formatter
 *
 * @author Xiaorui Waldesbelia <xinghuangying@gmail.com>
 */
class StrengthenFormatter extends SourceLineFormatter
{
    public const SIMPLE_FORMAT = "[%datetime%] %requestId%.%level_name%[%positioning%]: %message% %context% %extra%\n";

    private mixed $object;

    public function __construct(?string $format = null, ?string $dateFormat = null, bool $allowInlineLineBreaks = true, bool $ignoreEmptyContextAndExtra = true, bool $includeStacktraces = false)
    {
        parent::__construct($format, $dateFormat, $allowInlineLineBreaks, $ignoreEmptyContextAndExtra, $includeStacktraces);
    }

    /**
     * {@inheritdoc}
     */
    public function format(LogRecord $record): string
    {
        $output = parent::format($record);
        $output = Str::of($output);

        if ($output->contains('%positioning%') && $positioning = $this->positioning()) {
            $output = $output->replace('%positioning%', $positioning);
        }

        if ($output->contains('%requestId%')) {
            if ($requestId = $this->requestId()) {
                $artisanTaskId = $this->artisanTaskId();
                $queueTaskId = $this->queueTaskId();

                $output = match (true) {
                    $artisanTaskId && $output->contains('Commands') => $output->replace('%requestId%', $requestId . ' -> ' . $artisanTaskId),
                    !$artisanTaskId && $output->contains('Commands') => $output->replace('%requestId%', $requestId . ' -> ' . 'Artisan'),
                    $queueTaskId && $output->contains('Jobs') => $output->replace('%requestId%', $requestId . ' -> ' . $queueTaskId),
                    !$queueTaskId && $output->contains('Jobs') => $output->replace('%requestId%', $requestId . ' -> ' . 'Queue'),
                    default => $output->replace('%requestId%', $requestId)
                };
            } elseif ($output->contains('Commands')) {
                $artisanTaskId = $this->artisanTaskId();

                $output = match (true) {
                    (bool) $artisanTaskId == true => $output->replace('%requestId%', $artisanTaskId),
                    (bool) $artisanTaskId == false => $output->replace('%requestId%', 'Artisan')
                };
            } elseif ($output->contains('Jobs')) {
                $queueTaskId = $this->queueTaskId();

                $output = match (true) {
                    (bool) $queueTaskId == true => $output->replace('%requestId%', $queueTaskId),
                    (bool) $queueTaskId == false => $output->replace('%requestId%', 'Queue')
                };
            } elseif ($artisanTaskId = $this->artisanTaskId()) {
                $output = $output->replace('%requestId%', $artisanTaskId);
            } elseif ($queueTaskId = $this->queueTaskId()) {
                $output = $output->replace('%requestId%', $queueTaskId);
            } else {
                $output = $output->replace('%requestId%', $record->channel);
            }
        }

        if ($output->contains('%ips%') && $ips = $this->ips()) {
            $output = $output->replace('%ips%', $ips);
        }

        return $output;
    }

    /**
     * Class method and function location
     *
     * @return ?string
     */
    protected function positioning(): ?string
    {
        $data = debug_backtrace();

        // Facade logic processing
        foreach ($data as $key => $item) {
            $is_class = Arr::get($item, 'class') == 'Illuminate\Support\Facades\Facade';
            $is_type = Arr::get($item, 'type') == '::';
            if ($is_class && $is_type) {
                return $this->extract($data, $item, $key);
            }
        }

        // Helper function logic processing
        foreach ($data as $key => $item) {
            $is_class = Arr::get($item, 'class') == 'Illuminate\Log\LogManager';
            $is_type = Arr::get($item, 'type') == '->';
            $is_func = in_array(Arr::get($item, 'function'), ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency']);
            if ($is_class && $is_type && $is_func) {
                return $this->extract($data, $item, $key);
            }
        }

        return null;
    }

    /**
     * Parsing classes and function methods
     *
     * @param array $backtrace
     * @param array $item
     * @param int $key
     * @return string|void
     */
    private function extract(array $backtrace, array $item, int $key)
    {
        $data = Arr::get($backtrace, $key + 1);
        if ($data) {
            $this->object = Arr::get($data, 'object');

            $class_name = Arr::get($data, 'class');
            $class_name = $this->conversionClassName($class_name);
            $class_name = str_replace('\\', '.', $class_name);

            $fun_name = $this->conversionFunName($data);
            $line = Arr::get($item, 'line');
            $val = Str::of($class_name)->append('@')->append($fun_name)->append(':')->append($line)->toString();

            $is_val = substr($val, 0, 1);
            if (in_array($is_val, ['@', ':'])) $val = substr($val, 1);
            if ($is_val == '@:') $val = substr($val, 2);

            $is_val = substr($val, strlen($val) - 1);
            if (in_array($is_val, ['@', ':'])) $val = substr($val, 0, strlen($val) - 1);
            if ($is_val == '@:') $val = substr($val, 0, strlen($val) - 2);

            return $val;
        }
    }

    /**
     * Conversion class name to specify new name
     *
     * @param string $name
     * @return string
     */
    private function conversionClassName(string $name): string
    {
        $data = [
            QueryServiceProvider::class => 'System',
            RouteLog::class => 'System',
            RequestSendingToLog::class => 'HttpClient',
            ResponseReceivedToLog::class => 'HttpClient',
        ];

        return Arr::get($data, $name, $name);
    }

    /**
     * Conversion class function name to specify new name
     *
     * @param array $class
     * @return string
     */
    private function conversionFunName(array $class): string
    {
        $data = [
            QueryServiceProvider::class => 'db',
            RequestSendingToLog::class => 'request',
            ResponseReceivedToLog::class => 'response',
        ];

        if ($funName = Arr::get($data, get_class($this->object))) {
            return $funName;
        }

        return Arr::get($class, 'function');
    }

    /**
     * Get Request ID
     *
     * @return int|null
     */
    protected function requestId(): ?int
    {
        return StarLog::getRequestId();
    }

    /**
     * Get Artisan ID
     *
     * @return int|null
     */
    protected function artisanTaskId(): ?int
    {
        return StarLog::getArtisanId($this->object);
    }

    /**
     * Get Queue ID
     *
     * @return int|null
     */
    protected function queueTaskId(): ?int
    {
        return StarLog::getQueueId($this->object);
    }

    /**
     * Get all proxy server addresses passed
     *
     * @return string
     */
    protected function ips(): string
    {
        $request = app(Request::class);
        return implode(' -> ', $request->ips());
    }
}
