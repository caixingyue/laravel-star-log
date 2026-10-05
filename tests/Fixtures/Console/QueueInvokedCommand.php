<?php

namespace Caixingyue\LaravelStarLog\Tests\Fixtures\Console;

use Caixingyue\LaravelStarLog\Concerns\InteractsWithCorrelation;
use Caixingyue\LaravelStarLog\Contracts\ShouldBeCorrelated;
use Illuminate\Console\Command;

final class QueueInvokedCommand extends Command implements ShouldBeCorrelated
{
    use InteractsWithCorrelation;

    protected $signature = 'star-log:queue-invoked-command';

    public static array $executions = [];

    public function handle(): int
    {
        self::$executions[] = [
            'id' => $this->getId(),
            'queueId' => $this->getQueueId(),
            'nearestQueueId' => $this->getNearestQueueId(),
            'parent' => $this->getParentCorrelation(),
            'chain' => $this->getCorrelationChain(),
        ];

        return self::SUCCESS;
    }
}
