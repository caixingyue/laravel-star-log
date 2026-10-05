<?php

namespace Caixingyue\LaravelStarLog\Tests;

use Caixingyue\LaravelStarLog\Http\Middleware\AssignRequestId;
use Caixingyue\LaravelStarLog\Http\Middleware\RouteLog;
use Caixingyue\LaravelStarLog\Providers\ConsoleServiceProvider;
use Caixingyue\LaravelStarLog\Providers\QueryServiceProvider;
use Caixingyue\LaravelStarLog\Providers\QueueServiceProvider;
use Caixingyue\LaravelStarLog\Query\QueryBindingColumnRegistry;
use Caixingyue\LaravelStarLog\Query\QueryLogState;
use Caixingyue\LaravelStarLog\StarLog;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class StarLogServiceProviderTest extends TestCase
{
    public function test_registers_the_core_and_query_logging_services(): void
    {
        $this->assertInstanceOf(StarLog::class, $this->app->make(StarLog::class));
        $this->assertInstanceOf(QueryLogState::class, $this->app->make(QueryLogState::class));
        $this->assertInstanceOf(QueryBindingColumnRegistry::class, $this->app->make(QueryBindingColumnRegistry::class));
        $this->assertInstanceOf(ConsoleServiceProvider::class, $this->app->getProvider(ConsoleServiceProvider::class));
        $this->assertInstanceOf(QueryServiceProvider::class, $this->app->getProvider(QueryServiceProvider::class));
        $this->assertInstanceOf(QueueServiceProvider::class, $this->app->getProvider(QueueServiceProvider::class));
    }

    public function test_registered_listeners_add_the_id_and_record_one_final_response(): void
    {
        config()->set('starlog.route.request_id.response_header', 'Request-Id');
        $this->app->forgetScopedInstances();
        Facade::clearResolvedInstance(StarLog::class);
        Log::spy();
        $request = Request::create('/orders');
        $request->attributes->set(AssignRequestId::REQUEST_ID_ATTRIBUTE, 345);
        $request->attributes->set(RouteLog::STATE_ATTRIBUTE, ['started_at' => hrtime(true)]);
        $response = new Response('OK');

        Event::dispatch(new RequestHandled($request, $response));

        $this->assertSame('345', $response->headers->get('Request-Id'));
        $this->assertSame('OK', $response->getContent());
        Log::shouldHaveReceived('info')->withArgs(
            static fn (string $message): bool => str_contains($message, '200[orders] - Response:')
        )->once();
    }
}
