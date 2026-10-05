<?php

namespace Caixingyue\LaravelStarLog\Tests\Http\Client\Middleware;

use Caixingyue\LaravelStarLog\Facades\StarLog;
use Caixingyue\LaravelStarLog\Http\Client\HttpClientLogSnapshot;
use Caixingyue\LaravelStarLog\Http\Client\HttpClientLogSnapshots;
use Caixingyue\LaravelStarLog\Http\Client\Middleware\CaptureHttpClientLogSnapshot;
use Caixingyue\LaravelStarLog\Tests\TestCase;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Mockery;
use RuntimeException;

final class CaptureHttpClientLogSnapshotTest extends TestCase
{
    public function test_snapshot_is_available_before_the_handler_and_the_response_keeps_its_request(): void
    {
        $snapshots = $this->app->make(HttpClientLogSnapshots::class);
        $request = new Request('GET', 'https://example.test');
        $response = new Response;
        $snapshot = new HttpClientLogSnapshot(['enable' => false]);
        $options = [HttpClientLogSnapshot::OPTION => $snapshot];
        $handler = (new CaptureHttpClientLogSnapshot)(function ($sentRequest, $sentOptions) use ($snapshots, $request, $snapshot, $response, $options) {
            $this->assertSame($request, $sentRequest);
            $this->assertSame($options, $sentOptions);
            $this->assertSame($snapshot, $snapshots->forRequest($request));

            return Create::promiseFor($response);
        });

        $this->assertSame($response, $handler($request, $options)->wait());
        $this->assertSame($request, $snapshots->requestForResponse($response));
    }

    public function test_configuration_failure_does_not_replace_a_successful_response(): void
    {
        $config = Mockery::mock();
        $config->shouldReceive('getConfig')->once()->andThrow(new RuntimeException('configuration failed'));
        StarLog::swap($config);
        $request = new Request('GET', 'https://example.test');
        $response = new Response;
        $handler = (new CaptureHttpClientLogSnapshot)(static fn () => Create::promiseFor($response));

        $this->assertSame($response, $handler($request, [])->wait());
    }

    public function test_rejection_preserves_the_original_exception(): void
    {
        $failure = new RuntimeException('transport failed');
        $handler = (new CaptureHttpClientLogSnapshot)(static fn () => Create::rejectionFor($failure));

        try {
            $handler(new Request('GET', 'https://example.test'), [])->wait();
            $this->fail('The transport exception must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }
    }

    public function test_synchronous_failure_preserves_the_original_exception(): void
    {
        $failure = new RuntimeException('transport failed');
        $handler = (new CaptureHttpClientLogSnapshot)(static function () use ($failure): never {
            throw $failure;
        });

        try {
            $handler(new Request('GET', 'https://example.test'), []);
            $this->fail('The transport exception must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }
    }
}
