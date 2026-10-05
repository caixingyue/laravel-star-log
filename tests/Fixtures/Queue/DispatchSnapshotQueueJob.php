<?php

namespace Caixingyue\LaravelStarLog\Tests\Fixtures\Queue;

use Caixingyue\LaravelStarLog\Contracts\ShouldBeCorrelated;
use Caixingyue\LaravelStarLog\Facades\StarLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class DispatchSnapshotQueueJob implements ShouldBeCorrelated, ShouldQueue
{
    use Queueable;

    public static array $executions = [];

    public function __construct(public string $value = 'snapshot') {}

    public function handle(): void
    {
        self::$executions[] = ['chain' => StarLog::getCorrelationChain(), 'value' => $this->value];
    }
}
