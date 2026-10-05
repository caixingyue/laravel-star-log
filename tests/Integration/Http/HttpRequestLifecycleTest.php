<?php

namespace Caixingyue\LaravelStarLog\Tests\Integration\Http;

use Caixingyue\LaravelStarLog\Formatters\StarLogFormatter;
use Caixingyue\LaravelStarLog\Http\Middleware\AssignRequestId;
use Caixingyue\LaravelStarLog\Http\Middleware\RouteLog;
use Caixingyue\LaravelStarLog\StarLog;
use Caixingyue\LaravelStarLog\Tests\TestCase;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Cookie;

final class HttpRequestLifecycleTest extends TestCase
{
    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();

        $path = tempnam(sys_get_temp_dir(), 'star-log-http-');
        $this->assertIsString($path);
        $this->logPath = $path;

        config()->set([
            'app.debug' => false,
            'starlog.locale' => 'en',
            'starlog.route.request_id.response_header' => 'Request-Id',
            'starlog.route.request_id.share_log_context' => true,
            'logging.default' => 'http-lifecycle',
            'logging.channels.http-lifecycle' => [
                'driver' => 'single',
                'path' => $this->logPath,
                'formatter' => StarLogFormatter::class,
                'formatter_with' => ['format' => StarLogFormatter::SIMPLE_FORMAT],
            ],
        ]);
        $this->app->forgetScopedInstances();
        Facade::clearResolvedInstance(StarLog::class);
        $this->app->make(Kernel::class)->pushMiddleware(AssignRequestId::class);
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->logPath)) {
                Log::channel('http-lifecycle')->getLogger()->close();
                unlink($this->logPath);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_normal_responses_and_application_logs_share_the_generated_request_id(): void
    {
        $this->app->make(Kernel::class)->pushMiddleware(RouteLog::class);
        Route::get('/orders', static function (Request $request): JsonResponse {
            Log::info('Order created');

            return new JsonResponse([
                'request_id' => $request->attributes->get(AssignRequestId::REQUEST_ID_ATTRIBUTE),
                'token' => 'response-secret',
            ], 201);
        });

        $response = $this->getJson('/orders');

        $response->assertCreated()->assertJson(['token' => 'response-secret']);
        $requestId = (string) $response->json('request_id');
        $this->assertMatchesRegularExpression('/^\d{13}$/', $requestId);
        $response->assertHeader('Request-Id', $requestId);
        $output = file_get_contents($this->logPath);
        $this->assertSame(3, substr_count($output, "\n"));
        $this->assertSame(3, substr_count($output, "[request_id={$requestId}]"));
        $this->assertMatchesRegularExpression('/\[System@request:\d+\]: .*GET\[orders\] - Request:/', $output);
        $this->assertMatchesRegularExpression('/\[System@response:\d+\]: .*201\[orders\] - Response:/', $output);
        $this->assertStringContainsString('Order created', $output);
        $this->assertStringContainsString('"token":"******"', $output);
        $this->assertStringNotContainsString('response-secret', $output);
        $this->assertStringNotContainsString('"headers":', $output);
    }

    public function test_exception_responses_receive_the_request_id_and_are_logged_once(): void
    {
        config()->set('starlog.route.response.headers', ['request-id', 'CONTENT-TYPE']);
        $this->app->make(Kernel::class)->pushMiddleware(RouteLog::class);
        Route::get('/rejected', static fn () => abort(422, 'Order rejected'));

        $response = $this->getJson('/rejected');

        $response->assertUnprocessable()->assertJson(['message' => 'Order rejected']);
        $requestId = $response->headers->get('Request-Id');
        $this->assertIsString($requestId);
        $this->assertMatchesRegularExpression('/^\d{13}$/', $requestId);
        $output = file_get_contents($this->logPath);
        $this->assertSame(2, substr_count($output, "\n"));
        $this->assertSame(2, substr_count($output, "[request_id={$requestId}]"));
        $this->assertSame(1, substr_count($output, ' - Response:'));
        $this->assertStringContainsString('"request-id":["' . $requestId . '"]', $output);
        $this->assertStringContainsString('"content-type":["application/json"]', $output);
        $this->assertMatchesRegularExpression('/\[System@response:\d+\]: .*422\[rejected\] - Response:/', $output);
    }

    public function test_route_headers_are_selected_and_masked_with_bodies_disabled(): void
    {
        config()->set([
            'starlog.route.sensitive_fields' => ['authorization', 'cookie', 'set-cookie'],
            'starlog.route.request.headers' => ['x-request', 'AUTHORIZATION', 'cookie'],
            'starlog.route.response.headers' => ['x-response', 'SET-COOKIE', 'Request-Id'],
            'starlog.route.request.body' => false,
            'starlog.route.response.body' => false,
        ]);
        $this->app->make(Kernel::class)->pushMiddleware(RouteLog::class);
        Route::get('/headers', static function (Request $request): JsonResponse {
            $response = new JsonResponse([
                'authorization' => $request->header('Authorization'),
                'cookie' => $request->header('Cookie'),
            ], headers: ['X-Response' => ['first', 'second'], 'X-Unlisted' => 'private response']);
            $response->headers->setCookie(new Cookie('session', 'private cookie'));

            return $response;
        });

        $response = $this->getJson('/headers', [
            'X-Request' => 'request', 'Authorization' => 'Bearer private credential',
            'Cookie' => 'session=private', 'X-Unlisted' => 'private request',
        ]);

        $response->assertOk()->assertJson([
            'authorization' => 'Bearer private credential', 'cookie' => 'session=private',
        ]);
        $this->assertSame(['first', 'second'], $response->headers->all('X-Response'));
        $this->assertSame('private cookie', $response->headers->getCookies()[0]->getValue());
        $response->assertHeader('X-Unlisted', 'private response');
        $output = file_get_contents($this->logPath);
        $this->assertSame(2, substr_count($output, "\n"));
        $this->assertStringContainsString('"x-request":["request"]', $output);
        $this->assertStringContainsString('"authorization":"******"', $output);
        $this->assertStringContainsString('"cookie":"******"', $output);
        $this->assertStringContainsString('"x-response":["first","second"]', $output);
        $this->assertStringContainsString('"set-cookie":"******"', $output);
        $this->assertStringContainsString('"request-id":["' . $response->headers->get('Request-Id') . '"]', $output);
        $this->assertStringNotContainsString('private', $output);
        $this->assertStringNotContainsString('"body":', $output);
    }

    public function test_ignored_requests_keep_the_request_id_header_without_route_logs(): void
    {
        config()->set('starlog.route.ignore.paths', ['health']);
        config()->set('starlog.route.request.headers', ['content-type']);
        config()->set('starlog.route.response.headers', ['Request-Id']);
        $this->app->make(Kernel::class)->pushMiddleware(RouteLog::class);
        Route::get('/health', static fn (Request $request): JsonResponse => new JsonResponse([
            'request_id' => $request->attributes->get(AssignRequestId::REQUEST_ID_ATTRIBUTE),
        ]));

        $response = $this->getJson('/health');

        $response->assertOk();
        $requestId = (string) $response->json('request_id');
        $this->assertMatchesRegularExpression('/^\d{13}$/', $requestId);
        $response->assertHeader('Request-Id', $requestId);
        $this->assertSame('', file_get_contents($this->logPath));
    }

    public function test_request_id_middleware_alone_correlates_application_logs_and_adds_the_header(): void
    {
        Route::get('/correlation', static function (Request $request): JsonResponse {
            Log::info('Application request');

            return new JsonResponse([
                'request_id' => $request->attributes->get(AssignRequestId::REQUEST_ID_ATTRIBUTE),
            ]);
        });

        $response = $this->getJson('/correlation');

        $response->assertOk();
        $requestId = (string) $response->json('request_id');
        $this->assertMatchesRegularExpression('/^\d{13}$/', $requestId);
        $response->assertHeader('Request-Id', $requestId);
        $output = file_get_contents($this->logPath);
        $this->assertSame(1, substr_count($output, "\n"));
        $this->assertStringContainsString("[request_id={$requestId}]", $output);
        $this->assertStringContainsString('Application request', $output);
        $this->assertStringNotContainsString(' - Request:', $output);
        $this->assertStringNotContainsString(' - Response:', $output);
    }
}
