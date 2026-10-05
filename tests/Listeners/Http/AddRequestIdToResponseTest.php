<?php

namespace Caixingyue\LaravelStarLog\Tests\Listeners\Http;

use Caixingyue\LaravelStarLog\Http\Middleware\AssignRequestId;
use Caixingyue\LaravelStarLog\Listeners\Http\AddRequestIdToResponse;
use Caixingyue\LaravelStarLog\StarLog;
use Caixingyue\LaravelStarLog\Tests\TestCase;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class AddRequestIdToResponseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('starlog.route.request_id.response_header', 'Request-Id');
        $this->app->forgetScopedInstances();
        Facade::clearResolvedInstance(StarLog::class);
        Log::spy();
    }

    public function test_header_propagation_does_not_require_route_logging_or_write_a_log(): void
    {
        $request = Request::create('/');
        $request->attributes->set(AssignRequestId::REQUEST_ID_ATTRIBUTE, 123);
        $response = new Response('OK');

        (new AddRequestIdToResponse)->handle(new RequestHandled($request, $response));

        $this->assertSame('123', $response->headers->get('Request-Id'));
        $this->assertSame('OK', $response->getContent());
        Log::shouldNotHaveReceived('info');
    }

    public function test_missing_or_invalid_request_ids_do_not_replace_existing_headers(): void
    {
        foreach ([null, '123'] as $id) {
            $request = Request::create('/');
            $request->attributes->set(AssignRequestId::REQUEST_ID_ATTRIBUTE, $id);
            $response = new Response('OK', 200, ['Request-Id' => 'external']);

            (new AddRequestIdToResponse)->handle(new RequestHandled($request, $response));

            $this->assertSame('external', $response->headers->get('Request-Id'));
        }
    }
}
