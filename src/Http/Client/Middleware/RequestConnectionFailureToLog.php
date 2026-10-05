<?php

namespace Caixingyue\LaravelStarLog\Http\Client\Middleware;

use Caixingyue\LaravelStarLog\Facades\StarLog;
use Caixingyue\LaravelStarLog\Http\Client\HttpClientLogSnapshots;
use Caixingyue\LaravelStarLog\Support\HttpLogDataNormalizer;
use Closure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use Throwable;

/**
 * Record HTTP client connection failures without a response.
 */
final class RequestConnectionFailureToLog
{
    /**
     * Record connection failures while preserving the original transport exception.
     */
    public function __invoke(callable $handler): Closure
    {
        return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            $config = app(HttpClientLogSnapshots::class)->forRequest($request)->config;

            try {
                $promise = $handler($request, $options);
            } catch (Throwable $exception) {
                $this->write($request, $exception, $config);

                throw $exception;
            }

            return $promise->otherwise(function (Throwable $exception) use ($request, $config): never {
                $this->write($request, $exception, $config);

                throw $exception;
            });
        };
    }

    /**
     * Write one safe connection failure log entry.
     */
    private function write(RequestInterface $request, Throwable $exception, array $config): void
    {
        try {
            if (! ($exception instanceof ConnectException || ($exception instanceof RequestException && ! $exception->hasResponse()))) {
                return;
            }

            if (($config['enable'] ?? false) !== true || ($config['connection_failure']['enable'] ?? true) !== true) {
                return;
            }

            $normalizer = HttpLogDataNormalizer::fromConfig($config);

            Log::warning(StarLog::translate('client.connection_failed', [
                'method' => $request->getMethod(),
                'url' => $normalizer->prepareUrl((string) $request->getUri(), ($config['request']['query'] ?? true) === true),
                'reason' => $this->failureReason($exception),
            ]));
        } catch (Throwable) {
            // Logging must never affect the failed HTTP request.
        }
    }

    /**
     * Describe a transport failure without exposing its raw error details.
     */
    private function failureReason(ConnectException|RequestException $exception): string
    {
        $errorNumber = $exception->getHandlerContext()['errno'] ?? null;

        return match ($errorNumber) {
            5, 6 => StarLog::translate('client.connection_failure_reasons.name_resolution'),
            28 => StarLog::translate('client.connection_failure_reasons.timeout'),
            35, 51, 53, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91 => StarLog::translate('client.connection_failure_reasons.tls'),
            default => '',
        };
    }
}
