<?php

namespace Caixingyue\LaravelStarLog\Tests\Fixtures\Queue;

use Caixingyue\LaravelStarLog\Facades\StarLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

final class UncorrelatedQueueJob implements ShouldQueue
{
    use Queueable;

    public static array $executions = [];

    public function handle(): void
    {
        self::$executions[] = StarLog::getCorrelationChain();

        dispatch(new NestedQueueJob);

        self::$executions[] = StarLog::getCorrelationChain();
    }
}
