<?php

namespace Caixingyue\LaravelStarLog\Http\Middleware;

use Caixingyue\LaravelStarLog\Facades\StarLog;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OverflowException;
use RuntimeException;

/**
 * Start the correlation chain for an HTTP request.
 */
class AssignRequestId
{
    /**
     * The request attribute used to expose the generated ID to application code.
     */
    public const REQUEST_ID_ATTRIBUTE = 'requestId';

    /**
     * Handle an incoming request.
     *
     * @throws RuntimeException
     * @throws OverflowException
     * @throws LockTimeoutException
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $shareLogContext = StarLog::getConfig('route.request_id.share_log_context', true);

        if ($shareLogContext) {
            Log::shareContext(['request_id' => null]);
        }

        $requestId = StarLog::startRequestCorrelation();
        $request->attributes->set(self::REQUEST_ID_ATTRIBUTE, $requestId);

        if ($shareLogContext) {
            Log::shareContext(['request_id' => $requestId]);
        }

        return $next($request);
    }
}
