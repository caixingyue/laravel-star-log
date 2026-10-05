<?php

namespace Caixingyue\LaravelStarLog\Http\Client\Middleware;

use Caixingyue\LaravelStarLog\Http\Client\HttpClientLogSnapshot;
use Caixingyue\LaravelStarLog\Http\Client\HttpClientLogSnapshots;
use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Retain each sent request's log snapshot and associate its response.
 */
final class CaptureHttpClientLogSnapshot
{
    /**
     * Make the request's snapshot available to request, response, and failure logging.
     */
    public function __invoke(callable $handler): Closure
    {
        return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            $snapshots = app(HttpClientLogSnapshots::class);
            $snapshot = $options[HttpClientLogSnapshot::OPTION] ?? null;
            $snapshots->forRequest($request, $snapshot instanceof HttpClientLogSnapshot ? $snapshot : null);

            return $handler($request, $options)->then(function (ResponseInterface $response) use ($request, $snapshots): ResponseInterface {
                $snapshots->rememberResponse($request, $response);

                return $response;
            });
        };
    }
}
