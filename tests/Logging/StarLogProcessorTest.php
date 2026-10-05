<?php

namespace Caixingyue\LaravelStarLog\Tests\Logging;

use Caixingyue\LaravelStarLog\Formatters\StarLogFormatter;
use Caixingyue\LaravelStarLog\Logging\AttachStarLogProcessor;
use Caixingyue\LaravelStarLog\Logging\StarLogProcessor;
use Caixingyue\LaravelStarLog\Tests\TestCase;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Log\Logger as LaravelLogger;
use Illuminate\Support\Facades\Log;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\BufferHandler;
use Monolog\Handler\FingersCrossedHandler;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use WeakReference;

final class StarLogProcessorTest extends TestCase
{
    public function test_tap_registers_once_for_a_handler_shared_by_multiple_channels_and_preserves_other_processors(): void
    {
        $stream = fopen('php://memory', 'w+');
        $writer = new class($stream) extends StreamHandler
        {
            public int $registrations = 0;

            public function pushProcessor(callable $callback): HandlerInterface
            {
                $this->registrations++;

                return parent::pushProcessor($callback);
            }
        };
        $writer->setFormatter($this->formatter());
        $writer->pushProcessor(static fn (LogRecord $record): LogRecord => $record->with(extra: ['marker' => 'retained'] + $record->extra));
        $first = new LaravelLogger(new Logger('first', [$writer]));
        $second = new LaravelLogger(new Logger('second', [$writer]));
        $tap = new AttachStarLogProcessor;

        try {
            $tap($first);
            $tap($first);
            (new AttachStarLogProcessor)($second);

            $this->assertSame(2, $writer->registrations);
            $second->info('A');
            $output = $this->read($stream);
            $this->assertStringContainsString('[queue_id=101]', $output);
            $this->assertStringContainsString('"marker":"retained"', $output);
            $this->assertStringNotContainsString('_starlog_snapshot', $output);
        } finally {
            $first->getLogger()->close();
            $second->getLogger()->close();
        }
    }

    public function test_registration_does_not_keep_handlers_alive(): void
    {
        $writer = new StreamHandler('php://memory');
        $writer->setFormatter($this->formatter());
        $reference = WeakReference::create($writer);
        $logger = new LaravelLogger(new Logger('temporary', [$writer]));
        (new AttachStarLogProcessor)($logger);

        unset($writer, $logger);

        $this->assertNull($reference->get());
    }

    public function test_fingers_crossed_output_retains_each_records_original_execution_information(): void
    {
        $formatter = $this->formatter();
        $stream = fopen('php://memory', 'w+');
        $writer = new StreamHandler($stream);
        $writer->setFormatter($formatter);
        $logger = new Logger('snapshot', [new FingersCrossedHandler($writer, Level::Error)]);
        (new AttachStarLogProcessor)(new LaravelLogger($logger));

        try {
            $logger->info('A');
            $this->assertSame('', $this->read($stream));

            $formatter->correlation = 'queue_id=202';
            $formatter->callSite = 'App.Jobs.B@handle:22';
            $formatter->ips = '198.51.100.20';
            $logger->error('B');

            $output = $this->read($stream);
            $this->assertStringContainsString('[queue_id=101] [App.Jobs.A@handle:11] [203.0.113.10]: A', $output);
            $this->assertStringContainsString('[queue_id=202] [App.Jobs.B@handle:22] [198.51.100.20]: B', $output);
            $this->assertStringNotContainsString('_starlog_snapshot', $output);
        } finally {
            $logger->close();
        }
    }

    public function test_buffer_flush_preserves_missing_values_instead_of_using_later_context(): void
    {
        $formatter = $this->formatter();
        $formatter->correlation = $formatter->callSite = $formatter->ips = null;
        $stream = fopen('php://memory', 'w+');
        $writer = new StreamHandler($stream);
        $writer->setFormatter($formatter);
        $buffer = new BufferHandler($writer);
        $logger = new Logger('snapshot', [$buffer]);
        (new AttachStarLogProcessor)(new LaravelLogger($logger));

        try {
            $logger->info('no execution context');
            $formatter->correlation = 'queue_id=202';
            $formatter->callSite = 'App.Jobs.B@handle:22';
            $formatter->ips = '198.51.100.20';
            $buffer->flush();

            $output = $this->read($stream);
            $this->assertStringContainsString('no execution context', $output);
            $this->assertStringNotContainsString('queue_id=', $output);
            $this->assertStringNotContainsString('App.Jobs.', $output);
            $this->assertStringNotContainsString('198.51.100.20', $output);
        } finally {
            $logger->close();
        }
    }

    public function test_capture_preserves_user_extra_and_does_not_replace_an_existing_snapshot(): void
    {
        $formatter = $this->formatter();
        $processor = new StarLogProcessor($formatter);
        $extra = ['_starlog_snapshot' => 'user value', '_starlog_snapshot_' => 'another value'];
        $record = new LogRecord(new DateTimeImmutable, 'snapshot', Level::Info, 'A', [], $extra);

        $captured = $processor($record);
        $formatter->correlation = 'queue_id=202';
        $this->assertSame($captured, $processor($captured));
        $this->assertSame($extra, $record->extra);

        $output = $formatter->format($captured);
        $this->assertStringContainsString('[queue_id=101]', $output);
        $this->assertStringContainsString('"_starlog_snapshot":"user value"', $output);
        $this->assertStringContainsString('"_starlog_snapshot_":"another value"', $output);
        $this->assertStringNotContainsString('_starlog_snapshot__', $output);
    }

    public function test_request_id_deduplication_uses_the_snapshot_and_preserves_explicit_placeholders(): void
    {
        $formatter = $this->formatter(StarLogFormatter::SIMPLE_FORMAT);
        $formatter->correlation = 'request_id=101';
        $record = new LogRecord(new DateTimeImmutable, 'snapshot', Level::Info, 'A', ['request_id' => 101]);
        $captured = (new StarLogProcessor($formatter))($record);
        $formatter->correlation = 'request_id=202';

        $this->assertSame(1, substr_count($formatter->format($captured), 'request_id'));
        $this->assertSame(['request_id' => 101], $record->context);

        $explicit = $this->formatter('[%correlation%] %context.request_id% %message%');
        $this->assertSame('[request_id=101] 101 A', $explicit->format($captured));
    }

    public function test_tap_leaves_other_handlers_extra_fields_unchanged_in_a_mixed_channel(): void
    {
        $starStream = fopen('php://memory', 'w+');
        $plainStream = fopen('php://memory', 'w+');
        $starWriter = new StreamHandler($starStream);
        $starWriter->setFormatter($this->formatter());
        $plainWriter = new StreamHandler($plainStream);
        $plainWriter->setFormatter(new LineFormatter('%message% %extra%'));
        $logger = new Logger('mixed', [$starWriter, $plainWriter]);
        (new AttachStarLogProcessor)(new LaravelLogger($logger));

        try {
            $logger->info('A');
            $this->assertStringContainsString('[queue_id=101]', $this->read($starStream));
            $this->assertSame('A []', $this->read($plainStream));
        } finally {
            $logger->close();
        }
    }

    public function test_laravel_channel_tap_captures_request_ips_and_call_site_before_error_triggers_output(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'starlog-snapshot-');
        config()->set('logging.channels.snapshot', [
            'driver' => 'single',
            'path' => $path,
            'formatter' => StarLogFormatter::class,
            'formatter_with' => ['format' => '[%ips%] [%call_site%]: %message%' . PHP_EOL],
            'tap' => [AttachStarLogProcessor::class],
            'action_level' => 'error',
        ]);

        try {
            $this->app->instance(Request::class, Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.10']));
            $lineA = __LINE__ + 1;
            Log::channel('snapshot')->info('A');
            $this->assertSame('', file_get_contents($path));

            $this->app->instance(Request::class, Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '198.51.100.20']));
            $lineB = __LINE__ + 1;
            Log::channel('snapshot')->error('B');

            $output = file_get_contents($path);
            $class = str_replace('\\', '.', self::class);
            $method = __FUNCTION__;
            $this->assertStringContainsString("[203.0.113.10] [{$class}@{$method}:{$lineA}]: A", $output);
            $this->assertStringContainsString("[198.51.100.20] [{$class}@{$method}:{$lineB}]: B", $output);
        } finally {
            Log::channel('snapshot')->getLogger()->close();
            unlink($path);
        }
    }

    private function read($stream): string
    {
        rewind($stream);

        return stream_get_contents($stream);
    }

    private function formatter(?string $format = null): StarLogFormatter
    {
        return new class($format ?? '[%correlation%] [%call_site%] [%ips%]: %message% %extra%' . PHP_EOL) extends StarLogFormatter
        {
            public ?string $correlation = 'queue_id=101';

            public ?string $callSite = 'App.Jobs.A@handle:11';

            public ?string $ips = '203.0.113.10';

            protected function formatCorrelation(): ?string
            {
                return $this->correlation;
            }

            protected function resolveCallSite(): ?string
            {
                return $this->callSite;
            }

            protected function formatRequestIps(): ?string
            {
                return $this->ips;
            }
        };
    }
}
