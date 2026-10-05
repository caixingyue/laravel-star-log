<?php

namespace Caixingyue\LaravelStarLog\Tests\Integration\Http;

use Caixingyue\LaravelStarLog\Http\Middleware\RouteLog;
use Caixingyue\LaravelStarLog\Support\Redactor;
use Caixingyue\LaravelStarLog\Tests\TestCase;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

final class RouteLoggingIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('starlog.route', [
            'ignore' => [
                'paths' => [],
                'methods' => [],
                'route_names' => [],
            ],
            'sensitive_fields' => ['password', 'profile.password', 'token', 'authorization'],
            'request' => [
                'query' => true,
                'body' => true,
            ],
            'response' => [
                'body' => true,
                'view_data' => false,
            ],
            'limits' => [
                'max_string_length' => 12,
                'max_body_length' => 16,
                'max_array_items' => 3,
                'max_depth' => 4,
            ],
        ]);
        Log::spy();
    }

    public function test_logging_backend_failures_do_not_replace_the_application_response(): void
    {
        Log::shouldReceive('info')->andThrow(new \RuntimeException('log backend failed'));
        $request = Request::create('/orders');
        $response = new JsonResponse(['ok' => true]);

        $this->assertSame($response, (new RouteLog)->handle($request, fn () => $response));
        Event::dispatch(new RequestHandled($request, $response));
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_request_and_response_messages_preserve_the_decoded_path(): void
    {
        $request = Request::create('/orders%0aexport');
        $response = new JsonResponse(['ok' => true]);

        (new RouteLog)->handle($request, fn () => $response);
        Event::dispatch(new RequestHandled($request, $response));

        Log::shouldHaveReceived('info')->withArgs(static fn ($message): bool => str_contains($message, "orders\nexport"))->twice();
    }

    public function test_records_redacted_request_and_json_response_data(): void
    {
        Log::spy();
        $request = Request::create('/orders?token=query-secret', 'POST', [
            'profile' => [
                'password' => 'request-secret',
                'name' => 'A very long customer name',
            ],
        ]);
        $response = new JsonResponse([
            'token' => 'response-secret',
            'message' => 'A very long response message',
        ]);
        $route = Route::post('/orders', static fn (): JsonResponse => $response)->name('orders.store');
        $request->setRouteResolver(static fn () => $route);
        $middleware = new RouteLog;
        $middleware->handle($request, fn (): JsonResponse => $response);
        Event::dispatch(new RequestHandled($request, $response));

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context): bool {
            return str_ends_with($message, 'POST[orders] - Request:')
                && $context['route_name'] === 'orders.store'
                && $context['query']['token'] === Redactor::MASK
                && $context['body']['type'] === 'form'
                && $context['body']['data']['profile']['password'] === Redactor::MASK
                && $context['body']['data']['profile']['name'] === 'A very long…';
        })->once();

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context): bool {
            return str_contains($message, '200[orders] - Response:')
                && ! array_key_exists('route_name', $context)
            && $context['body']['type'] === 'json'
            && $context['body']['data']['token'] === Redactor::MASK
            && $context['body']['data']['message'] === 'A very long…';
        })->once();
    }

    public function test_plain_text_requests_and_responses_are_recorded(): void
    {
        foreach (['/health', '/private'] as $path) {
            $request = Request::create($path, 'POST', server: ['CONTENT_TYPE' => 'text/plain'], content: 'PING');
            $response = new Response('OK', headers: ['Content-Type' => 'text/plain']);
            (new RouteLog)->handle($request, fn () => $response);
            Event::dispatch(new RequestHandled($request, $response));
        }

        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => str_contains($message, '[health]')
            && in_array($context['body']['data'] ?? null, ['PING', 'OK'], true))->twice();
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => str_contains($message, '[private]')
            && in_array($context['body']['data'] ?? null, ['PING', 'OK'], true))->twice();
    }

    public function test_sensitive_fields_select_private_paths_in_query_body_and_response(): void
    {
        config()->set('starlog.route.sensitive_fields', ['user.token']);
        $data = ['pagination' => ['token' => 'next'], 'user' => ['token' => 'secret']];
        $request = Request::create('/orders?pagination[token]=next&user[token]=secret', 'POST', $data);
        $response = new JsonResponse($data);
        (new RouteLog)->handle($request, fn () => $response);
        Event::dispatch(new RequestHandled($request, $response));

        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $context['body']['data']['pagination']['token'] === 'next'
            && $context['body']['data']['user']['token'] === Redactor::MASK)->twice();
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => isset($context['query'])
            && $context['query']['pagination']['token'] === 'next' && $context['query']['user']['token'] === Redactor::MASK)->once();
    }

    public function test_existing_body_switches_omit_request_and_response_contents(): void
    {
        config()->set('starlog.route.request.body', false);
        config()->set('starlog.route.response.body', false);
        $request = Request::create('/orders', 'POST', server: ['CONTENT_TYPE' => 'text/plain'], content: 'request contents');
        $response = new Response('response contents', headers: ['Content-Type' => 'text/plain']);
        (new RouteLog)->handle($request, fn () => $response);
        Event::dispatch(new RequestHandled($request, $response));

        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => ! array_key_exists('body', $context))->twice();
    }
}
