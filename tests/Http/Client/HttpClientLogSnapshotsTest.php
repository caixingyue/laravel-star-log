<?php

namespace Caixingyue\LaravelStarLog\Tests\Http\Client;

use Caixingyue\LaravelStarLog\Facades\StarLog;
use Caixingyue\LaravelStarLog\Http\Client\HttpClientLogSnapshot;
use Caixingyue\LaravelStarLog\Http\Client\HttpClientLogSnapshots;
use Caixingyue\LaravelStarLog\Tests\TestCase;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use WeakReference;

final class HttpClientLogSnapshotsTest extends TestCase
{
    public function test_supplied_and_retained_snapshots_do_not_read_current_configuration(): void
    {
        $config = Mockery::mock();
        $config->shouldReceive('getConfig')->never();
        StarLog::swap($config);
        $snapshots = new HttpClientLogSnapshots;
        $request = new Request('GET', 'https://example.test');
        $snapshot = new HttpClientLogSnapshot(['enable' => false]);

        $this->assertSame($snapshot, $snapshots->forRequest($request, $snapshot));
        $this->assertSame($snapshot, $snapshots->forRequest($request));
        $this->assertSame($snapshot, $snapshots->forRequest($request, new HttpClientLogSnapshot(['enable' => true])));
    }

    public function test_missing_snapshots_capture_configuration_once_per_request(): void
    {
        $config = Mockery::mock();
        $config->shouldReceive('getConfig')->with('http_client', [])->once()->andReturn(['enable' => true]);
        StarLog::swap($config);
        $snapshots = new HttpClientLogSnapshots;
        $request = new Request('GET', 'https://example.test');
        $snapshot = $snapshots->forRequest($request);

        $this->assertSame(['enable' => true], $snapshot->config);
        $this->assertSame($snapshot, $snapshots->forRequest($request));
    }

    public function test_responses_retain_their_own_requests_until_the_responses_are_released(): void
    {
        $snapshots = new HttpClientLogSnapshots;
        $request = new Request('GET', 'https://example.test/first');
        $response = new Response;
        $reference = WeakReference::create($request);
        $snapshots->rememberResponse($request, $response);
        unset($request);

        $this->assertSame($reference->get(), $snapshots->requestForResponse($response));
        $this->assertSame('/first', $snapshots->requestForResponse($response)->getUri()->getPath());
        $this->assertNull($snapshots->requestForResponse(new Response));
        unset($response);
        $this->assertNull($reference->get());
    }

    public function test_configuration_failure_retains_a_disabled_snapshot_without_retrying_the_read(): void
    {
        $config = Mockery::mock();
        $config->shouldReceive('getConfig')->once()->andThrow(new RuntimeException('configuration failed'));
        StarLog::swap($config);
        $snapshots = new HttpClientLogSnapshots;
        $request = new Request('GET', 'https://example.test');
        $snapshot = $snapshots->forRequest($request);

        $this->assertSame([], $snapshot->config);
        $this->assertSame($snapshot, $snapshots->forRequest($request));
    }

    public function test_send_time_configuration_failure_does_not_break_the_request_or_log_with_other_options(): void
    {
        Http::fake(['*' => Http::response('OK')]);
        Log::spy();
        $config = Mockery::mock();
        $config->shouldReceive('getConfig')->once()->andThrow(new RuntimeException('configuration failed'));
        StarLog::swap($config);

        $response = Http::get('https://example.test');

        $this->assertTrue($response->successful());
        $this->assertSame('OK', $response->body());
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('warning');
    }

    public function test_snapshots_are_released_when_their_requests_are_released(): void
    {
        $snapshots = new HttpClientLogSnapshots;
        $request = new Request('GET', 'https://example.test');
        $snapshot = new HttpClientLogSnapshot(['enable' => true]);
        $reference = WeakReference::create($snapshot);
        $snapshots->forRequest($request, $snapshot);
        unset($snapshot);

        $this->assertNotNull($reference->get());
        unset($request);
        $this->assertNull($reference->get());
    }
}
