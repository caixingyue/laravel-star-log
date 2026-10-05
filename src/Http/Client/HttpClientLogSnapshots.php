<?php

namespace Caixingyue\LaravelStarLog\Http\Client;

use Caixingyue\LaravelStarLog\Facades\StarLog;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use WeakMap;

/**
 * Retain request log snapshots and associate responses with their originating requests.
 */
final readonly class HttpClientLogSnapshots
{
    /**
     * @var WeakMap<RequestInterface, HttpClientLogSnapshot>
     */
    private WeakMap $requests;

    /**
     * @var WeakMap<ResponseInterface, RequestInterface>
     */
    private WeakMap $responseRequests;

    /**
     * Initialize the request and response associations.
     */
    public function __construct()
    {
        $this->requests = new WeakMap;
        $this->responseRequests = new WeakMap;
    }

    /**
     * Capture the active log configuration, disabling logging if it cannot be read.
     */
    public function capture(): HttpClientLogSnapshot
    {
        try {
            return new HttpClientLogSnapshot(StarLog::getConfig('http_client', []));
        } catch (Throwable) {
            return new HttpClientLogSnapshot([]);
        }
    }

    /**
     * Reuse a request's snapshot, retaining the supplied snapshot or current configuration on first access.
     */
    public function forRequest(RequestInterface $request, ?HttpClientLogSnapshot $snapshot = null): HttpClientLogSnapshot
    {
        if (isset($this->requests[$request])) {
            return $this->requests[$request];
        }

        $snapshot ??= $this->capture();
        $this->requests->offsetSet($request, $snapshot);

        return $snapshot;
    }

    /**
     * Associate a response with the request that produced it.
     */
    public function rememberResponse(RequestInterface $request, ResponseInterface $response): void
    {
        $this->responseRequests->offsetSet($response, $request);
    }

    /**
     * Get the originating request, or null if no association was retained.
     */
    public function requestForResponse(ResponseInterface $response): ?RequestInterface
    {
        return $this->responseRequests[$response] ?? null;
    }
}
