<?php

namespace Caixingyue\LaravelStarLog\Tests\Fixtures\Queue;

use Caixingyue\LaravelStarLog\Contracts\ShouldBeCorrelated;
use Caixingyue\LaravelStarLog\Facades\StarLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

final class NestedQueueJob implements ShouldBeCorrelated, ShouldQueue
{
    use Queueable;

    public static array $executions = [];

    public static array $afterNested = [];

    public function __construct(private readonly int $remainingDispatches = 0) {}

    public function handle(): void
    {
        self::$executions[] = StarLog::getCorrelationChain();

        if ($this->remainingDispatches > 0) {
            dispatch(new self($this->remainingDispatches - 1));
            self::$afterNested = StarLog::getCorrelationChain();
        }
    }
}
