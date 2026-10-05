<?php

namespace Caixingyue\LaravelStarLog\Tests\Http\Client;

use Caixingyue\LaravelStarLog\Listeners\HttpClient\RequestSendingToLog;
use Caixingyue\LaravelStarLog\StarLog;
use Caixingyue\LaravelStarLog\Tests\TestCase;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Promises\LazyPromise;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class HttpClientLoggingConfigurationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('starlog.http_client.enable', true);
        config()->set('starlog.http_client.sensitive_fields', ['username', 'password', 'authorization']);
        Log::spy();
    }

    public function test_configured_request_headers_are_selected_case_insensitively_and_masked_without_changing_transport(): void
    {
        config()->set('starlog.http_client.request.headers', ['x-trace', 'AUTHORIZATION']);
        $this->refreshStarLog();
        Http::fake(['*' => Http::response(['ok' => true])]);

        Http::withHeaders([
            'X-Trace' => 'trace', 'Authorization' => 'private credential', 'X-Unlisted' => 'not logged',
        ])->post('https://example.test/headers', ['password' => 'secret']);

        Log::shouldHaveReceived('info')->withArgs(static fn ($message, $context): bool => str_ends_with($message, ' - Request:')
            && $context['headers'] === ['X-Trace' => ['trace'], 'Authorization' => '******']
            && $context['body']['data']['password'] === '******')->once();
        Http::assertSent(static fn (Request $request): bool => $request->header('Authorization') === ['private credential']
            && $request->header('X-Unlisted') === ['not logged'] && $request['password'] === 'secret');
    }

    public function test_disabling_query_and_body_logging_preserves_transport_and_omits_query_from_both_log_urls(): void
    {
        config()->set('starlog.http_client.request.query', false);
        config()->set('starlog.http_client.request.body', false);
        $this->refreshStarLog();
        Http::fake(['*' => Http::response(['ok' => true])]);

        Http::post('https://example.test?debug=private&order_id=123', ['password' => 'secret']);

        Log::shouldHaveReceived('info')->with('POST[https://example.test] - Request:', [])->once();
        Log::shouldHaveReceived('info')->withArgs(static fn ($message): bool => str_contains($message, '200[https://example.test] - Response:'))->once();
        Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://example.test?debug=private&order_id=123'
            && $request['password'] === 'secret');
    }

    public function test_client_response_headers_are_selected_and_masked_without_changing_the_response(): void
    {
        config()->set('starlog.http_client.response.headers', ['x-trace', 'SET-COOKIE']);
        config()->set('starlog.http_client.sensitive_fields', ['set-cookie']);
        $this->refreshStarLog();
        Http::fake(['*' => Http::response(['ok' => true], 200, [
            'X-Trace' => ['first', 'second'],
            'Set-Cookie' => ['session=secret', 'token=secret'],
            'X-Unlisted' => 'private',
        ])]);

        $response = Http::get('https://example.test/headers');

        Log::shouldHaveReceived('info')->withArgs(static fn ($message, $context): bool => str_ends_with($message, ' - Request:')
            && ! isset($context['headers']))->once();
        Log::shouldHaveReceived('info')->withArgs(static fn ($message, $context): bool => str_ends_with($message, ' - Response:')
            && $context['headers'] === ['X-Trace' => ['first', 'second'], 'Set-Cookie' => '******'])->once();
        $this->assertSame(['session=secret', 'token=secret'], $response->headers()['Set-Cookie']);
        $this->assertSame(['private'], $response->headers()['X-Unlisted']);
        $this->assertTrue($response->json('ok'));
    }

    public function test_request_header_logging_is_independent_of_the_body_switch(): void
    {
        config()->set('starlog.http_client.request.headers', ['x-trace']);
        config()->set('starlog.http_client.request.body', false);
        $this->refreshStarLog();
        Http::fake(['*' => Http::response('OK', 200, ['X-Trace' => 'response'])]);

        Http::withHeaders(['X-Trace' => 'request'])->post('https://example.test', ['password' => 'secret']);

        Log::shouldHaveReceived('info')->withArgs(static fn ($message, $context): bool => str_ends_with($message, ' - Request:')
            && $context === ['headers' => ['X-Trace' => ['request']]])->once();
        Log::shouldHaveReceived('info')->withArgs(static fn ($message, $context): bool => str_ends_with($message, ' - Response:')
            && ! isset($context['headers']))->once();
    }

    public function test_disabled_query_is_omitted_from_synchronous_failure_logs_while_credentials_are_masked(): void
    {
        config()->set('starlog.http_client.request.query', false);
        $this->refreshStarLog();

        try {
            Http::setHandler(static function ($request) {
                throw new ConnectException('private transport detail', $request);
            })->get('https://alice:password@example.test?debug=private');
            $this->fail('The original connection failure must reach the caller.');
        } catch (ConnectionException $exception) {
            $this->assertInstanceOf(ConnectException::class, $exception->getPrevious());
        }

        Log::shouldHaveReceived('warning')->with('GET[https://******:******@example.test] - Connection failed.')->once();
    }

    public function test_disabled_query_is_omitted_from_asynchronous_response_logs(): void
    {
        config()->set('starlog.http_client.request.query', false);
        config()->set('starlog.http_client.request.headers', ['x-request']);
        config()->set('starlog.http_client.response.headers', ['x-response', 'authorization']);
        $this->refreshStarLog();
        $pending = new Promise;
        $response = Http::async()->withHeaders(['X-Request' => 'request'])->setHandler(static fn () => $pending)->get('https://example.test?debug=private');

        if ($response instanceof LazyPromise) {
            $response->buildPromise();
        }

        $pending->resolve(new Response(200, [
            'Content-Type' => 'application/json', 'X-Response' => ['first', 'second'], 'Authorization' => 'secret',
        ], '{"ok":true}'));
        $this->assertTrue($response->wait()->successful());
        Log::shouldHaveReceived('info')->withArgs(static fn ($message, $context): bool => $message === 'GET[https://example.test] - Request:'
            && $context['headers'] === ['X-Request' => ['request']])->once();
        Log::shouldHaveReceived('info')->withArgs(static fn ($message, $context): bool => str_contains($message, '200[https://example.test] - Response:')
            && $context['headers'] === ['X-Response' => ['first', 'second'], 'Authorization' => '******'])->once();
    }

    public function test_disabled_query_is_omitted_from_asynchronous_failure_logs(): void
    {
        config()->set('starlog.http_client.request.query', false);
        config()->set('starlog.http_client.response.headers', ['x-response']);
        $this->refreshStarLog();
        $response = Http::async()->setHandler(static fn ($request) => Create::rejectionFor(
            new ConnectException('private transport detail', $request),
        ))->get('https://example.test?debug=private');

        $this->assertInstanceOf(ConnectionException::class, $response->wait());
        Log::shouldHaveReceived('warning')->with('GET[https://example.test] - Connection failed.')->once();
        Log::shouldNotHaveReceived('info', static fn ($message): bool => str_ends_with($message, ' - Response:'));
    }

    public function test_disabled_body_logging_leaves_a_non_seekable_request_stream_unread(): void
    {
        config()->set('starlog.http_client.request.body', false);
        $this->refreshStarLog();
        $stream = new NoSeekStream(Utils::streamFor('{"password":"secret"}'));
        $request = new Request(new PsrRequest('POST', 'https://example.test', ['Content-Type' => 'application/json'], $stream));

        (new RequestSendingToLog)->handle(new RequestSending($request));

        $this->assertSame(0, $stream->tell());
        $this->assertSame('{"password":"secret"}', $stream->getContents());
        Log::shouldHaveReceived('info')->with('POST[https://example.test] - Request:', [])->once();
    }

    private function refreshStarLog(): void
    {
        $this->app->forgetScopedInstances();
        Facade::clearResolvedInstance(StarLog::class);
    }
}
