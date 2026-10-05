<?php

namespace Caixingyue\LaravelStarLog\Listeners\Http;

use Caixingyue\LaravelStarLog\Facades\StarLog;
use Caixingyue\LaravelStarLog\Http\Middleware\RouteLog;
use Caixingyue\LaravelStarLog\Support\FileSize;
use Caixingyue\LaravelStarLog\Support\HttpContentType;
use Caixingyue\LaravelStarLog\Support\HttpLogDataNormalizer;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as LaravelResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Record the final response for requests selected by the route logging middleware.
 */
final class RequestHandledToLog
{
    /**
     * Log timing, memory usage, and configured response data for requests selected by RouteLog.
     */
    public function handle(RequestHandled $event): void
    {
        try {
            $request = $event->request;
            $response = $event->response;
            $state = $request->attributes->get(RouteLog::STATE_ATTRIBUTE);
            $startedAt = is_array($state) ? ($state['started_at'] ?? null) : null;

            if (! is_int($startedAt)) {
                return;
            }

            $duration = round((hrtime(true) - $startedAt) / 1_000_000_000, 2);
            $memory = memory_get_usage(true);

            $normalizer = HttpLogDataNormalizer::fromConfig(StarLog::getConfig('route', []));

            $message = StarLog::translate('route.response', [
                'duration' => $duration . 's',
                'memory' => FileSize::format($memory),
                'status' => $response->getStatusCode(),
                'path' => $request->path(),
            ]);

            $context = array_filter([
                'headers' => $normalizer->prepareHeaders($response->headers->all(), StarLog::getConfig('route.response.headers', [])),
                'body' => $this->describeResponseBody($response, $normalizer),
            ], static fn (mixed $value): bool => $value !== null);

            Log::info($message, $context);
        } catch (Throwable) {
            // Logging must not replace Laravel's final application response.
        }
    }

    /**
     * Build a safe response body description.
     */
    private function describeResponseBody(Response $response, HttpLogDataNormalizer $normalizer): ?array
    {
        if (StarLog::getConfig('route.response.body', false) !== true) {
            return null;
        }

        $contentType = $response->headers->get('Content-Type');

        if ($response instanceof BinaryFileResponse || HttpContentType::isBinaryContentType(HttpContentType::normalizeContentType($contentType ?? ''))) {
            return $normalizer->describeBody('binary', $contentType);
        }

        if ($response instanceof StreamedResponse) {
            return $normalizer->describeBody('stream', $contentType);
        }

        if ($response instanceof LaravelResponse && $response->original instanceof View) {
            $details = [
                'name' => $response->original->getName(),
                'path' => $response->original->getPath(),
            ];

            if (StarLog::getConfig('route.response.view_data', false) === true) {
                $details['data'] = $normalizer->prepare($response->original->getData());
            }

            return $normalizer->describeBody('view', $contentType, $details);
        }

        $content = $response->getContent();

        if (! is_string($content) || $content === '') {
            return $normalizer->describeBody('empty');
        }

        $lowerContentType = strtolower((string) $contentType);

        if ($response instanceof JsonResponse || str_contains($lowerContentType, 'json')) {
            return $normalizer->describeJsonBody($content, $contentType);
        }

        if (str_contains($lowerContentType, 'html')) {
            return $normalizer->describeHtmlBody($content, $contentType);
        }

        return $normalizer->describeTextBody($content, $contentType);
    }
}
