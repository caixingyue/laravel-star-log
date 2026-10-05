<?php

namespace Caixingyue\LaravelStarLog\Tests\Integration\HttpClient;

use Caixingyue\LaravelStarLog\StarLog as StarLogImplementation;
use Caixingyue\LaravelStarLog\Tests\TestCase;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class HttpClientLoggingIntegrationTest extends TestCase
{
    public function test_logging_backend_failures_do_not_break_the_http_client(): void
    {
        Log::shouldReceive('info')->andThrow(new \RuntimeException('log backend failed'));

        $response = Http::setHandler(fn () => Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], '{"ok":true}')))
            ->get('https://example.test');

        $this->assertSame(['ok' => true], $response->json());
    }

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('starlog.http_client.enable', true);
        config()->set('starlog.http_client.sensitive_fields', ['username', 'password', 'token']);
        $this->app->forgetScopedInstances();
        Facade::clearResolvedInstance(StarLogImplementation::class);
        Log::spy();
    }

    public function test_logging_preserves_uploaded_and_received_non_seekable_streams(): void
    {
        $upload = new NoSeekStream(Utils::streamFor('upload contents'));
        $download = new NoSeekStream(Utils::streamFor('download contents'));
        $sentBody = null;

        $response = Http::setHandler(function ($request) use (&$sentBody, $download) {
            $sentBody = $request->getBody()->getContents();

            return Create::promiseFor(new Response(200, ['Content-Type' => 'text/plain'], $download));
        })->withBody($upload, 'text/plain')->post('https://example.test');

        $this->assertSame('upload contents', $sentBody);
        $this->assertSame('download contents', $response->body());
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $context['body']['type'] === 'stream')->twice();
    }

    public function test_registered_failure_middleware_logs_synchronous_failures_once(): void
    {
        try {
            Http::setHandler(function ($request) {
                throw new ConnectException('private transport details', $request, null, ['errno' => 28]);
            })->get('https://alice:password@example.test?token=secret');
            $this->fail('The connection exception must reach the caller.');
        } catch (ConnectionException $exception) {
            $this->assertInstanceOf(ConnectException::class, $exception->getPrevious());
        }

        Log::shouldHaveReceived('warning')->with('GET[https://******:******@example.test?token=******] - Connection failed [timed out].')->once();
    }

    public function test_async_rejection_logs_once_and_preserves_laravel_connection_failure(): void
    {
        $failure = Http::async()->setHandler(fn ($request) => Create::rejectionFor(new ConnectException('private details', $request, null, ['errno' => 28])))
            ->get('https://example.test')->wait();

        $this->assertInstanceOf(ConnectionException::class, $failure);
        $this->assertInstanceOf(ConnectException::class, $failure->getPrevious());

        Log::shouldHaveReceived('warning')->with('GET[https://example.test] - Connection failed [timed out].')->once();
    }

    public function test_retry_recovers_after_one_logged_failure(): void
    {
        $attempts = 0;
        $response = Http::retry(2, 0)->setHandler(function ($request) use (&$attempts) {
            return ++$attempts === 1
                ? Create::rejectionFor(new ConnectException('private details', $request, null, ['errno' => 28]))
                : Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], '{"ok":true}'));
        })->get('https://example.test');

        $this->assertSame(2, $attempts);
        $this->assertSame(['ok' => true], $response->json());
        Log::shouldHaveReceived('warning')->with('GET[https://example.test] - Connection failed [timed out].')->once();
    }

    public function test_plain_text_recording_uses_the_existing_request_body_option(): void
    {
        foreach (['https://api.test/health', 'https://other.test/health'] as $url) {
            config()->set('starlog.http_client.request.body', $url === 'https://api.test/health');
            $this->app->forgetScopedInstances();
            Facade::clearResolvedInstance(StarLogImplementation::class);
            $response = Http::setHandler(fn () => Create::promiseFor(new Response(200, ['Content-Type' => 'text/plain'], 'OK')))
                ->withBody('PING', 'text/plain')->post($url);
            $this->assertSame('OK', $response->body());
        }

        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => str_contains($message, '[https://api.test/health]')
            && in_array($context['body']['data'] ?? null, ['PING', 'OK'], true))->twice();
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => str_contains($message, '[https://other.test/health]')
            && str_contains($message, 'Request:') && ! array_key_exists('body', $context))->once();
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => str_contains($message, '[https://other.test/health]')
            && ($context['body']['data'] ?? null) === 'OK')->once();
    }

    public function test_body_length_limits_text_without_cutting_json_html_or_transport(): void
    {
        config()->set('starlog.http_client.limits.max_body_length', 8);
        config()->set('starlog.http_client.limits.max_string_length', null);
        config()->set('starlog.http_client.sensitive_fields', ['password']);
        $this->app->forgetScopedInstances();
        Facade::clearResolvedInstance(StarLogImplementation::class);

        $data = ['message' => str_repeat('x', 70000), 'password' => 'secret'];
        $html = '<html><head><!--' . str_repeat('x', 70000) . '--><title>Late title</title></head><body><h1>Late heading</h1></body></html>';

        foreach ([
            ['text/plain', 'Hello world' . str_repeat('x', 70000), ['type' => 'text', 'data' => 'Hello wo…']],
            ['application/json', json_encode($data), ['type' => 'json', 'data' => array_replace($data, ['password' => '******'])]],
            ['text/plain', json_encode($data), ['type' => 'json', 'data' => array_replace($data, ['password' => '******'])]],
            ['text/html', $html, ['type' => 'html', 'length' => strlen($html), 'summary' => ['title' => 'Late title', 'heading' => 'Late heading']]],
        ] as [$contentType, $contents, $expected]) {
            $sent = null;
            $response = Http::setHandler(function ($request) use ($contents, $contentType, &$sent) {
                $sent = (string) $request->getBody();

                return Create::promiseFor(new Response(200, ['Content-Type' => $contentType], $contents));
            })->withBody($contents, $contentType)->post('https://example.test');

            $this->assertSame($contents, $sent);
            $this->assertSame($contents, $response->body());
            $expected['content_type'] = $contentType;
            Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $context['body'] == $expected)->twice();
        }
    }

    public function test_malformed_json_is_logged_as_text_without_changing_transport_contents(): void
    {
        $contents = '{"message":"unfinished",';
        $sent = null;
        $response = Http::setHandler(function ($request) use ($contents, &$sent) {
            $sent = (string) $request->getBody();

            return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], $contents));
        })->withBody($contents, 'application/json')->post('https://example.test');

        $this->assertSame($contents, $sent);
        $this->assertSame($contents, $response->body());
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $context['body'] === [
            'type' => 'text', 'content_type' => 'application/json', 'data' => $contents,
        ])->twice();
    }

    public function test_sensitive_fields_select_private_paths_in_client_urls_and_json_bodies(): void
    {
        config()->set('starlog.http_client.sensitive_fields', ['user.token']);
        $this->app->forgetScopedInstances();
        Facade::clearResolvedInstance(StarLogImplementation::class);
        $data = ['pagination' => ['token' => 'next'], 'user' => ['token' => 'secret']];

        $response = Http::setHandler(fn () => Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], json_encode($data))))
            ->post('https://api.test?pagination[token]=next&user[token]=secret', $data);

        $this->assertSame($data, $response->json());
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => str_contains($message, 'pagination%5Btoken%5D=next')
            && str_contains($message, 'user%5Btoken%5D=******')
            && $context['body']['data']['pagination']['token'] === 'next'
            && $context['body']['data']['user']['token'] === '******')->twice();
    }
}
