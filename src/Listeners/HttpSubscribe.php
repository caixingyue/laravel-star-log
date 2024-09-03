<?php

namespace Caixingyue\LaravelStarLog\Listeners;

use Caixingyue\LaravelStarLog\Listeners\Http\RequestSendingToLog;
use Caixingyue\LaravelStarLog\Listeners\Http\ResponseReceivedToLog;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;

/**
 * HTTP Client Subscriber
 */
class HttpSubscribe
{
    /**
     * Register the listeners for the subscriber.
     *
     * @return array
     */
    public function subscribe(): array
    {
        return [
            // HTTP Client Request
            RequestSending::class => [
                // Record client request logs
                RequestSendingToLog::class
            ],

            // HTTP Client Response
            ResponseReceived::class => [
                // Record client response log
                ResponseReceivedToLog::class
            ]
        ];
    }
}
