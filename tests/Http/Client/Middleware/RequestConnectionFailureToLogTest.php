<?php

namespace Caixingyue\LaravelStarLog\Tests\Http\Client\Middleware;

use Caixingyue\LaravelStarLog\Http\Client\Middleware\RequestConnectionFailureToLog;
use Caixingyue\LaravelStarLog\StarLog as StarLogImplementation;
use Caixingyue\LaravelStarLog\Tests\TestCase;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class RequestConnectionFailureToLogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('starlog.http_client', [
            'enable' => true,
            'sensitive_fields' => ['token'],
        ]);
        $this->refreshStarLog();
        Log::spy();
    }

    public function test_records_a_timeout_without_exposing_sensitive_url_values(): void
    {
        $request = new Request('GET', 'https://example.test?token=secret');
        $exception = new ConnectException('connection failed', $request);

        $this->assertSame($exception, $this->reject($request, $exception, 28));

        Log::shouldHaveReceived('warning')->with(
            'GET[https://example.test?token=******] - Connection failed [timed out].',
        )->once();
    }

    public function test_records_a_response_less_tls_failure(): void
    {
        $request = new Request('GET', 'https://example.test?token=secret');
        $exception = RequestException::create($request);

        $this->assertSame($exception, $this->reject($request, $exception, 60));

        Log::shouldHaveReceived('warning')->with(
            'GET[https://example.test?token=******] - Connection failed [TLS failed].',
        )->once();
    }

    public function test_does_not_record_a_request_failure_that_has_a_response(): void
    {
        $request = new Request('GET', 'https://example.test?token=secret');
        $exception = RequestException::create($request, new Response(500));

        $this->assertSame($exception, $this->reject($request, $exception));

        Log::shouldNotHaveReceived('warning');
    }

    public function test_does_not_record_when_http_client_logging_is_disabled(): void
    {
        config()->set('starlog.http_client.enable', false);
        $this->refreshStarLog();
        $request = new Request('GET', 'https://example.test?token=secret');
        $exception = new ConnectException('connection failed', $request);

        $this->assertSame($exception, $this->reject($request, $exception));

        Log::shouldNotHaveReceived('warning');
    }

    public function test_records_synchronously_thrown_connection_failures(): void
    {
        $request = new Request('GET', 'https://example.test');
        $failure = new ConnectException('synchronous connection failure', $request);
        $handler = (new RequestConnectionFailureToLog)(static function () use ($failure): never {
            throw $failure;
        });

        try {
            $handler($request, []);
            $this->fail('The transport exception must be rethrown.');
        } catch (ConnectException $exception) {
            $this->assertSame($failure, $exception);
        }

        Log::shouldHaveReceived('warning')->with('GET[https://example.test] - Connection failed.')->once();
    }

    public function test_masks_url_credentials_in_failure_logs(): void
    {
        config()->set('starlog.http_client.sensitive_fields', ['username', 'password', 'token']);
        $this->refreshStarLog();
        $request = new Request('GET', 'https://alice:password@example.test?token=secret');
        $exception = new ConnectException('raw secret error', $request);
        $this->assertSame($exception, $this->reject($request, $exception));

        Log::shouldHaveReceived('warning')->with('GET[https://******:******@example.test?token=******] - Connection failed.')->once();
    }

    public function test_preserves_url_credentials_when_not_configured_as_sensitive(): void
    {
        $request = new Request('GET', 'https://alice:password@example.test?token=secret');
        $exception = new ConnectException('transport failed', $request);
        $this->assertSame($exception, $this->reject($request, $exception));

        Log::shouldHaveReceived('warning')->with('GET[https://alice:password@example.test?token=******] - Connection failed.')->once();
    }

    public function test_preserves_the_transport_error_when_the_logger_throws(): void
    {
        Log::shouldReceive('warning')->andThrow(new RuntimeException('logger failed'));
        $request = new Request('GET', 'https://example.test');
        $exception = new ConnectException('transport failed', $request);

        $this->assertSame($exception, $this->reject($request, $exception));
    }

    public function test_preserves_the_callers_stats_callback(): void
    {
        $request = new Request('GET', 'https://example.test');
        $failure = new ConnectException('private details', $request);
        $stats = new TransferStats($request, null, 0, 6);
        $received = null;
        $handler = (new RequestConnectionFailureToLog)(static function ($request, $options) use ($stats, $failure) {
            $options['on_stats']($stats);

            return Create::rejectionFor($failure);
        });

        try {
            $handler($request, ['on_stats' => static function ($stats) use (&$received) {
                $received = $stats;
            }])->wait();
            $this->fail('The original exception must reach the caller.');
        } catch (ConnectException $caught) {
            $this->assertSame($failure, $caught);
        }

        $this->assertSame($stats, $received);
        Log::shouldHaveReceived('warning')->with('GET[https://example.test] - Connection failed [name resolution failed].')->once();
    }

    public function test_reads_stats_reported_after_the_async_handler_returns(): void
    {
        $request = new Request('GET', 'https://example.test');
        $failure = new ConnectException('private details', $request);
        $pending = new \GuzzleHttp\Promise\Promise;
        $onStats = null;
        $handler = (new RequestConnectionFailureToLog)(static function ($request, $options) use ($pending, &$onStats) {
            $onStats = $options['on_stats'];

            return $pending;
        });
        $result = $handler($request, []);
        $onStats(new TransferStats($request, null, 0, 60));
        $pending->reject($failure);

        try {
            $result->wait();
            $this->fail('The original exception must reach the caller.');
        } catch (ConnectException $caught) {
            $this->assertSame($failure, $caught);
        }

        Log::shouldHaveReceived('warning')->with('GET[https://example.test] - Connection failed [TLS failed].')->once();
    }

    public function test_records_guzzle_network_timeouts_without_stats(): void
    {
        if (! class_exists(\GuzzleHttp\Exception\NetworkTimeoutException::class)) {
            $this->markTestSkipped('NetworkTimeoutException is available in Guzzle 8.');
        }

        $request = new Request('GET', 'https://example.test');

        foreach ([\GuzzleHttp\Exception\NetworkTimeoutException::class, \GuzzleHttp\Exception\ConnectTimeoutException::class] as $exceptionClass) {
            $failure = new $exceptionClass('private details', $request);
            $this->assertSame($failure, $this->reject($request, $failure));
        }
        Log::shouldHaveReceived('warning')->with('GET[https://example.test] - Connection failed [timed out].')->twice();
    }

    public function test_preserves_guzzle_7_exception_metadata_without_stats(): void
    {
        if (! method_exists(ConnectException::class, 'getHandlerContext')) {
            $this->markTestSkipped('Guzzle 8 reports metadata through transport stats.');
        }

        $request = new Request('GET', 'https://example.test');
        $failure = new ConnectException('private details', $request, null, ['errno' => 28]);
        $this->assertSame($failure, $this->reject($request, $failure));
        Log::shouldHaveReceived('warning')->with('GET[https://example.test] - Connection failed [timed out].')->once();
    }

    private function reject(Request $request, Throwable $exception, ?int $errno = null): Throwable
    {
        $handler = (new RequestConnectionFailureToLog)(static function ($request, $options) use ($exception, $errno) {
            $options['on_stats'](new TransferStats($request, null, 0, $errno));

            return Create::rejectionFor($exception);
        });

        try {
            $handler($request, [])->wait();
        } catch (Throwable $caught) {
            return $caught;
        }

        $this->fail('The original transport exception was not rethrown.');
    }

    private function refreshStarLog(): void
    {
        $this->app->forgetScopedInstances();
        Facade::clearResolvedInstance(StarLogImplementation::class);
    }
}
