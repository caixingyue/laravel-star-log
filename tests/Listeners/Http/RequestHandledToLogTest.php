<?php

namespace Caixingyue\LaravelStarLog\Tests\Listeners\Http;

use Caixingyue\LaravelStarLog\Http\Middleware\AssignRequestId;
use Caixingyue\LaravelStarLog\Http\Middleware\RouteLog;
use Caixingyue\LaravelStarLog\Listeners\Http\RequestHandledToLog;
use Caixingyue\LaravelStarLog\StarLog;
use Caixingyue\LaravelStarLog\Tests\TestCase;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Http\Response as LaravelResponse;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class RequestHandledToLogTest extends TestCase
{
    public function test_responses_without_a_route_logging_marker_are_not_recorded(): void
    {
        (new RequestHandledToLog)->handle(new RequestHandled(Request::create('/'), new Response('OK')));

        Log::shouldNotHaveReceived('info');
    }

    public function test_response_logging_does_not_add_the_request_id_header(): void
    {
        config()->set('starlog.route.request_id.response_header', 'Request-Id');
        $this->app->forgetScopedInstances();
        Facade::clearResolvedInstance(StarLog::class);
        $request = Request::create('/orders');
        $request->attributes->set(AssignRequestId::REQUEST_ID_ATTRIBUTE, 345);
        $request->attributes->set(RouteLog::STATE_ATTRIBUTE, ['started_at' => hrtime(true)]);
        $response = new Response('OK');

        (new RequestHandledToLog)->handle(new RequestHandled($request, $response));

        $this->assertNull($response->headers->get('Request-Id'));
        Log::shouldHaveReceived('info')->once();
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

    public function test_records_streamed_responses_without_reading_their_content(): void
    {
        Log::spy();
        $request = Request::create('/stream', 'GET');
        $response = new StreamedResponse(static fn () => print 'stream');
        $request->attributes->set(RouteLog::STATE_ATTRIBUTE, ['started_at' => hrtime(true)]);
        (new RequestHandledToLog)->handle(new RequestHandled($request, $response));

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context): bool {
            return str_contains($message, '200[stream] - Response:')
                && $context['body']['type'] === 'stream';
        })->once();
    }

    public function test_records_empty_responses_as_an_explicit_empty_body(): void
    {
        $this->recordResponse(new LaravelResponse('', 204));

        Log::shouldHaveReceived('info')->withArgs(static function (string $message, array $context): bool {
            return str_contains($message, '204[response] - Response:')
                && $context['body'] === ['type' => 'empty'];
        })->once();
    }

    public function test_records_html_responses_by_type_and_length_without_recording_the_markup(): void
    {
        $content = '<html><head><title>Page</title></head><body>secret</body></html>';
        $response = new LaravelResponse($content, 200, ['Content-Type' => 'text/html']);

        $this->recordResponse($response);

        Log::shouldHaveReceived('info')->withArgs(static function (string $message, array $context) use ($content): bool {
            return str_contains($message, '200[response] - Response:')
                && $context['body'] === [
                    'type' => 'html',
                    'content_type' => 'text/html',
                    'length' => strlen($content),
                    'summary' => ['title' => 'Page'],
                ];
        })->once();
    }

    public function test_records_the_name_and_resolved_path_of_a_rendered_view(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'star-log-');
        rename($path, $path . '.blade.php');
        $path .= '.blade.php';
        file_put_contents($path, 'view content');

        try {
            $this->recordResponse(response($this->app['view']->file($path)));

            Log::shouldHaveReceived('info')->withArgs(static function (string $message, array $context) use ($path): bool {
                return str_contains($message, '200[response] - Response:')
                    && $context['body'] === [
                        'type' => 'view',
                        'content_type' => 'text/html',
                        'name' => $path,
                        'path' => $path,
                    ];
            })->once();
        } finally {
            unlink($path);
        }
    }

    public function test_records_binary_responses_without_reading_the_file_contents(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'star-log-');
        file_put_contents($path, 'binary content');

        try {
            $this->recordResponse(new BinaryFileResponse($path));

            Log::shouldHaveReceived('info')->withArgs(static function (string $message, array $context): bool {
                return str_contains($message, '200[response] - Response:')
                && $context['body']['type'] === 'binary';
            })->once();
        } finally {
            unlink($path);
        }
    }

    public function test_omits_binary_contents_in_regular_responses(): void
    {
        $this->recordResponse(new Response("\x00private bytes", 200, ['Content-Type' => 'application/octet-stream']));

        Log::shouldHaveReceived('info')->withArgs(static fn ($message, $context): bool => str_contains($message, '- Response:') && $context['body'] === [
            'type' => 'binary', 'content_type' => 'application/octet-stream',
        ])->once();
    }

    public function test_retains_invalid_json_response_as_text(): void
    {
        $response = new LaravelResponse('{invalid json', 200, ['Content-Type' => 'application/json']);

        $this->recordResponse($response);

        Log::shouldHaveReceived('info')->withArgs(static function (string $message, array $context): bool {
            return str_contains($message, '200[response] - Response:')
                && $context['body'] === [
                    'type' => 'text',
                    'content_type' => 'application/json',
                    'data' => '{invalid jso…',
                ];
        })->once();
    }

    private function recordResponse(Response $response): void
    {
        $request = Request::create('/response', 'GET');
        $response->prepare($request);
        $request->attributes->set(RouteLog::STATE_ATTRIBUTE, ['started_at' => hrtime(true)]);
        (new RequestHandledToLog)->handle(new RequestHandled($request, $response));
    }
}
