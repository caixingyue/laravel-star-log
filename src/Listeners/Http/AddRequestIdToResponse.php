<?php

namespace Caixingyue\LaravelStarLog\Listeners\Http;

use Caixingyue\LaravelStarLog\Facades\StarLog;
use Caixingyue\LaravelStarLog\Http\Middleware\AssignRequestId;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Throwable;

/**
 * Add the generated request ID to Laravel's final response when configured.
 */
final class AddRequestIdToResponse
{
    /**
     * Copy the request's generated ID to the configured header on Laravel's final response.
     */
    public function handle(RequestHandled $event): void
    {
        try {
            $header = StarLog::getConfig('route.request_id.response_header');
            $requestId = $event->request->attributes->get(AssignRequestId::REQUEST_ID_ATTRIBUTE);

            if (is_string($header) && $header !== '' && is_int($requestId)) {
                $event->response->headers->set($header, (string) $requestId);
            }
        } catch (Throwable) {
            // Request ID propagation must not replace the application response.
        }
    }
}
