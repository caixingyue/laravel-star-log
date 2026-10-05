<?php

namespace Caixingyue\LaravelStarLog\Tests\Fixtures\Console;

use Caixingyue\LaravelStarLog\Concerns\InteractsWithCorrelation;
use Caixingyue\LaravelStarLog\Contracts\ShouldBeCorrelated;
use Illuminate\Console\Command;

final class ChildCorrelatedCommand extends Command implements ShouldBeCorrelated
{
    use InteractsWithCorrelation;

    protected $signature = 'star-log:child-correlation-test';

    public static array $executions = [];

    public static bool $dispatchQueue = false;

    public function handle(): int
    {
        self::$executions[] = [
            'id' => $this->getId(),
            'artisanId' => $this->getArtisanId(),
            'nearestArtisanId' => $this->getNearestArtisanId(),
            'chain' => $this->getCorrelationChain(),
        ];

        if (self::$dispatchQueue) {
            dispatch(new CommandDispatchedQueueJob);
        }

        return self::SUCCESS;
    }
}
