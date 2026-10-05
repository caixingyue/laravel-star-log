<?php

namespace Caixingyue\LaravelStarLog\Http\Client;

/**
 * Keep one logical HTTP request's log options unchanged across async completion and retries.
 * This object is a Guzzle option and is never added to transport headers or body data.
 */
final readonly class HttpClientLogSnapshot
{
    public const OPTION = '_starlog_log_snapshot';

    /**
     * Store the request logging configuration.
     */
    public function __construct(public array $config) {}
}
