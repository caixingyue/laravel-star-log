<?php

namespace Caixingyue\LaravelStarLog\Tests\Http\Client;

use Caixingyue\LaravelStarLog\Facades\StarLog;
use Caixingyue\LaravelStarLog\Http\Client\HttpClientLogSnapshots;
use Caixingyue\LaravelStarLog\StarLog as StarLogService;
use Caixingyue\LaravelStarLog\Tests\TestCase;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class HttpClientLogScopesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('starlog.http_client.enable', false);
        config()->set('starlog.http_client.sensitive_fields', [
            'authorization', 'phone', 'password', 'username', 'profile', 'items.*.token',
        ]);
        $this->app->forgetScopedInstances();
        Facade::clearResolvedInstance(StarLogService::class);
        Log::spy();
    }

    public function test_temporary_options_replace_header_lists_and_restore_after_a_business_exception(): void
    {
        Http::fake(['*' => Http::response(['phone' => 'private'], 200, ['X-Response' => 'response'])]);

        try {
            StarLog::withHttpClientLogOptions([
                'enable' => true,
                'request' => ['headers' => ['X-Request'], 'query' => false, 'body' => false],
                'response' => ['headers' => ['X-Response'], 'body' => false],
            ], function (): void {
                $response = Http::withHeaders(['X-Request' => 'request'])->post('https://example.test/outer?private=yes', ['phone' => 'private']);
                $this->assertSame('private', $response->json('phone'));

                StarLog::withHttpClientLogOptions(['request' => ['headers' => []]], fn () => Http::withHeaders(['X-Request' => 'request'])->get('https://example.test/inner'));

                throw new RuntimeException('business error');
            });
            $this->fail('The callback exception must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('business error', $exception->getMessage());
        }

        $this->assertFalse(StarLog::getConfig('http_client.enable'));
        $this->assertTrue(config('starlog.http_client.request.body'));
        Http::get('https://example.test/outside');
        Log::shouldHaveReceived('info')->with('POST[https://example.test/outer] - Request:', ['headers' => ['X-Request' => ['request']]])->once();
        Log::shouldHaveReceived('info')->with('GET[https://example.test/inner] - Request:', [])->once();
        Log::shouldHaveReceived('info')->withArgs(static fn ($message, $context): bool => str_ends_with($message, ' - Response:')
            && $context === ['headers' => ['X-Response' => ['response']]])->twice();
        Log::shouldNotHaveReceived('info', static fn ($message): bool => str_contains($message, '/outside'));
        Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://example.test/outer?private=yes'
            && $request['phone'] === 'private' && $request->header('X-Request') === ['request']);
    }

    public function test_outer_pause_wins_over_inner_enable_and_request_response_switches_are_independent(): void
    {
        Http::fake(['*' => Http::response('OK')]);
        StarLog::withHttpClientLogging(function (): void {
            StarLog::withoutHttpClientLogging(fn () => StarLog::withHttpClientLogging(fn () => Http::get('https://example.test/paused')));
            StarLog::withHttpClientLogOptions(['request' => ['enable' => false]], fn () => Http::get('https://example.test/response-only'));
            StarLog::withHttpClientLogOptions(['response' => ['enable' => false]], fn () => Http::get('https://example.test/request-only'));
        });

        Log::shouldNotHaveReceived('info', static fn ($message): bool => str_contains($message, '/paused'));
        Log::shouldNotHaveReceived('info', static fn ($message): bool => str_contains($message, '/response-only') && str_ends_with($message, ' - Request:'));
        Log::shouldNotHaveReceived('info', static fn ($message): bool => str_contains($message, '/request-only') && str_ends_with($message, ' - Response:'));
        Log::shouldHaveReceived('info')->twice();
    }

    public function test_sensitive_exceptions_cover_headers_parent_branches_and_wildcards_without_changing_transport(): void
    {
        $data = [
            'profile' => ['phone' => 'visible', 'password' => 'private'],
            'items' => [['token' => 'visible'], ['token' => 'private']],
        ];
        Http::fake(['*' => Http::response($data)]);

        StarLog::withHttpClientLogOptions([
            'enable' => true,
            'request' => ['headers' => ['Authorization']],
        ], fn () => StarLog::withoutHttpClientSensitiveFields(
            ['authorization', 'profile.phone', 'items.0.token'],
            fn () => Http::withToken('visible')->post('https://example.test?profile[phone]=visible&profile[password]=private', $data),
        ));

        Log::shouldHaveReceived('info')->withArgs(static fn ($message, $context): bool => str_ends_with($message, ' - Request:')
            && str_contains(urldecode($message), 'profile[phone]=visible&profile[password]=******')
            && $context['headers']['Authorization'] === ['Bearer visible']
            && $context['body']['data']['profile'] === ['phone' => 'visible', 'password' => '******']
            && $context['body']['data']['items'] === [['token' => 'visible'], ['token' => '******']])->once();
        Log::shouldHaveReceived('info')->withArgs(static fn ($message, $context): bool => str_ends_with($message, ' - Response:')
            && $context['body']['data']['profile'] === ['phone' => 'visible', 'password' => '******']
            && $context['body']['data']['items'] === [['token' => 'visible'], ['token' => '******']])->once();
        Http::assertSent(static fn (Request $request): bool => $request['profile']['password'] === 'private'
            && $request['items'][1]['token'] === 'private' && $request->header('Authorization') === ['Bearer visible']);
    }

    public function test_inner_masking_overrides_outer_exception_and_all_rules_restore(): void
    {
        Http::fake(['*' => Http::response(['phone' => 'private'])]);
        StarLog::withHttpClientLogging(function (): void {
            StarLog::withoutHttpClientSensitiveFields(['phone'], function (): void {
                Http::post('https://example.test/visible', ['phone' => 'private']);
                StarLog::withHttpClientSensitiveFields(['phone'], fn () => Http::post('https://example.test/masked', ['phone' => 'private']));
                Http::post('https://example.test/visible-again', ['phone' => 'private']);
            });
            Http::post('https://example.test/default', ['phone' => 'private']);
        });

        Log::shouldHaveReceived('info')->withArgs(static fn ($message, $context): bool => ($context['body']['data']['phone'] ?? null) === 'private')->times(4);
        Log::shouldHaveReceived('info')->withArgs(static fn ($message, $context): bool => ($context['body']['data']['phone'] ?? null) === '******')->times(4);
    }

    public function test_returned_async_promises_keep_independent_options_after_their_scopes_finish(): void
    {
        $pending = ['visible' => new Promise, 'masked' => new Promise];
        $started = 0;
        $handler = static function ($request) use ($pending, &$started) {
            $started++;

            return $pending[trim($request->getUri()->getPath(), '/')];
        };
        $visible = StarLog::withHttpClientLogging(fn () => StarLog::withoutHttpClientSensitiveFields(
            ['phone'],
            fn () => ['result' => Http::async()->setHandler($handler)->get('https://example.test/visible')],
        ));
        $masked = StarLog::withHttpClientLogging(fn () => Http::async()->setHandler($handler)->get('https://example.test/masked'));

        $this->assertSame(['result'], array_keys($visible));
        $this->assertSame(2, $started);
        $this->assertFalse(StarLog::getConfig('http_client.enable'));

        foreach ($pending as $promise) {
            $promise->resolve(new Response(200, ['Content-Type' => 'application/json'], '{"phone":"private"}'));
        }
        $this->assertTrue($masked->wait()->successful());
        $this->assertTrue($visible['result']->wait()->successful());
        Log::shouldHaveReceived('info')->withArgs(static fn ($message, $context): bool => str_contains($message, '/visible] - Response:')
            && $context['body']['data']['phone'] === 'private')->once();
        Log::shouldHaveReceived('info')->withArgs(static fn ($message, $context): bool => str_contains($message, '/masked] - Response:')
            && $context['body']['data']['phone'] === '******')->once();
    }

    public function test_async_retry_keeps_the_original_snapshot_after_the_scope_finishes(): void
    {
        $pending = new Promise;
        $attempts = (object) ['count' => 0];
        $promise = StarLog::withHttpClientLogging(fn () => StarLog::withoutHttpClientSensitiveFields(
            ['phone'],
            fn () => Http::async()->retry(2, 0)->setHandler(static function () use ($attempts, $pending) {
                return ++$attempts->count === 1 ? $pending : Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], '{"phone":"private"}'));
            })->get('https://example.test/retry'),
        ));

        $this->assertSame(1, $attempts->count);
        $this->assertFalse(StarLog::getConfig('http_client.enable'));
        $pending->resolve(new Response(500, ['Content-Type' => 'application/json'], '{"phone":"private"}'));
        $this->assertTrue($promise->wait()->successful());
        $this->assertSame(2, $attempts->count);
        Log::shouldHaveReceived('info')->with('GET[https://example.test/retry] - Request:', ['body' => ['type' => 'empty']])->twice();
        Log::shouldHaveReceived('info')->withArgs(static fn ($message, $context): bool => str_ends_with($message, ' - Response:')
            && $context['body']['data']['phone'] === 'private')->twice();
    }

    public function test_async_connection_failure_uses_snapshot_masking_and_can_be_disabled_separately(): void
    {
        $promise = StarLog::withHttpClientLogging(fn () => StarLog::withoutHttpClientSensitiveFields(
            ['password'],
            fn () => Http::async()->setHandler(static fn ($request) => Create::rejectionFor(new ConnectException('transport secret', $request)))
                ->get('https://alice:visible@example.test/failure'),
        ));
        $this->assertInstanceOf(ConnectionException::class, $promise->wait());
        Log::shouldHaveReceived('warning')->with('GET[https://******:visible@example.test/failure] - Connection failed.')->once();

        $disabled = StarLog::withHttpClientLogOptions([
            'enable' => true, 'connection_failure' => ['enable' => false],
        ], fn () => Http::async()->setHandler(static fn ($request) => Create::rejectionFor(new ConnectException('transport secret', $request)))
            ->get('https://example.test/disabled'));
        $this->assertInstanceOf(ConnectionException::class, $disabled->wait());
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_reused_pending_client_takes_new_options_for_each_send(): void
    {
        $client = Http::async()->setHandler(static fn () => Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], '{"phone":"private"}')));
        $first = StarLog::withHttpClientLogging(fn () => StarLog::withoutHttpClientSensitiveFields(
            ['phone'], fn () => $client->get('https://example.test/first'),
        ));
        $this->assertTrue($first->wait()->successful());
        $second = StarLog::withHttpClientLogging(fn () => $client->get('https://example.test/second'));
        $this->assertTrue($second->wait()->successful());
        Log::shouldHaveReceived('info')->withArgs(static fn ($message, $context): bool => str_contains($message, '/first] - Response:')
            && $context['body']['data']['phone'] === 'private')->once();
        Log::shouldHaveReceived('info')->withArgs(static fn ($message, $context): bool => str_contains($message, '/second] - Response:')
            && $context['body']['data']['phone'] === '******')->once();
    }

    #[DataProvider('concurrentMaskingScopes')]
    public function test_concurrent_sends_on_a_reused_client_log_their_own_request_and_options(bool $firstVisible): void
    {
        $pending = [];
        $client = Http::async()->setHandler(static function ($request) use (&$pending): Promise {
            return $pending[$request->getUri()->getPath()] = new Promise;
        });
        $send = static fn (string $path, bool $visible) => StarLog::withHttpClientLogging(
            fn () => $visible
                ? StarLog::withoutHttpClientSensitiveFields(['phone'], fn () => $client->get('https://example.test/' . $path))
                : $client->get('https://example.test/' . $path),
        );
        $first = $send('first', $firstVisible);
        $second = $send('second', ! $firstVisible);

        $pending['/second']->resolve(new Response(200, ['Content-Type' => 'application/json'], '{"phone":"second"}'));
        $this->assertTrue($second->wait()->successful());
        $pending['/first']->resolve(new Response(200, ['Content-Type' => 'application/json'], '{"phone":"first"}'));
        $this->assertTrue($first->wait()->successful());

        Log::shouldHaveReceived('info')->withArgs(static fn ($message, $context): bool => str_contains($message, '/first] - Response:')
            && $context['body']['data']['phone'] === ($firstVisible ? 'first' : '******'))->once();
        Log::shouldHaveReceived('info')->withArgs(static fn ($message, $context): bool => str_contains($message, '/second] - Response:')
            && $context['body']['data']['phone'] === ($firstVisible ? '******' : 'second'))->once();
        $this->assertFalse(StarLog::getConfig('http_client.enable'));
    }

    public function test_disabled_concurrent_send_stays_disabled_when_the_same_client_enables_logging(): void
    {
        $pending = [];
        $client = Http::async()->setHandler(static function ($request) use (&$pending): Promise {
            return $pending[$request->getUri()->getPath()] = new Promise;
        });
        $disabled = StarLog::withoutHttpClientLogging(fn () => $client->get('https://example.test/disabled'));
        $enabled = StarLog::withHttpClientLogging(fn () => $client->get('https://example.test/enabled'));

        foreach (['/disabled', '/enabled'] as $path) {
            $pending[$path]->resolve(new Response(200, ['Content-Type' => 'application/json'], '{"phone":"private"}'));
        }

        $this->assertTrue($disabled->wait()->successful());
        $this->assertTrue($enabled->wait()->successful());
        Log::shouldNotHaveReceived('info', static fn ($message): bool => str_contains($message, '/disabled'));
        Log::shouldHaveReceived('info')->withArgs(static fn ($message): bool => str_contains($message, '/enabled'))->twice();
    }

    public function test_reused_client_uses_the_new_snapshot_store_after_scoped_instances_are_reset(): void
    {
        $sentRequests = [];
        $client = Http::setHandler(static function ($request) use (&$sentRequests) {
            $sentRequests[] = $request;

            return Create::promiseFor(new Response(200));
        });
        $previous = $this->app->make(HttpClientLogSnapshots::class);
        $first = StarLog::withHttpClientLogging(fn () => $client->get('https://example.test/first'));
        $this->assertSame($sentRequests[0], $previous->requestForResponse($first->toPsrResponse()));

        $this->app->forgetScopedInstances();
        Facade::clearResolvedInstance(StarLogService::class);
        $current = $this->app->make(HttpClientLogSnapshots::class);
        $this->assertNotSame($previous, $current);

        $second = StarLog::withHttpClientLogging(fn () => $client->get('https://example.test/second'));
        $this->assertSame($sentRequests[1], $current->requestForResponse($second->toPsrResponse()));
        $this->assertNull($previous->requestForResponse($second->toPsrResponse()));
        Log::shouldHaveReceived('info')->withArgs(static fn ($message): bool => str_contains($message, '/second'))->twice();
    }

    public static function concurrentMaskingScopes(): array
    {
        return ['first visible' => [true], 'second visible' => [false]];
    }
}
