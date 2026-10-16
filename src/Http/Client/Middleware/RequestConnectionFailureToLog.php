<?php

namespace Caixingyue\LaravelStarLog\Http\Client\Middleware;

use Caixingyue\LaravelStarLog\Facades\StarLog;
use Caixingyue\LaravelStarLog\Http\Client\HttpClientLogSnapshots;
use Caixingyue\LaravelStarLog\Support\HttpLogDataNormalizer;
use Closure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\TransferStats;
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
            $errorNumber = null;
            $onStats = $options['on_stats'] ?? null;
            $options['on_stats'] = static function (TransferStats $stats) use (&$errorNumber, $onStats): void {
                $error = $stats->getHandlerErrorData();
                $errorNumber = is_int($error) ? $error : null;

                if ($onStats !== null) {
                    $onStats($stats);
                }
            };

            try {
                $promise = $handler($request, $options);
            } catch (Throwable $exception) {
                $this->write($request, $exception, $config, $errorNumber);

                throw $exception;
            }

            return $promise->otherwise(function (Throwable $exception) use ($request, $config, &$errorNumber): never {
                $this->write($request, $exception, $config, $errorNumber);

                throw $exception;
            });
        };
    }

    /**
     * Write one safe connection failure log entry.
     */
    private function write(RequestInterface $request, Throwable $exception, array $config, ?int $errorNumber): void
    {
        try {
            if (! ($exception instanceof ConnectException || $exception instanceof NetworkException || $exception instanceof RequestException)) {
                return;
            }

            // Guzzle 7 exposes hasResponse(); Guzzle 8 uses response-specific exceptions.
            if ((method_exists($exception, 'hasResponse') && $exception->hasResponse())
                || (method_exists($exception, 'getResponse') && $exception->getResponse() !== null)) {
                return;
            }

            if (($config['enable'] ?? false) !== true || ($config['connection_failure']['enable'] ?? true) !== true) {
                return;
            }

            $normalizer = HttpLogDataNormalizer::fromConfig($config);

            Log::warning(StarLog::translate('client.connection_failed', [
                'method' => $request->getMethod(),
                'url' => $normalizer->prepareUrl((string) $request->getUri(), ($config['request']['query'] ?? true) === true),
                'reason' => $this->failureReason($exception, $errorNumber),
            ]));
        } catch (Throwable) {
            // Logging must never affect the failed HTTP request.
        }
    }

    /**
     * Describe a transport failure without exposing its raw error details.
     */
    private function failureReason(Throwable $exception, ?int $errorNumber): string
    {
        // Guzzle 8 reports transport metadata through on_stats rather than exceptions.
        $errorNumber ??= method_exists($exception, 'getHandlerContext')
            ? ($exception->getHandlerContext()['errno'] ?? null)
            : null;

        if ($exception instanceof NetworkTimeoutException || $exception instanceof ConnectTimeoutException) {
            $errorNumber = 28;
        }

        return match ($errorNumber) {
            5, 6 => StarLog::translate('client.connection_failure_reasons.name_resolution'),
            28 => StarLog::translate('client.connection_failure_reasons.timeout'),
            35, 51, 53, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91 => StarLog::translate('client.connection_failure_reasons.tls'),
            default => '',
        };
    }
}
