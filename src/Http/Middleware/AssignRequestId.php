<?php

namespace Caixingyue\LaravelStarLog\Http\Middleware;

use Caixingyue\LaravelStarLog\Facades\StarLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Generate and return a request ID
 */
class AssignRequestId
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = StarLog::appendRequestId();

        $response = $next($request);

        if (StarLog::getConfig('route.response_head_id', false)) {
            $response->headers->set('Request-Id', $requestId);
        }

        return $response;
    }
}
