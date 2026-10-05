<?php

namespace Caixingyue\LaravelStarLog\Http\Client;

use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Promises\LazyPromise;
use Illuminate\Http\Client\Response;

/**
 * Attach log options when get/post/send is called, before Laravel can defer or retry it.
 */
class LoggingPendingRequest extends PendingRequest
{
    /**
     * Capture the active log configuration before Laravel sends, defers, or retries the request.
     *
     * @throws ConnectionException
     * @throws Exception
     */
    public function send(string $method, string $url, array $options = []): Response|LazyPromise
    {
        $options[HttpClientLogSnapshot::OPTION] = app(HttpClientLogSnapshots::class)->capture();

        return parent::send($method, $url, $options);
    }
}
