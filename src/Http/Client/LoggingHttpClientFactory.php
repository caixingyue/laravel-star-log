<?php

namespace Caixingyue\LaravelStarLog\Http\Client;

use Illuminate\Http\Client\Factory;

/**
 * Create Laravel HTTP clients that retain per-request logging options.
 * Laravel's middleware, global options, fake responses, and request recording are inherited.
 */
class LoggingHttpClientFactory extends Factory
{
    /**
     * Create a request that captures log options while retaining Laravel's global client settings.
     */
    protected function newPendingRequest(): LoggingPendingRequest
    {
        return (new LoggingPendingRequest($this, $this->globalMiddleware))->withOptions(value($this->globalOptions));
    }
}
