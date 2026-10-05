<?php

namespace Caixingyue\LaravelStarLog\Listeners\HttpClient;

use Caixingyue\LaravelStarLog\Facades\StarLog;
use Caixingyue\LaravelStarLog\Http\Client\HttpClientLogSnapshots;
use Caixingyue\LaravelStarLog\Support\HttpContentType;
use Caixingyue\LaravelStarLog\Support\HttpLogDataNormalizer;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Record an HTTP client response.
 */
final class ResponseReceivedToLog
{
    /**
     * Record the response received by Laravel's HTTP client.
     */
    public function handle(ResponseReceived $event): void
    {
        try {
            $snapshots = app(HttpClientLogSnapshots::class);
            $request = $snapshots->requestForResponse($event->response->toPsrResponse()) ?? $event->request->toPsrRequest();
            $config = $snapshots->forRequest($request)->config;

            if (($config['enable'] ?? false) !== true || ($config['response']['enable'] ?? true) !== true) {
                return;
            }

            $response = $event->response;
            $normalizer = HttpLogDataNormalizer::fromConfig($config);

            $message = StarLog::translate('client.response', [
                'duration' => $this->formatDuration($response->transferStats?->getTransferTime()),
                'status' => $response->status(),
                'url' => $normalizer->prepareUrl((string) $request->getUri(), ($config['request']['query'] ?? true) === true),
            ]);

            $context = array_filter([
                'headers' => $normalizer->prepareHeaders($response->headers(), $config['response']['headers'] ?? []),
                'body' => ($config['response']['body'] ?? true) === true ? $this->describeResponseBody($response, $normalizer) : null,
            ], static fn (mixed $value): bool => $value !== null);

            Log::info($message, $context);
        } catch (Throwable) {
            // HTTP client logging must never affect the response being received.
        }
    }

    /**
     * Mark unavailable times and keep measured nonzero times from rounding to zero.
     */
    private function formatDuration(?float $duration): string
    {
        if ($duration === null) {
            return 'N/A';
        }

        $rounded = round($duration, 2);

        return ($rounded === 0.0 && $duration !== 0.0 ? $duration : $rounded) . 's';
    }

    /**
     * Build a safe body description for an HTTP client response.
     */
    private function describeResponseBody(Response $response, HttpLogDataNormalizer $normalizer): array
    {
        $contentType = HttpContentType::normalizeContentType($response->header('Content-Type'));

        if (HttpContentType::isBinaryContentType($contentType)) {
            return $normalizer->describeBody('binary', $contentType);
        }

        $body = $normalizer->readBody($response->toPsrResponse()->getBody(), $contentType);

        if ($body === null) {
            return $normalizer->describeBody('stream', $contentType);
        }

        if ($body === '') {
            return $normalizer->describeBody('empty');
        }

        if (Str::isJson($body)) {
            return $normalizer->describeJsonBody($body, $contentType);
        }

        if (str_contains($contentType ?? '', 'json')) {
            return $normalizer->describeJsonBody($body, $contentType);
        }

        if (in_array($contentType, ['text/html', 'application/xhtml+xml'], true)) {
            return $normalizer->describeHtmlBody($body, $contentType);
        }

        return HttpContentType::isTextContentType($contentType)
            ? $normalizer->describeTextBody($body, $contentType)
            : $normalizer->describeBody('unknown', $contentType);
    }
}
