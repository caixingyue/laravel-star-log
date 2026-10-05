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
        $exception = new ConnectException('connection failed', $request, null, ['errno' => 28]);

        $this->assertSame($exception, $this->reject($request, $exception));

        Log::shouldHaveReceived('warning')->with(
            'GET[https://example.test?token=******] - Connection failed [timed out].',
        )->once();
    }

    public function test_records_a_response_less_tls_failure(): void
    {
        $request = new Request('GET', 'https://example.test?token=secret');
        $exception = new RequestException('TLS failed', $request, null, null, ['errno' => 60]);

        $this->assertSame($exception, $this->reject($request, $exception));

        Log::shouldHaveReceived('warning')->with(
            'GET[https://example.test?token=******] - Connection failed [TLS failed].',
        )->once();
    }

    public function test_does_not_record_a_request_failure_that_has_a_response(): void
    {
        $request = new Request('GET', 'https://example.test?token=secret');
        $exception = new RequestException('server error', $request, new Response(500));

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

    private function reject(Request $request, Throwable $exception): Throwable
    {
        $handler = (new RequestConnectionFailureToLog)(static fn () => Create::rejectionFor($exception));

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
