<?php

namespace Caixingyue\LaravelStarLog\Tests\Formatters;

use Caixingyue\LaravelStarLog\Formatters\StarLogFormatter;
use Caixingyue\LaravelStarLog\Query\QueryLogState;
use Caixingyue\LaravelStarLog\StarLog;
use Caixingyue\LaravelStarLog\Support\DailyIdGenerator;
use Caixingyue\LaravelStarLog\Tests\Fixtures\Formatters\InspectableStarLogFormatter;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Monolog\Formatter\LineFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class StarLogFormatterTest extends TestCase
{
    public function test_default_formatter_preserves_multiline_messages_without_truncation(): void
    {
        $formatter = $this->formatter(null, null);
        $message = "first line\nsecond line\t" . str_repeat('x', 70000);
        $output = $formatter->format(new LogRecord(new DateTimeImmutable, 'local', Level::Info, $message));

        $this->assertSame(2, substr_count($output, "\n"));
        $this->assertStringContainsString($message, $output);
    }

    public function test_inline_line_breaks_can_be_disabled(): void
    {
        $formatter = $this->formatter(null, null);
        $formatter->allowInlineLineBreaks(false);
        $output = $formatter->format(new LogRecord(new DateTimeImmutable, 'local', Level::Info, "first line\nsecond line"));

        $this->assertSame(1, substr_count($output, "\n"));
        $this->assertStringContainsString('first line second line', $output);
    }

    public function test_formats_channel_correlation_and_call_site_as_separate_sections(): void
    {
        $formatter = $this->formatter(
            'request_id=1, queue_id=3',
            'App.Jobs.SyncUser@handle:30'
        );

        $record = new LogRecord(
            new DateTimeImmutable('2026-09-23 10:20:12.123456', new DateTimeZone('Asia/Shanghai')),
            'local',
            Level::Info,
            'Jobs completed'
        );

        $this->assertSame(
            '[2026-09-23 10:20:12.123456] local.INFO [request_id=1, queue_id=3] [App.Jobs.SyncUser@handle:30]: Jobs completed' . PHP_EOL,
            $formatter->format($record)
        );
    }

    public function test_removes_empty_optional_sections_without_leaving_whitespace(): void
    {
        $formatter = $this->formatter(null, null);
        $record = new LogRecord(
            new DateTimeImmutable('2026-09-23 10:20:12.123456', new DateTimeZone('Asia/Shanghai')),
            'local',
            Level::Info,
            'Application started'
        );

        $this->assertSame(
            '[2026-09-23 10:20:12.123456] local.INFO: Application started' . PHP_EOL,
            $formatter->format($record)
        );
    }

    public function test_default_format_includes_message_context_and_extra(): void
    {
        $formatter = $this->formatter('request_id=1', 'System@db:30');
        $datetime = new DateTimeImmutable('2026-09-23 10:20:12.123456', new DateTimeZone('Asia/Shanghai'));

        $this->assertSame(
            '[2026-09-23 10:20:12.123456] local.INFO [request_id=1] [System@db:30]: Completed {"count":1} {"elapsed":12}' . PHP_EOL,
            $formatter->format(new LogRecord(
                $datetime,
                'local',
                Level::Info,
                'Completed',
                ['count' => 1],
                ['elapsed' => 12]
            ))
        );

    }

    public function test_keeps_an_explicit_custom_format_unchanged(): void
    {
        $formatter = $this->formatter('request_id=1', 'System@db:30', null, StarLogFormatter::SIMPLE_FORMAT);
        $datetime = new DateTimeImmutable('2026-09-23 10:20:12.123456', new DateTimeZone('Asia/Shanghai'));

        $this->assertSame(
            '[2026-09-23 10:20:12.123456] local.INFO [request_id=1] [System@db:30]:  {"sql":"select 1"}' . PHP_EOL,
            $formatter->format(new LogRecord($datetime, 'local', Level::Info, '', ['sql' => 'select 1']))
        );
    }

    public function test_keeps_empty_context_and_extra_when_configured(): void
    {
        $formatter = new class(null, 'Y-m-d H:i:s.u', true, false) extends StarLogFormatter
        {
            protected function formatCorrelation(): ?string
            {
                return null;
            }

            protected function resolveCallSite(): ?string
            {
                return null;
            }
        };

        $record = new LogRecord(
            new DateTimeImmutable('2026-09-23 10:20:12.123456', new DateTimeZone('Asia/Shanghai')),
            'local',
            Level::Info,
            ''
        );

        $this->assertSame(
            '[2026-09-23 10:20:12.123456] local.INFO:  [] []' . PHP_EOL,
            $formatter->format($record)
        );
    }

    public function test_uses_the_timezone_from_the_log_record(): void
    {
        $formatter = $this->formatter('request_id=1', null);
        $record = new LogRecord(
            new DateTimeImmutable('2026-09-23 10:20:12.123456', new DateTimeZone('Asia/Shanghai')),
            'production',
            Level::Warning,
            'Application warning'
        );

        $this->assertStringStartsWith(
            '[2026-09-23 10:20:12.123456] production.WARNING',
            $formatter->format($record)
        );
    }

    public function test_formats_request_ips_when_the_format_includes_them(): void
    {
        $formatter = $this->formatter(null, null, '203.0.113.10 -> 10.0.0.1', '[%datetime%] [%ips%]: %message%');
        $record = new LogRecord(
            new DateTimeImmutable('2026-09-23 10:20:12.123456', new DateTimeZone('Asia/Shanghai')),
            'local',
            Level::Info,
            'Request completed'
        );

        $this->assertSame(
            '[2026-09-23 10:20:12.123456] [203.0.113.10 -> 10.0.0.1]: Request completed',
            $formatter->format($record)
        );
    }

    public function test_removes_the_ip_section_when_the_request_has_no_ip_addresses(): void
    {
        $formatter = $this->formatter(null, null, null, '[%datetime%] [%ips%]: %message%');
        $record = new LogRecord(
            new DateTimeImmutable('2026-09-23 10:20:12.123456', new DateTimeZone('Asia/Shanghai')),
            'local',
            Level::Info,
            'Command completed'
        );

        $this->assertSame(
            '[2026-09-23 10:20:12.123456]: Command completed',
            $formatter->format($record)
        );
    }

    public function test_formats_the_ips_of_an_http_request(): void
    {
        $container = new Container;
        $container->instance('request', Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.10']));
        $container->alias('request', Request::class);

        $this->assertRequestIps($container, '203.0.113.10');
    }

    public function test_keeps_a_request_id_when_the_custom_format_has_no_correlation_section(): void
    {
        $output = $this->formatWithRequestContext(
            new StarLogFormatter('[%datetime%] %message% %context%', 'Y-m-d H:i:s'),
            new LogRecord(new DateTimeImmutable('2026-09-23 10:20:12'), 'local', Level::Info, 'Completed', ['request_id' => 101])
        );

        $this->assertSame('[2026-09-23 10:20:12] Completed {"request_id":101}', $output);
    }

    public function test_removes_a_matching_shared_request_id_from_formatter_output(): void
    {
        $record = new LogRecord(
            new DateTimeImmutable('2026-09-23 10:20:12'),
            'local',
            Level::Info,
            'Completed',
            ['request_id' => 101]
        );
        $output = $this->formatWithRequestContext(
            new StarLogFormatter('[%datetime%] [%correlation%] %message% %context%', 'Y-m-d H:i:s'),
            $record
        );

        $this->assertSame('[2026-09-23 10:20:12] [request_id=101] Completed ', $output);
        $this->assertSame(['request_id' => 101], $record->context);
    }

    public function test_keeps_a_request_id_that_does_not_belong_to_the_current_request_correlation(): void
    {
        $output = $this->formatWithRequestContext(
            new StarLogFormatter('[%datetime%] %message% %context%', 'Y-m-d H:i:s'),
            new LogRecord(new DateTimeImmutable('2026-09-23 10:20:12'), 'local', Level::Info, 'Completed', ['request_id' => 202]),
        );

        $this->assertSame('[2026-09-23 10:20:12] Completed {"request_id":202}', $output);
    }

    public function test_keeps_a_null_request_id_in_the_context(): void
    {
        $output = $this->formatWithRequestContext(
            new StarLogFormatter('[%datetime%] %message% %context%', 'Y-m-d H:i:s'),
            new LogRecord(new DateTimeImmutable('2026-09-23 10:20:12'), 'local', Level::Info, 'Starting', ['request_id' => null])
        );

        $this->assertSame('[2026-09-23 10:20:12] Starting {"request_id":null}', $output);
    }

    public function test_custom_placeholder_text_in_messages_context_and_extra_is_preserved(): void
    {
        $record = new LogRecord(new DateTimeImmutable, 'local', Level::Info,
            'Use [%correlation%], [%call_site%], or [%ips%]',
            ['template' => '[%correlation%] %call_site% %ips%'],
            ['template' => '%correlation%']);

        foreach ([
            "%message% %context% %extra%\n" => "%message% %context% %extra%\n",
            "[%correlation%] [%call_site%] [%ips%]: %message% %context% %extra%\n" => "[request_id=101] [App@handle:12] [203.0.113.10]: %message% %context% %extra%\n",
        ] as $format => $expectedFormat) {
            $this->assertSame(
                (new LineFormatter($expectedFormat, null, true, true))->format($record),
                $this->formatter('request_id=101', 'App@handle:12', '203.0.113.10', $format)->format($record)
            );
        }
    }

    public function test_default_format_preserves_message_trailing_spaces_and_tabs(): void
    {
        $message = "Completed  \t ";
        $record = new LogRecord(new DateTimeImmutable, 'local', Level::Info, $message);

        $this->assertStringEndsWith(': ' . $message . PHP_EOL, $this->formatter(null, null)->format($record));
    }

    public function test_custom_format_preserves_its_trailing_whitespace(): void
    {
        $format = "%message% END \t \n";
        $record = new LogRecord(new DateTimeImmutable, 'local', Level::Info, 'Completed   ');

        $this->assertSame("Completed    END \t \n", $this->formatter(null, null, null, $format)->format($record));
    }

    public function test_explicit_request_id_placeholder_is_retained_alongside_correlation(): void
    {
        $record = new LogRecord(new DateTimeImmutable, 'local', Level::Info, 'Completed', ['request_id' => 101]);
        $output = $this->formatWithRequestContext(new StarLogFormatter('[%correlation%] %context.request_id%: %message%'), $record);

        $this->assertSame('[request_id=101] 101: Completed', $output);
        $this->assertSame(['request_id' => 101], $record->context);
    }

    public function test_default_format_omits_only_the_duplicate_request_id(): void
    {
        $record = new LogRecord(new DateTimeImmutable, 'local', Level::Info, 'Completed', ['request_id' => 101, 'order_id' => 7]);
        $output = $this->formatWithRequestContext(new StarLogFormatter, $record);

        $this->assertStringContainsString('[request_id=101]', $output);
        $this->assertStringEndsWith('Completed {"order_id":7}' . PHP_EOL, $output);
        $this->assertSame(['request_id' => 101, 'order_id' => 7], $record->context);
    }

    public function test_internal_placeholder_keys_do_not_overwrite_business_extra_fields(): void
    {
        $record = new LogRecord(new DateTimeImmutable, 'local', Level::Info, 'Completed', [], ['_starlog_correlation' => 'business']);
        $output = $this->formatter('request_id=101', null)->format($record);

        $this->assertStringContainsString('[request_id=101]', $output);
        $this->assertStringEndsWith('Completed {"_starlog_correlation":"business"}' . PHP_EOL, $output);
        $this->assertSame(['_starlog_correlation' => 'business'], $record->extra);
    }

    private function formatter(?string $correlation, ?string $callSite, ?string $ips = null, ?string $format = null): StarLogFormatter
    {
        return new class($correlation, $callSite, $ips, $format) extends StarLogFormatter
        {
            public function __construct(
                private readonly ?string $testCorrelation,
                private readonly ?string $testCallSite,
                private readonly ?string $testIps,
                ?string $format
            ) {
                parent::__construct($format, 'Y-m-d H:i:s.u');
            }

            protected function formatCorrelation(): ?string
            {
                return $this->testCorrelation;
            }

            protected function resolveCallSite(): ?string
            {
                return $this->testCallSite;
            }

            protected function formatRequestIps(): ?string
            {
                return $this->testIps;
            }
        };
    }

    private function assertRequestIps(Container $container, ?string $expected): void
    {
        $previousContainer = Container::getInstance();
        Container::setInstance($container);

        try {
            $this->assertSame($expected, (new InspectableStarLogFormatter)->requestIps());
        } finally {
            Container::setInstance($previousContainer);
        }
    }

    private function formatWithRequestContext(StarLogFormatter $formatter, LogRecord $record): string
    {
        $previousApplication = Facade::getFacadeApplication();
        $app = new Application(__DIR__);
        $starLog = new StarLog(
            Request::create('/'),
            [],
            new DailyIdGenerator(new Repository(new ArrayStore)),
            new QueryLogState
        );
        $starLog->setCorrelationChain([['id' => 101, 'name' => Request::class, 'type' => 'request']]);
        $app->instance(StarLog::class, $starLog);

        Facade::clearResolvedInstance(StarLog::class);
        Facade::setFacadeApplication($app);

        try {
            return $formatter->format($record);
        } finally {
            Facade::clearResolvedInstance(StarLog::class);
            Facade::setFacadeApplication($previousApplication);
        }
    }
}
