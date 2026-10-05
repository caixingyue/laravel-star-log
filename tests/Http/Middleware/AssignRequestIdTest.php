<?php

namespace Caixingyue\LaravelStarLog\Tests\Http\Middleware;

use Caixingyue\LaravelStarLog\Facades\StarLog;
use Caixingyue\LaravelStarLog\Http\Middleware\AssignRequestId;
use Caixingyue\LaravelStarLog\Listeners\Http\AddRequestIdToResponse;
use Caixingyue\LaravelStarLog\StarLog as StarLogImplementation;
use Caixingyue\LaravelStarLog\Tests\Fixtures\Http\Middleware\HttpCorrelationCommand;
use Caixingyue\LaravelStarLog\Tests\Fixtures\Http\Middleware\HttpCorrelationJob;
use Caixingyue\LaravelStarLog\Tests\TestCase;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpFoundation\Response;

final class AssignRequestIdTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        HttpCorrelationCommand::$executions = [];
        HttpCorrelationJob::$executions = [];
        config()->set('queue.default', 'sync');

        /** @var \Illuminate\Foundation\Console\Kernel $kernel */
        $kernel = $this->app->make(Kernel::class);
        $kernel->registerCommand(new HttpCorrelationCommand);
    }

    public function test_request_id_links_the_http_request_command_and_queue_job(): void
    {
        config()->set('starlog.route.request_id.response_header', 'Request-Id');
        config()->set('starlog.route.request_id.share_log_context', true);
        $this->resetCorrelationScope();
        $request = Request::create('/correlation-test');

        $response = (new AssignRequestId)->handle($request, function (): Response {
            Artisan::call('star-log:http-correlation-test');

            return new Response('OK');
        });

        (new AddRequestIdToResponse)->handle(new RequestHandled($request, $response));

        $requestId = $request->attributes->get('requestId');
        [$beforeQueue, $afterQueue] = HttpCorrelationCommand::$executions;
        $job = HttpCorrelationJob::$executions[0];

        $this->assertIsInt($requestId);
        $this->assertSame((string) $requestId, $response->headers->get('Request-Id'));
        $this->assertSame($requestId, $this->app['log']->sharedContext()['request_id']);
        $this->assertSame($requestId, $beforeQueue['requestId']);
        $this->assertSame($beforeQueue, $afterQueue);
        $this->assertSame($requestId, $job['requestId']);
        $this->assertSame($beforeQueue['id'], $job['artisanId']);
        $this->assertSame($job['id'], $job['queueId']);
        $this->assertSame(['request', 'artisan', 'queue'], array_column($job['chain'], 'type'));
        $this->assertSame([
            ['id' => $requestId, 'name' => Request::class, 'type' => 'request'],
        ], StarLog::getCorrelationChain());
    }

    public function test_response_header_is_not_added_when_it_is_not_configured(): void
    {
        config()->set('starlog.route.request_id.response_header', null);
        $this->resetCorrelationScope();
        $request = Request::create('/correlation-test');

        $response = (new AssignRequestId)->handle($request, fn (): Response => new Response('OK'));

        (new AddRequestIdToResponse)->handle(new RequestHandled($request, $response));

        $this->assertIsInt($request->attributes->get('requestId'));
        $this->assertNull($response->headers->get('Request-Id'));
    }

    public function test_request_id_is_not_shared_with_laravel_log_context_when_disabled(): void
    {
        config()->set('starlog.route.request_id.share_log_context', false);
        $this->resetCorrelationScope();
        $request = Request::create('/correlation-test');

        (new AssignRequestId)->handle($request, fn (): Response => new Response('OK'));

        $this->assertIsInt($request->attributes->get('requestId'));
        $this->assertSame([], $this->app['log']->sharedContext());
    }

    private function resetCorrelationScope(): void
    {
        $this->app['log']->flushSharedContext();
        $this->app['log']->withoutContext();
        $this->app->forgetScopedInstances();
        Facade::clearResolvedInstance(StarLogImplementation::class);
    }
}
