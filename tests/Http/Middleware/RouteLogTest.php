<?php

namespace Caixingyue\LaravelStarLog\Tests\Http\Middleware;

use Caixingyue\LaravelStarLog\Http\Middleware\RouteLog;
use Caixingyue\LaravelStarLog\Listeners\Http\RequestHandledToLog;
use Caixingyue\LaravelStarLog\Support\Redactor;
use Caixingyue\LaravelStarLog\Tests\TestCase;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;
use Symfony\Component\HttpFoundation\Response;

final class RouteLogTest extends TestCase
{
    public function test_text_request_prefixes_are_logged_and_complete_bodies_remain_readable(): void
    {
        config()->set('starlog.route.limits.max_body_length', 8);
        $contents = str_repeat('x', 128);
        $resource = fopen('php://temp', 'r+');
        fwrite($resource, $contents);
        rewind($resource);
        $request = new class([], [], [], [], [], ['CONTENT_TYPE' => 'text/plain'], $resource) extends Request
        {
            public int $unboundedReads = 0;

            public function getContent(bool $asResource = false): mixed
            {
                if (! $asResource) {
                    $this->unboundedReads++;
                }

                return parent::getContent($asResource);
            }
        };

        try {
            (new RouteLog)->handle($request, fn (): Response => new Response('OK'));

            $this->assertSame(0, $request->unboundedReads);
            Log::shouldHaveReceived('info')->withArgs(static fn ($message, $context): bool => $context['body'] === [
                'type' => 'text', 'content_type' => 'text/plain', 'data' => 'xxxxxxxx…',
            ])->once();
            $this->assertSame($contents, $request->getContent());
        } finally {
            fclose($resource);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('starlog.route', [
            'ignore' => [
                'paths' => [],
                'methods' => [],
                'route_names' => [],
            ],
            'sensitive_fields' => ['password', 'token', 'authorization'],
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

    public function test_formats_terminal_and_ip_together_in_the_request_message(): void
    {
        $this->assertSame(
            '[Macintosh|OS X|desktop] @ 127.0.0.1 - GET[orders] - Request:',
            __('star-log::star-log.route.request', [
                'terminal' => 'Macintosh|OS X|desktop',
                'ip' => '127.0.0.1',
                'method' => 'GET',
                'path' => 'orders',
            ], 'en')
        );

        $this->assertSame(
            '[Macintosh|OS X|desktop] @ 127.0.0.1 - GET[orders] - 请求报文:',
            __('star-log::star-log.route.request', [
                'terminal' => 'Macintosh|OS X|desktop',
                'ip' => '127.0.0.1',
                'method' => 'GET',
                'path' => 'orders',
            ], 'zh_CN')
        );
    }

    public function test_records_a_redacted_json_request_body(): void
    {
        config()->set('starlog.route.sensitive_fields', ['profile.token']);
        Log::spy();
        $request = Request::create(
            '/json',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{"profile":{"token":"request-secret"}}'
        );
        $response = new JsonResponse(['ok' => true]);
        $middleware = new RouteLog;

        $middleware->handle($request, fn (): JsonResponse => $response);

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context): bool {
            return str_ends_with($message, 'POST[json] - Request:')
                && $context['body']['type'] === 'json'
                && $context['body']['content_type'] === 'application/json'
                && $context['body']['data']['profile']['token'] === Redactor::MASK;
        })->once();
    }

    public function test_recognizes_json_request_media_types_independently_of_case(): void
    {
        $request = Request::create('/json', 'POST', [], [], [], ['CONTENT_TYPE' => 'Application/JSON; charset=UTF-8'], '{"password":"secret"}');

        (new RouteLog)->handle($request, fn (): JsonResponse => new JsonResponse(['ok' => true]));

        Log::shouldHaveReceived('info')->withArgs(static fn ($message, $context): bool => $context['body'] === [
            'type' => 'json', 'content_type' => 'application/json', 'data' => ['password' => Redactor::MASK],
        ])->once();
    }

    public function test_omits_binary_request_contents(): void
    {
        $request = Request::create('/upload', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], "\x00private bytes");

        (new RouteLog)->handle($request, fn (): Response => new Response('OK'));

        Log::shouldHaveReceived('info')->withArgs(static fn ($message, $context): bool => $context['body'] === [
            'type' => 'binary', 'content_type' => 'application/octet-stream',
        ])->once();
    }

    public function test_uses_the_current_request_user_agent_in_the_terminal_description(): void
    {
        $request = Request::create('/agent', 'GET', [], [], [], [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/100.0.0.0 Safari/537.36',
        ]);

        (new RouteLog)->handle($request, fn (): Response => new Response('OK'));

        Log::shouldHaveReceived('info')->withArgs(static fn ($message, $context): bool => str_starts_with($message, '[Macintosh|OS X|desktop]'))->once();
    }

    public function test_does_not_record_an_ignored_path(): void
    {
        config()->set('starlog.route.ignore.paths', ['health']);
        Log::spy();
        $request = Request::create('/health', 'GET');
        $response = new JsonResponse(['ok' => true]);
        $middleware = new RouteLog;

        $middleware->handle($request, fn (): JsonResponse => $response);
        (new RequestHandledToLog)->handle(new RequestHandled($request, $response));

        Log::shouldNotHaveReceived('info');
    }

    public function test_does_not_record_an_ignored_named_route_before_route_middleware_runs(): void
    {
        config()->set('starlog.route.ignore.route_names', ['route-log.named-ignored']);
        Route::get('/route-log-named-ignored', fn (): JsonResponse => response()->json(['ignored' => true]))
            ->name('route-log.named-ignored');
        Log::spy();

        (new RouteLog)->handle(Request::create('/route-log-named-ignored', 'GET'), fn (): JsonResponse => response()->json(['ignored' => true]));

        Log::shouldNotHaveReceived('info');
    }

    public function test_records_uploaded_file_metadata_in_its_original_request_field(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'star-log-');
        file_put_contents($path, 'file content');

        try {
            Log::spy();
            $request = Request::create('/avatar', 'POST', [], [], [
                'avatar' => new SymfonyUploadedFile($path, 'avatar.png', 'image/png', null, true),
            ]);
            $response = new JsonResponse(['ok' => true]);

            (new RouteLog)->handle($request, fn (): JsonResponse => $response);

            Log::shouldHaveReceived('info')->once()->withArgs(static function (string $message, array $context): bool {
                return str_ends_with($message, 'POST[avatar] - Request:')
                    && $context['body']['type'] === 'multipart'
                    && $context['body']['data']['avatar'][UploadedFile::class] === [
                        'name' => 'avatar.png',
                        'extension' => 'png',
                        'mime' => 'image/png',
                        'size' => '12B',
                    ];
            });
        } finally {
            unlink($path);
        }
    }
}
